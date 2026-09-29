<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\CourierPaymentMovement;
use App\Models\Provider;
use App\Models\RutaCv;
use App\Models\RutaCvFrequency;
use App\Models\Tenant;
use App\Modules\ProviderPayments\Services\MonthlyPaymentClosingService;
use App\Modules\ProviderPayments\Services\ProcessDeletionAuthorizer;
use App\Modules\ProviderPayments\Services\RutaCvClosingService;
use App\Modules\ProviderPayments\Services\RutaCvManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class RutaCvController
{
    public function index(Request $request): View
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $periods = RutaCv::query()->where('tenant_id', $tenant->id)
            ->selectRaw('periodo, COUNT(*) as rutas, SUM(total_mensual) as total')
            ->groupBy('periodo')->orderByDesc('periodo')->get();
        $selectedPeriod = (string) $request->query('periodo', $periods->first()?->periodo ?? '');
        if ($selectedPeriod !== '' && ! $periods->contains('periodo', $selectedPeriod)) {
            $selectedPeriod = $periods->first()?->periodo ?? '';
        }
        $routes = RutaCv::query()->where('tenant_id', $tenant->id)
            ->when($selectedPeriod !== '', fn ($query) => $query->where('periodo', $selectedPeriod))
            ->orderByRaw('CASE WHEN provider_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('zona')->orderBy('id')->get();
        $providers = Provider::query()->where('tenant_id', $tenant->id)->orderBy('legal_name')->get();
        $frequencies = RutaCvFrequency::query()->where('tenant_id', $tenant->id)->orderBy('name')->get();
        $unmatched = $routes->whereNull('provider_id')->count();
        $total = $routes->sum('total_mensual');
        $monthClosed = $selectedPeriod !== '' && DB::table('Cierres_Pagos')->where('tenant_id', $tenant->id)->where('periodo', $selectedPeriod)->exists();
        $isClosed = $monthClosed || ($routes->isNotEmpty() && $routes->every(fn (RutaCv $route): bool => $route->closed_at !== null));
        $daysInMonth = 31;
        $firstWeekdayOffset = 0;
        $dayLabels = [];
        if ($selectedPeriod !== '') {
            $month = CarbonImmutable::create((int) substr($selectedPeriod, 0, 4), (int) substr($selectedPeriod, 4, 2), 1);
            $daysInMonth = $month->daysInMonth;
            $firstWeekdayOffset = $month->dayOfWeekIso - 1;
            $shortNames = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
            $fullNames = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $date = $month->setDay($day);
                $dayLabels[$day] = [
                    'short' => $shortNames[$date->dayOfWeekIso].' '.$date->format('d/m'),
                    'date' => $date->format('d/m'),
                    'full' => $fullNames[$date->dayOfWeekIso].' '.$date->format('d/m/Y'),
                ];
            }
        }

        return view('provider-payments::rutas-cv', compact('periods', 'selectedPeriod', 'routes', 'providers', 'frequencies', 'unmatched', 'total', 'isClosed', 'monthClosed', 'daysInMonth', 'firstWeekdayOffset', 'dayLabels'));
    }

    public function import(Request $request, RutaCvManager $manager): RedirectResponse
    {
        $validated = $request->validate(['file' => ['required', 'file', 'extensions:xlsx', 'max:20480']]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        try {
            $result = $manager->import($validated['file']->getRealPath(), $tenant->id);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'No se pudo leer la planilla Excel. Revisa el archivo Ruta CV.']);
        }

        return redirect()->route('provider-payments.courier-movements.rutas-cv', ['periodo' => $result['periodo']])
            ->with('status', "{$result['imported']} rutas cargadas para {$result['periodo']}. {$result['unmatched']} facturadores pendientes de asociar.");
    }

    public function generate(Request $request, RutaCvManager $manager): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $validated = $request->validate([
            'periodo_month' => ['required', 'date_format:Y-m'],
            'exclude_route_ids' => ['sometimes', 'array'],
            'exclude_route_ids.*' => ['integer', 'distinct', Rule::exists('Rutas_CV', 'id')->where('tenant_id', $tenant->id)],
        ]);
        $period = str_replace('-', '', $validated['periodo_month']);
        $result = $manager->generate($tenant->id, $period, array_map('intval', $validated['exclude_route_ids'] ?? []));

        return redirect()->route('provider-payments.courier-movements.rutas-cv', ['periodo' => $period])
            ->with('status', "{$result['created']} rutas generadas desde {$result['source']} con los días del nuevo mes marcados según su frecuencia. Se excluyeron {$result['excluded']} rutas de la copia. Puedes corregirlas o eliminarlas antes del cierre.");
    }

    public function sourceRoutes(Request $request): JsonResponse
    {
        $validated = $request->validate(['periodo_month' => ['required', 'date_format:Y-m']]);
        $period = str_replace('-', '', $validated['periodo_month']);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $sourcePeriod = RutaCv::query()->where('tenant_id', $tenant->id)->where('periodo', '<', $period)
            ->orderByDesc('periodo')->value('periodo');
        $routes = $sourcePeriod === null ? collect() : RutaCv::query()
            ->where('tenant_id', $tenant->id)->where('periodo', $sourcePeriod)
            ->orderBy('facturador')->orderBy('detalle_ruta')->get(['id', 'nombre_pila_proveedor', 'facturador', 'detalle_ruta', 'frecuencia']);

        return response()->json([
            'source_period' => $sourcePeriod,
            'routes' => $routes->map(fn (RutaCv $route): array => [
                'id' => $route->id,
                'provider' => $route->nombre_pila_proveedor ?: $route->facturador,
                'detail' => $route->detalle_ruta,
                'frequency' => $route->frecuencia,
            ])->values(),
        ]);
    }

    public function update(Request $request, RutaCvManager $manager): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $period = (string) $request->input('periodo');
        $deleteRouteId = $request->input('delete_route_id');
        if ($deleteRouteId !== null && is_array($request->input('rows'))) {
            $request->merge(['rows' => array_filter(
                $request->input('rows'),
                fn ($row): bool => is_array($row) && (string) ($row['id'] ?? '') !== (string) $deleteRouteId
            )]);
        }
        $validated = $request->validate([
            'periodo' => ['required', 'date_format:Ym'],
            'delete_route_id' => ['nullable', 'integer', Rule::exists('Rutas_CV', 'id')->where('tenant_id', $tenant->id)->where('periodo', $period)],
            'rows' => $deleteRouteId === null ? ['required', 'array', 'min:1'] : ['present', 'array'],
            'rows.*.id' => ['required', 'integer', 'distinct', Rule::exists('Rutas_CV', 'id')->where('tenant_id', $tenant->id)->where('periodo', $period)],
            'rows.*.provider_id' => ['nullable', 'integer', Rule::exists('providers', 'id')->where('tenant_id', $tenant->id)],
            'rows.*.zona' => ['required', 'string', 'max:40'],
            'rows.*.frecuencia' => ['required', 'string', Rule::exists('ruta_cv_frequencies', 'name')->where('tenant_id', $tenant->id)],
            'rows.*.facturador' => ['required', 'string', 'max:255'],
            'rows.*.usuario' => ['required', 'string', 'max:255'],
            'rows.*.detalle_ruta' => ['required', 'string', 'max:255'],
            'rows.*.comuna' => ['required', 'string', 'max:160'],
            'rows.*.producto' => ['nullable', 'string', 'max:255'],
            'rows.*.agente' => ['nullable', 'string', 'max:160'],
            'rows.*.valor' => ['required', 'integer', 'min:0'],
            'rows.*.tipo_cobro' => ['required', Rule::in(['diario', 'fijo'])],
            'rows.*.monto_fijo' => ['nullable', 'integer', 'min:0'],
            'rows.*.inasistencia' => ['required', 'integer', 'between:0,31'],
            'rows.*.dias' => ['sometimes', 'array'],
            'rows.*.dias.*' => ['integer', 'between:1,31'],
            'rows.*.observacion' => ['nullable', 'string', 'max:2000'],
        ]);

        $rows = array_values($validated['rows']);
        $deleteRouteId = isset($validated['delete_route_id']) ? (int) $validated['delete_route_id'] : null;
        $providerIds = collect($rows)->pluck('provider_id')->filter()->unique();
        $providers = Provider::query()->where('tenant_id', $tenant->id)->whereIn('id', $providerIds)->get()->keyBy('id');
        DB::transaction(function () use ($tenant, $period, $rows, $providers, $manager, $deleteRouteId): void {
            MonthlyPaymentClosingService::assertOpen($tenant->id, $period);
            $routeToDelete = $deleteRouteId === null ? null : RutaCv::query()
                ->where('tenant_id', $tenant->id)->where('periodo', $period)
                ->lockForUpdate()->findOrFail($deleteRouteId);
            if ($routeToDelete !== null && ($routeToDelete->closed_at !== null || CourierPaymentMovement::query()
                ->where('tenant_id', $tenant->id)->where('ruta_cv_id', $routeToDelete->id)->exists())) {
                throw ValidationException::withMessages(['periodo' => 'Esta ruta ya tiene pagos cerrados. Reabre el proceso con la clave maestra antes de eliminarla.']);
            }
            $records = RutaCv::query()->where('tenant_id', $tenant->id)->where('periodo', $period)
                ->whereIn('id', array_column($rows, 'id'))->lockForUpdate()->get()->keyBy('id');
            if ($records->contains(fn (RutaCv $route): bool => $route->closed_at !== null)) {
                throw ValidationException::withMessages(['periodo' => 'El período está cerrado. Reábrelo con la clave maestra antes de modificarlo.']);
            }
            if ($records->count() !== count($rows)) {
                throw ValidationException::withMessages(['rows' => 'Algunas rutas ya no pertenecen a este período. No se guardó ninguna.']);
            }
            foreach ($rows as $row) {
                $days = array_map('intval', $row['dias'] ?? []);
                if (count($days) !== count(array_unique($days))) {
                    throw ValidationException::withMessages(['rows' => 'Una ruta tiene el mismo día marcado más de una vez. No se guardó ninguna ruta.']);
                }
                sort($days);
                if ((int) $row['inasistencia'] > count($days) || ($row['tipo_cobro'] === 'fijo' && ! isset($row['monto_fijo']))) {
                    throw ValidationException::withMessages(['rows' => 'Revisa las inasistencias y el monto fijo. No se guardó ninguna ruta.']);
                }
                $provider = $providers->get($row['provider_id'] ?? null);
                $records[$row['id']]->update([
                    'zona' => trim($row['zona']), 'frecuencia' => trim($row['frecuencia']),
                    'facturador' => trim($row['facturador']), 'usuario' => trim($row['usuario']),
                    'detalle_ruta' => trim($row['detalle_ruta']), 'comuna' => trim($row['comuna']),
                    'producto' => trim($row['producto'] ?? '') ?: null,
                    'agente' => trim($row['agente'] ?? '') ?: null,
                    'valor' => (int) $row['valor'], 'dias' => $days,
                    'inasistencia' => (int) $row['inasistencia'],
                    'tipo_cobro' => $row['tipo_cobro'],
                    'monto_fijo' => $row['tipo_cobro'] === 'fijo' ? (int) $row['monto_fijo'] : null,
                    'total_mensual' => $manager->total($days, (int) $row['inasistencia'], (int) $row['valor'], $row['tipo_cobro'], $row['monto_fijo'] ?? null),
                    'observacion' => trim($row['observacion'] ?? '') ?: null,
                ] + $manager->providerFields($provider));
            }
            $routeToDelete?->delete();
        });

        $status = $deleteRouteId === null ? count($rows).' rutas guardadas.' : match (count($rows)) {
            0 => 'Ruta eliminada.',
            1 => '1 ruta guardada y una ruta eliminada.',
            default => count($rows).' rutas guardadas y una ruta eliminada.',
        };

        return redirect()->route('provider-payments.courier-movements.rutas-cv', ['periodo' => $period])
            ->with('status', $status);
    }

    public function store(Request $request, RutaCvManager $manager): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $validated = $request->validate([
            'periodo' => ['required', 'date_format:Ym'],
            'zona' => ['required', 'string', 'max:40'],
            'frecuencia' => ['required', 'string', Rule::exists('ruta_cv_frequencies', 'name')->where('tenant_id', $tenant->id)],
            'facturador' => ['required', 'string', 'max:255'],
            'usuario' => ['required', 'string', 'max:255'],
            'detalle_ruta' => ['required', 'string', 'max:255'],
            'comuna' => ['required', 'string', 'max:160'],
            'valor' => ['required', 'integer', 'min:0'],
            'provider_id' => ['nullable', 'integer', Rule::exists('providers', 'id')->where('tenant_id', $tenant->id)],
            'tipo_cobro' => ['required', Rule::in(['diario', 'fijo'])],
            'monto_fijo' => ['nullable', 'integer', 'min:0'],
        ]);
        if ($validated['tipo_cobro'] === 'fijo' && ! isset($validated['monto_fijo'])) {
            throw ValidationException::withMessages(['monto_fijo' => 'Ingresa el monto mensual fijo.']);
        }
        $provider = isset($validated['provider_id']) ? Provider::query()->where('tenant_id', $tenant->id)->find($validated['provider_id']) : null;
        $frequency = RutaCvFrequency::query()->where('tenant_id', $tenant->id)->where('name', $validated['frecuencia'])->firstOrFail();
        $days = $manager->suggestDays($validated['periodo'], $frequency->weekdays ?: $manager->defaultWeekdays($frequency->name));
        DB::transaction(function () use ($tenant, $validated, $manager, $provider, $days): void {
            MonthlyPaymentClosingService::assertOpen($tenant->id, $validated['periodo']);
            if (RutaCv::query()->where('tenant_id', $tenant->id)->where('periodo', $validated['periodo'])
                ->lockForUpdate()->whereNotNull('closed_at')->exists()) {
                throw ValidationException::withMessages(['periodo' => 'El período está cerrado. Reábrelo con la clave maestra antes de agregar rutas.']);
            }
            RutaCv::query()->create([
                'tenant_id' => $tenant->id, 'periodo' => $validated['periodo'], 'route_key' => (string) Str::uuid(),
                'zona' => trim($validated['zona']), 'frecuencia' => trim($validated['frecuencia']),
                'facturador' => trim($validated['facturador']), 'usuario' => trim($validated['usuario']),
                'detalle_ruta' => trim($validated['detalle_ruta']), 'comuna' => trim($validated['comuna']),
                'producto' => 'Ruta de Cruz Verde', 'valor' => (int) $validated['valor'],
                'dias' => $days, 'tipo_cobro' => $validated['tipo_cobro'],
                'monto_fijo' => $validated['tipo_cobro'] === 'fijo' ? (int) $validated['monto_fijo'] : null,
                'total_mensual' => $manager->total($days, 0, (int) $validated['valor'], $validated['tipo_cobro'], $validated['monto_fijo'] ?? null),
                'origen' => 'manual',
            ] + $manager->providerFields($provider));
        });

        return redirect()->route('provider-payments.courier-movements.rutas-cv', ['periodo' => $validated['periodo']])
            ->with('status', $days === []
                ? 'Ruta agregada. Esta frecuencia no tiene días definidos: márcalos en el calendario y guarda los cambios.'
                : 'Ruta agregada con los días del período marcados según su frecuencia. Revisa el calendario antes de cerrar.');
    }

    public function storeFrequency(Request $request): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'weekdays' => ['sometimes', 'array'],
            'weekdays.*' => ['integer', 'distinct', 'between:1,7'],
            'periodo' => ['nullable', 'date_format:Ym'],
        ]);
        $name = trim($validated['name']);
        $key = Str::slug($name);
        if ($key === '' || RutaCvFrequency::query()->where('tenant_id', $tenant->id)->where('name_key', $key)->exists()) {
            throw ValidationException::withMessages(['name' => 'Esta frecuencia ya existe o el nombre no es válido.']);
        }
        $weekdays = array_values(array_unique(array_map('intval', $validated['weekdays'] ?? [])));
        sort($weekdays);
        RutaCvFrequency::query()->create([
            'tenant_id' => $tenant->id, 'name' => $name, 'name_key' => $key, 'weekdays' => $weekdays,
        ]);

        return redirect()->route('provider-payments.courier-movements.rutas-cv', array_filter(['periodo' => $validated['periodo'] ?? null]))
            ->with('status', "Frecuencia {$name} agregada. Ya puedes seleccionarla en las rutas.");
    }

    public function close(Request $request, RutaCvClosingService $closing): RedirectResponse
    {
        $period = $request->validate(['periodo' => ['required', 'date_format:Ym']])['periodo'];
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $count = $closing->close($tenant->id, $period);

        return redirect()->route('provider-payments.courier-movements.rutas-cv', ['periodo' => $period])
            ->with('status', "Período {$period} cerrado. {$count} pagos de Ruta CV grabados y edición bloqueada.");
    }

    public function reopen(Request $request, ProcessDeletionAuthorizer $authorizer, RutaCvClosingService $closing): RedirectResponse
    {
        $period = $request->validate(['periodo' => ['required', 'date_format:Ym']])['periodo'];
        $authorizer->authorize($request);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $count = $closing->reopen($tenant->id, $period);

        return redirect()->route('provider-payments.courier-movements.rutas-cv', ['periodo' => $period])
            ->with('status', "Período {$period} reabierto. {$count} pagos de Ruta CV retirados; ya puedes corregir y volver a cerrar.");
    }
}
