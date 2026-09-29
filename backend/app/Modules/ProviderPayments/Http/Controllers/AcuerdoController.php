<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\Acuerdo;
use App\Models\AcuerdoCalendarDay;
use App\Models\AcuerdoServiceRule;
use App\Models\Client;
use App\Models\Coverage;
use App\Models\Provider;
use App\Models\Tenant;
use App\Modules\ProviderPayments\Services\AcuerdoClosingService;
use App\Modules\ProviderPayments\Services\AcuerdoImporter;
use App\Modules\ProviderPayments\Services\AcuerdoManager;
use App\Modules\ProviderPayments\Services\MonthlyPaymentClosingService;
use App\Modules\ProviderPayments\Services\ProcessDeletionAuthorizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AcuerdoController
{
    public function index(Request $request): View
    {
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $periods = Acuerdo::query()->where('tenant_id', $tenantId)
            ->selectRaw('periodo, COUNT(*) AS registros, SUM(total) AS monto')
            ->groupBy('periodo')->orderByDesc('periodo')->get();
        $period = (string) $request->query('periodo', $periods->first()?->periodo ?? '');
        if (! $periods->contains('periodo', $period)) {
            $period = $periods->first()?->periodo ?? '';
        }
        $base = Acuerdo::query()->where('tenant_id', $tenantId)
            ->when($period !== '', fn ($query) => $query->where('periodo', $period), fn ($query) => $query->whereRaw('1 = 0'));
        $summary = (clone $base)->selectRaw('COUNT(*) AS registros, COALESCE(SUM(total), 0) AS monto,
            SUM(CASE WHEN provider_id IS NULL THEN 1 ELSE 0 END) AS proveedores_pendientes,
            SUM(CASE WHEN client_id IS NULL THEN 1 ELSE 0 END) AS clientes_pendientes,
            SUM(CASE WHEN empresa_mandante IS NULL OR TRIM(empresa_mandante) = ? THEN 1 ELSE 0 END) AS mandantes_pendientes,
            SUM(CASE WHEN closed_at IS NOT NULL THEN 1 ELSE 0 END) AS cerrados', [''])->first();
        $coverageZones = Coverage::query()->where('tenant_id', $tenantId)->where('is_active', true)
            ->whereNotNull('provider_id')->whereNotNull('zone')->get(['provider_id', 'zone'])
            ->groupBy('provider_id')->map(fn ($coverages) => $coverages->pluck('zone')
            ->map(fn ($zone) => trim((string) $zone))->filter()->unique()->values());
        $providers = Provider::query()->where('tenant_id', $tenantId)->orderBy('operational_name')->get();
        $zoneByProvider = $providers->mapWithKeys(function (Provider $provider) use ($coverageZones): array {
            $zones = $coverageZones->get($provider->id, collect());
            $zone = $zones->count() === 1 ? $zones->first() : ($zones->isEmpty() ? $provider->operator_type : null);

            return [$provider->id => in_array($zone, ['RM', 'Regiones'], true) ? $zone : null];
        });
        $summary->zonas_pendientes = (clone $base)->get(['provider_id', 'zona'])
            ->filter(fn (Acuerdo $row): bool => ! $row->zona && ! $zoneByProvider->get($row->provider_id))->count();
        $monthClosed = $period !== '' && DB::table('Cierres_Pagos')->where('tenant_id', $tenantId)->where('periodo', $period)->exists();
        $isClosed = $monthClosed || ((int) $summary->registros > 0 && (int) $summary->cerrados === (int) $summary->registros);
        $byService = (clone $base)->selectRaw('servicio, COUNT(*) AS registros, SUM(cantidad) AS dias, SUM(total) AS monto')
            ->groupBy('servicio')->orderByDesc('monto')->get();
        $byClient = (clone $base)->selectRaw('COALESCE(comerciante_pila_origen, razon_social_cliente_origen, ?) AS cliente, COUNT(*) AS registros, SUM(total) AS monto', ['Sin cliente'])
            ->groupBy('cliente')->orderByDesc('monto')->get();
        $calendar = AcuerdoCalendarDay::query()->where('tenant_id', $tenantId)->where('periodo', $period)->orderBy('fecha')->get();
        $calendarLocked = $calendar->contains(fn (AcuerdoCalendarDay $day): bool => $day->bloqueado);
        $weekdayCounts = $calendar->where('es_feriado', false)->countBy(fn (AcuerdoCalendarDay $day): int => $day->fecha->dayOfWeekIso);
        $rules = AcuerdoServiceRule::query()->where('tenant_id', $tenantId)->where('periodo', $period)
            ->whereNotIn(DB::raw('LOWER(servicio)'), ['apoyo alza', 'agencia apoyo alza'])
            ->orderBy('servicio')->get();
        $status = in_array($request->query('estado'), ['todos', 'pendientes', 'completos'], true) ? $request->query('estado') : 'todos';
        $service = mb_substr(trim((string) $request->query('servicio', '')), 0, 160);
        $mandante = mb_substr(trim((string) $request->query('mandante', '')), 0, 100);
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $providerId = filter_var($request->query('proveedor'), FILTER_VALIDATE_INT) ?: null;
        $clientId = filter_var($request->query('cliente'), FILTER_VALIDATE_INT) ?: null;
        $providersWithUniqueZone = $zoneByProvider->filter()->keys()->all();
        $rows = (clone $base)
            ->when($status === 'pendientes', fn ($query) => $query->where(fn ($query) => $query
                ->whereNull('provider_id')->orWhereNull('client_id')
                ->orWhereNull('empresa_mandante')->orWhere('empresa_mandante', '')
                ->orWhere(fn ($query) => $query->whereNull('zona')->whereNotIn('provider_id', $providersWithUniqueZone))))
            ->when($status === 'completos', fn ($query) => $query
                ->whereNotNull('provider_id')->whereNotNull('client_id')
                ->whereNotNull('empresa_mandante')->where('empresa_mandante', '<>', '')
                ->where(fn ($query) => $query->whereNotNull('zona')->orWhereIn('provider_id', $providersWithUniqueZone)))
            ->when($service !== '', fn ($query) => $query->where('servicio', $service))
            ->when($mandante !== '', fn ($query) => $query->where('empresa_mandante', $mandante))
            ->when($providerId, fn ($query) => $query->where('provider_id', $providerId))
            ->when($clientId, fn ($query) => $query->where('client_id', $clientId))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                foreach (['proveedor_origen', 'rut_proveedor_origen', 'servicio', 'marca',
                    'comerciante_pila_origen', 'razon_social_cliente_origen', 'rut_cliente_origen'] as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            }))
            ->with(['provider', 'client'])->orderBy('id')->paginate(25)->withQueryString();
        $clients = Client::query()->where('tenant_id', $tenantId)->orderBy('commercial_name')->get();
        $mandantes = (clone $base)->whereNotNull('empresa_mandante')->where('empresa_mandante', '<>', '')
            ->distinct()->orderBy('empresa_mandante')->pluck('empresa_mandante');
        $mandanteOptions = Acuerdo::query()->where('tenant_id', $tenantId)->whereNotNull('empresa_mandante')
            ->where('empresa_mandante', '<>', '')->distinct()->orderBy('empresa_mandante')->pluck('empresa_mandante');

        return view('provider-payments::acuerdos', compact('periods', 'period', 'summary', 'byService', 'byClient',
            'calendar', 'calendarLocked', 'weekdayCounts', 'rules', 'rows', 'providers', 'clients', 'status', 'service', 'search',
            'providerId', 'clientId', 'mandante', 'mandantes', 'mandanteOptions', 'isClosed', 'monthClosed', 'zoneByProvider'));
    }

    public function import(Request $request, AcuerdoImporter $importer): RedirectResponse
    {
        $file = $request->validate(['file' => ['required', 'file', 'extensions:xlsx', 'max:20480']])['file'];
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $result = $importer->import($file->getRealPath(), $file->getClientOriginalName(), $tenantId);

        return redirect()->route('provider-payments.courier-movements.acuerdos', ['periodo' => $result['period']])
            ->with('status', "{$result['imported']} acuerdos cargados; {$result['existing']} ya existían. Total calculado: $ ".number_format($result['total'], 0, ',', '.').". {$result['pending']} requieren revisar cliente o proveedor.");
    }

    public function generate(Request $request, AcuerdoManager $manager): RedirectResponse
    {
        $period = str_replace('-', '', $request->validate(['periodo' => ['required', 'date_format:Y-m']])['periodo']);
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $count = $manager->generate($tenantId, $period);

        return redirect()->route('provider-payments.courier-movements.acuerdos', ['periodo' => $period])
            ->with('status', "{$count} acuerdos generados para {$period}. Revisa feriados, servicios y ajustes del mes.");
    }

    public function calendar(Request $request, AcuerdoManager $manager): RedirectResponse
    {
        $validated = $request->validate(['periodo' => ['required', 'date_format:Ym'], 'feriados' => ['nullable', 'array'],
            'feriados.*' => ['date_format:Y-m-d']]);
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $period = $validated['periodo'];
        DB::transaction(function () use ($tenantId, $period, $validated, $manager): void {
            $this->assertOpen($tenantId, $period);
            $days = AcuerdoCalendarDay::query()->where('tenant_id', $tenantId)->where('periodo', $period)->lockForUpdate()->get();
            abort_if($days->isEmpty(), 404);
            if ($days->contains(fn (AcuerdoCalendarDay $day): bool => $day->bloqueado)) {
                throw ValidationException::withMessages(['feriados' => 'El calendario está bloqueado. Desbloquéalo antes de recalcular.']);
            }
            $holidays = $validated['feriados'] ?? [];
            foreach ($days as $day) {
                $day->update(['es_feriado' => in_array($day->fecha->toDateString(), $holidays, true), 'bloqueado' => true]);
            }
            $manager->recalculate($tenantId, $period);
        });

        return redirect()->route('provider-payments.courier-movements.acuerdos', ['periodo' => $period])
            ->with('status', 'Calendario y montos actualizados. El calendario quedó bloqueado.');
    }

    public function unlockCalendar(Request $request): RedirectResponse
    {
        $period = $request->validate(['periodo' => ['required', 'date_format:Ym']])['periodo'];
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        DB::transaction(function () use ($tenantId, $period): void {
            $this->assertOpen($tenantId, $period);
            $days = AcuerdoCalendarDay::query()->where('tenant_id', $tenantId)->where('periodo', $period);
            abort_unless((clone $days)->exists(), 404);
            $days->update(['bloqueado' => false]);
        });

        return redirect()->route('provider-payments.courier-movements.acuerdos', ['periodo' => $period])
            ->with('status', 'Calendario desbloqueado. Ya puedes modificar feriados y volver a calcular.');
    }

    public function rule(Request $request, AcuerdoManager $manager): RedirectResponse
    {
        $validated = $request->validate(['periodo' => ['required', 'date_format:Ym'], 'servicio' => ['required', 'string', 'max:160'],
            'modo' => ['required', 'in:fijo,dias_semana'], 'dias_semana' => ['nullable', 'array'],
            'dias_semana.*' => ['integer', 'between:1,7'], 'cantidad_fija' => ['nullable', 'integer', 'between:0,366']]);
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $period = $validated['periodo'];
        abort_unless(Acuerdo::query()->where('tenant_id', $tenantId)->where('periodo', $period)->exists(), 404);
        DB::transaction(function () use ($validated, $tenantId, $period, $manager): void {
            $this->assertOpen($tenantId, $period);
            AcuerdoServiceRule::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'periodo' => $period, 'servicio' => $validated['servicio']],
                ['modo' => $validated['modo'],
                    'dias_semana' => $validated['modo'] === 'dias_semana' ? array_values(array_unique(array_map('intval', $validated['dias_semana'] ?? []))) : null,
                    'cantidad_fija' => $validated['modo'] === 'fijo' ? ($validated['cantidad_fija'] ?? 0) : null],
            );
            $manager->recalculate($tenantId, $period);
        });

        return redirect()->route('provider-payments.courier-movements.acuerdos', ['periodo' => $period])
            ->with('status', 'Regla del servicio y montos actualizados.');
    }

    public function updateRows(Request $request, AcuerdoManager $manager): RedirectResponse
    {
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $validated = $request->validate([
            'periodo' => ['required', 'date_format:Ym'], 'rows' => ['required', 'array', 'min:1', 'max:25'],
            'rows.*.provider_id' => ['present', 'nullable', 'integer', Rule::exists('providers', 'id')->where('tenant_id', $tenantId)],
            'rows.*.client_id' => ['present', 'nullable', 'integer', Rule::exists('clients', 'id')->where('tenant_id', $tenantId)],
            'rows.*.empresa_mandante' => ['present', 'nullable', 'string', 'max:100'],
            'rows.*.zona' => ['nullable', 'in:RM,Regiones'],
            'rows.*.inasistencias' => ['required', 'integer', 'between:0,366'],
            'rows.*.adicionales' => ['required', 'integer', 'between:0,366'],
            'rows.*.costo' => ['required', 'integer', 'min:0'], 'rows.*.factor' => ['required', 'integer', 'between:1,1000'],
            'page' => ['nullable', 'integer', 'min:1'], 'estado' => ['nullable', 'in:todos,pendientes,completos'],
            'servicio' => ['nullable', 'string', 'max:160'], 'mandante' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
            'proveedor' => ['nullable', 'integer'], 'cliente' => ['nullable', 'integer'],
        ]);
        $period = $validated['periodo'];
        DB::transaction(function () use ($validated, $tenantId, $period, $manager): void {
            $this->assertOpen($tenantId, $period);
            $rows = Acuerdo::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                ->whereIn('id', array_keys($validated['rows']))->lockForUpdate()->get()->keyBy('id');
            abort_unless($rows->count() === count($validated['rows']), 404);
            foreach ($validated['rows'] as $id => $selection) {
                $rows[$id]->update($selection);
            }
            $manager->recalculate($tenantId, $period);
        });

        return redirect()->route('provider-payments.courier-movements.acuerdos', array_filter([
            'periodo' => $period, 'page' => $validated['page'] ?? null, 'estado' => $validated['estado'] ?? null,
            'servicio' => $validated['servicio'] ?? null, 'mandante' => $validated['mandante'] ?? null,
            'q' => $validated['q'] ?? null,
            'proveedor' => $validated['proveedor'] ?? null, 'cliente' => $validated['cliente'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''))->with('status', count($validated['rows']).' acuerdos guardados y recalculados.');
    }

    public function storeRow(Request $request, AcuerdoManager $manager): RedirectResponse
    {
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $validated = $request->validate([
            'periodo' => ['required', 'date_format:Ym'],
            'provider_id' => ['required', 'integer', Rule::exists('providers', 'id')->where('tenant_id', $tenantId)],
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('tenant_id', $tenantId)],
            'servicio' => ['required', 'string', Rule::exists('acuerdo_service_rules', 'servicio')
                ->where('tenant_id', $tenantId)->where('periodo', $request->input('periodo'))],
            'costo' => ['required', 'integer', 'min:0'], 'factor' => ['required', 'integer', 'between:1,1000'],
            'inasistencias' => ['required', 'integer', 'between:0,366'],
            'adicionales' => ['required', 'integer', 'between:0,366'],
            'agencia' => ['nullable', 'string', 'max:100'], 'tipo_servicio' => ['nullable', 'string', 'max:50'],
            'marca' => ['nullable', 'string', 'max:255'], 'empresa_mandante' => ['nullable', 'string', 'max:100'],
            'zona' => ['nullable', 'in:RM,Regiones'],
        ]);
        $period = $validated['periodo'];
        $provider = Provider::query()->where('tenant_id', $tenantId)->findOrFail($validated['provider_id']);
        $client = Client::query()->where('tenant_id', $tenantId)->findOrFail($validated['client_id']);
        DB::transaction(function () use ($validated, $tenantId, $period, $provider, $client, $manager): void {
            $this->assertOpen($tenantId, $period);
            Acuerdo::query()->create([
                ...$validated, 'tenant_id' => $tenantId, 'nombre_proceso' => $period.'-Acuerdos',
                'proveedor_origen' => $provider->operational_name ?: $provider->legal_name,
                'rut_proveedor_origen' => $provider->tax_id,
                'razon_social_cliente_origen' => $client->legal_name,
                'comerciante_pila_origen' => $client->commercial_name,
                'rut_cliente_origen' => $client->tax_id,
                'nombre_comercial_origen' => $client->commercial_name,
                'dias_calendario' => 0, 'cantidad' => 0, 'total' => 0,
            ]);
            $manager->recalculate($tenantId, $period);
        });

        return redirect()->route('provider-payments.courier-movements.acuerdos', ['periodo' => $period])
            ->with('status', 'Acuerdo agregado y calculado para el período.');
    }

    public function close(Request $request, AcuerdoClosingService $closing): RedirectResponse
    {
        $period = $request->validate(['periodo' => ['required', 'date_format:Ym']])['periodo'];
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $count = $closing->close($tenantId, $period);

        return redirect()->route('provider-payments.courier-movements.acuerdos', ['periodo' => $period])
            ->with('status', "Período {$period} cerrado. {$count} pagos de Acuerdos grabados y edición bloqueada.");
    }

    public function reopen(Request $request, ProcessDeletionAuthorizer $authorizer, AcuerdoClosingService $closing): RedirectResponse
    {
        $period = $request->validate(['periodo' => ['required', 'date_format:Ym']])['periodo'];
        $authorizer->authorize($request);
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $count = $closing->reopen($tenantId, $period);

        return redirect()->route('provider-payments.courier-movements.acuerdos', ['periodo' => $period])
            ->with('status', "Período {$period} reabierto. {$count} pagos de Acuerdos retirados; ya puedes corregir y volver a cerrar.");
    }

    private function assertOpen(int $tenantId, string $period): void
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);
        $agreements = Acuerdo::query()->where('tenant_id', $tenantId)->where('periodo', $period)
            ->lockForUpdate()->get(['id', 'closed_at']);
        if ($agreements->contains(fn (Acuerdo $agreement): bool => $agreement->closed_at !== null)) {
            throw ValidationException::withMessages(['periodo' => 'El período de Acuerdos está cerrado. Reábrelo con la clave maestra antes de modificarlo.']);
        }
    }
}
