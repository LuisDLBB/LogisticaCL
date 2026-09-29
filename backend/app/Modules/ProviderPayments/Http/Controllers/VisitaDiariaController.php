<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\Client;
use App\Models\Provider;
use App\Models\Tenant;
use App\Models\VisitaDiaria;
use App\Modules\ProviderPayments\Services\MonthlyPaymentClosingService;
use App\Modules\ProviderPayments\Services\ProcessDeletionAuthorizer;
use App\Modules\ProviderPayments\Services\ProviderZone;
use App\Modules\ProviderPayments\Services\VisitaDiariaClosingService;
use App\Modules\ProviderPayments\Services\VisitaDiariaManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class VisitaDiariaController
{
    public function index(Request $request): View
    {
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $periods = VisitaDiaria::query()->where('tenant_id', $tenantId)->distinct()->orderByDesc('periodo')->pluck('periodo');
        $period = (string) $request->query('periodo', $periods->first() ?? '');
        if ($period !== '' && ! $periods->contains($period)) {
            $period = $periods->first() ?? '';
        }
        $base = VisitaDiaria::query()->where('tenant_id', $tenantId)->where('periodo', $period);
        $providerFilter = $request->integer('proveedor');
        $clientFilter = $request->integer('cliente');
        $localFilter = trim((string) $request->query('local', ''));
        $search = trim((string) $request->query('q', ''));
        $filters = (clone $base)->with(['provider', 'client'])
            ->when($providerFilter > 0, fn ($query) => $query->where('provider_id', $providerFilter))
            ->when($clientFilter > 0, fn ($query) => $query->where('client_id', $clientFilter))
            ->when($localFilter !== '', fn ($query) => $query->where('local', $localFilter));
        foreach (array_slice(preg_split('/\s+/u', $search) ?: [], 0, 8) as $term) {
            if ($term === '') {
                continue;
            }
            $filters->where(function ($query) use ($term): void {
                foreach (['local', 'nombre_local', 'direccion', 'comuna', 'frecuencia', 'agente_original',
                    'rut_proveedor_origen', 'razon_social_proveedor_origen', 'nombre_pila_proveedor_origen',
                    'rut_cliente_origen', 'razon_social_cliente_origen', 'comerciante_pila_origen', 'estatus_origen'] as $column) {
                    $query->orWhere($column, 'like', '%'.$term.'%');
                }
                $query->orWhereHas('provider', fn ($provider) => $provider->where('legal_name', 'like', '%'.$term.'%')
                    ->orWhere('operational_name', 'like', '%'.$term.'%')->orWhere('tax_id', 'like', '%'.$term.'%'));
                $query->orWhereHas('client', fn ($client) => $client->where('legal_name', 'like', '%'.$term.'%')
                    ->orWhere('commercial_name', 'like', '%'.$term.'%')->orWhere('tax_id', 'like', '%'.$term.'%'));
            });
        }
        $rows = $filters->orderByRaw("CASE WHEN provider_id IS NULL OR client_id IS NULL OR TRIM(direccion) = '' THEN 0 WHEN valor_dia >= 10000 THEN 1 ELSE 2 END")
            ->orderBy('local')->orderBy('nombre_local')->paginate(25)->withQueryString();
        $summary = (clone $base)->selectRaw('COUNT(*) AS cantidad, SUM(total_mensual) AS total,
            SUM(CASE WHEN provider_id IS NULL OR client_id IS NULL OR TRIM(direccion) = \'\' THEN 1 ELSE 0 END) AS pendientes,
            SUM(CASE WHEN valor_dia >= 10000 THEN 1 ELSE 0 END) AS atipicos,
            SUM(CASE WHEN closed_at IS NOT NULL THEN 1 ELSE 0 END) AS cerrados')->first();
        $filteredTotal = (clone $filters)->sum('total_mensual');
        $providers = Provider::query()->where('tenant_id', $tenantId)->orderBy('operational_name')->get();
        $clients = Client::query()->where('tenant_id', $tenantId)->orderBy('commercial_name')->get();
        $locals = (clone $base)->distinct()->orderBy('local')->pluck('local');
        $frequencies = (clone $base)->distinct()->orderBy('frecuencia')->pluck('frecuencia');
        $daysInMonth = 31;
        $firstWeekdayOffset = 0;
        $dayLabels = [];
        if ($period !== '') {
            $month = CarbonImmutable::create((int) substr($period, 0, 4), (int) substr($period, 4, 2), 1);
            $daysInMonth = $month->daysInMonth;
            $firstWeekdayOffset = $month->dayOfWeekIso - 1;
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $date = $month->setDay($day);
                $dayLabels[$day] = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'][$date->dayOfWeekIso].' '.$date->format('d/m');
            }
        }
        $monthClosed = $period !== '' && DB::table('Cierres_Pagos')->where('tenant_id', $tenantId)->where('periodo', $period)->exists();
        $isClosed = $monthClosed || ((int) ($summary->cantidad ?? 0) > 0 && (int) $summary->cantidad === (int) $summary->cerrados);

        return view('provider-payments::visitas-diarias', compact('periods', 'period', 'rows', 'summary', 'filteredTotal',
            'providers', 'clients', 'locals', 'frequencies', 'providerFilter', 'clientFilter', 'localFilter', 'search',
            'daysInMonth', 'firstWeekdayOffset', 'dayLabels', 'isClosed', 'monthClosed'));
    }

    public function import(Request $request, VisitaDiariaManager $manager): RedirectResponse
    {
        $data = $request->validate(['periodo_month' => ['required', 'date_format:Y-m'],
            'file' => ['required', 'file', 'extensions:xlsx,csv', 'max:20480']]);
        $period = str_replace('-', '', $data['periodo_month']);
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        try {
            $result = $manager->import($data['file']->getRealPath(), $tenantId, $period);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'No se pudo leer la planilla. Revisa el formato Base_Visitas.']);
        }

        return redirect()->route('provider-payments.courier-movements.visitas', ['periodo' => $period])
            ->with('status', "{$result['imported']} visitas cargadas. {$result['pending']} filas requieren revisar cliente, proveedor o dirección.");
    }

    public function generate(Request $request, VisitaDiariaManager $manager): RedirectResponse
    {
        $period = str_replace('-', '', $request->validate(['periodo_month' => ['required', 'date_format:Y-m']])['periodo_month']);
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $count = $manager->generate($tenantId, $period);

        return redirect()->route('provider-payments.courier-movements.visitas', ['periodo' => $period])
            ->with('status', "{$count} visitas generadas desde el mes anterior. Revisa los días y montos antes de cerrar.");
    }

    public function update(Request $request, VisitaDiariaManager $manager): RedirectResponse
    {
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $period = (string) $request->input('periodo');
        $data = $request->validate([
            'periodo' => ['required', 'date_format:Ym'], 'rows' => ['required', 'array', 'min:1'],
            'rows.*.id' => ['required', 'integer', 'distinct', Rule::exists('Visitas_Diarias', 'id')->where('tenant_id', $tenantId)->where('periodo', $period)],
            'rows.*.provider_id' => ['nullable', 'integer', Rule::exists('providers', 'id')->where('tenant_id', $tenantId)],
            'rows.*.client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('tenant_id', $tenantId)],
            'rows.*.agente_original' => ['nullable', 'string', 'max:255'],
            'rows.*.local' => ['required', 'string', 'max:100'], 'rows.*.nombre_local' => ['required', 'string', 'max:255'],
            'rows.*.direccion' => ['required', 'string', 'max:255'], 'rows.*.comuna' => ['required', 'string', 'max:160'],
            'rows.*.frecuencia' => ['required', 'string', 'max:80'], 'rows.*.zona' => ['nullable', 'in:RM,Regiones'],
            'rows.*.valor_dia' => ['required', 'integer', 'min:0'],
            'rows.*.dias' => ['sometimes', 'array'], 'rows.*.dias.*' => ['integer', 'between:1,31'],
        ]);
        $manager->assertPeriod($period);
        $rows = array_values($data['rows']);
        $daysInMonth = CarbonImmutable::create((int) substr($period, 0, 4), (int) substr($period, 4, 2), 1)->daysInMonth;
        DB::transaction(function () use ($rows, $period, $tenantId, $manager, $daysInMonth): void {
            MonthlyPaymentClosingService::assertOpen($tenantId, $period);
            $periodRows = VisitaDiaria::query()->where('tenant_id', $tenantId)->where('periodo', $period)->lockForUpdate()->get();
            if ($periodRows->contains(fn (VisitaDiaria $row): bool => $row->closed_at !== null)) {
                throw ValidationException::withMessages(['periodo' => 'El período está cerrado. Reábrelo con clave maestra para editar.']);
            }
            $records = $periodRows->keyBy('id');
            foreach ($rows as $row) {
                $record = $records->get((int) $row['id']);
                if ($record === null) {
                    throw ValidationException::withMessages(['rows' => 'Una visita ya no pertenece a este período.']);
                }
                $days = array_map('intval', $row['dias'] ?? []);
                if (count($days) !== count(array_unique($days)) || collect($days)->contains(fn (int $day): bool => $day > $daysInMonth)) {
                    throw ValidationException::withMessages(['rows' => "La visita {$record->id} tiene días duplicados o fuera del mes."]);
                }
                sort($days);
                $record->update([
                    'provider_id' => $row['provider_id'] ?? null, 'client_id' => $row['client_id'] ?? null,
                    'agente_original' => trim($row['agente_original'] ?? '') ?: null,
                    'local' => trim($row['local']), 'nombre_local' => trim($row['nombre_local']),
                    'direccion' => trim($row['direccion']), 'comuna' => trim($row['comuna']),
                    'frecuencia' => trim($row['frecuencia']), 'zona' => $row['zona'] ?? null,
                    'valor_dia' => (int) $row['valor_dia'], 'dias' => $days,
                    'total_mensual' => $manager->total($days, (int) $row['valor_dia']),
                ]);
            }
        });

        return redirect()->route('provider-payments.courier-movements.visitas', ['periodo' => $period, 'page' => $request->input('page', 1)])
            ->with('status', count($rows).' visitas guardadas.');
    }

    public function store(Request $request, VisitaDiariaManager $manager): RedirectResponse
    {
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $data = $request->validate([
            'periodo' => ['required', 'date_format:Ym'],
            'provider_id' => ['required', 'integer', Rule::exists('providers', 'id')->where('tenant_id', $tenantId)],
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('tenant_id', $tenantId)],
            'agente_original' => ['nullable', 'string', 'max:255'], 'local' => ['required', 'string', 'max:100'],
            'nombre_local' => ['required', 'string', 'max:255'], 'direccion' => ['required', 'string', 'max:255'],
            'comuna' => ['required', 'string', 'max:160'], 'frecuencia' => ['required', 'string', 'max:80'],
            'valor_dia' => ['required', 'integer', 'min:0'], 'zona' => ['nullable', 'in:RM,Regiones'],
        ]);
        $manager->assertPeriod($data['periodo']);
        $provider = Provider::query()->where('tenant_id', $tenantId)->findOrFail($data['provider_id']);
        $client = Client::query()->where('tenant_id', $tenantId)->findOrFail($data['client_id']);
        DB::transaction(function () use ($data, $tenantId, $provider, $client, $manager): void {
            MonthlyPaymentClosingService::assertOpen($tenantId, $data['periodo']);
            if (VisitaDiaria::query()->where('tenant_id', $tenantId)->where('periodo', $data['periodo'])
                ->lockForUpdate()->whereNotNull('closed_at')->exists()) {
                throw ValidationException::withMessages(['periodo' => 'El período está cerrado.']);
            }
            $days = $manager->suggestDays($data['periodo'], $data['frecuencia']);
            VisitaDiaria::query()->create([
                'tenant_id' => $tenantId, 'periodo' => $data['periodo'],
                'nombre_proceso' => $data['periodo'].'-Visitas', 'visit_key' => (string) Str::uuid(),
                'agente_original' => trim($data['agente_original'] ?? '') ?: null,
                'local' => trim($data['local']), 'nombre_local' => trim($data['nombre_local']),
                'direccion' => trim($data['direccion']), 'comuna' => trim($data['comuna']),
                'frecuencia' => trim($data['frecuencia']), 'provider_id' => $provider->id,
                'rut_proveedor_origen' => $provider->tax_id,
                'razon_social_proveedor_origen' => $provider->legal_name,
                'nombre_pila_proveedor_origen' => $provider->operational_name,
                'client_id' => $client->id, 'rut_cliente_origen' => $client->tax_id,
                'razon_social_cliente_origen' => $client->legal_name,
                'comerciante_pila_origen' => $client->source_merchant_name ?: $client->commercial_name,
                'zona' => ProviderZone::resolve($provider->tax_id, $provider->id, $data['zona'] ?? $provider->operator_type),
                'estatus_origen' => 'Activo', 'valor_dia' => (int) $data['valor_dia'],
                'dias' => $days, 'total_mensual' => $manager->total($days, (int) $data['valor_dia']), 'origen' => 'manual',
            ]);
        });

        return redirect()->route('provider-payments.courier-movements.visitas', ['periodo' => $data['periodo']])
            ->with('status', 'Visita agregada. Revisa sus días antes de cerrar el período.');
    }

    public function close(Request $request, VisitaDiariaClosingService $closing): RedirectResponse
    {
        $period = $request->validate(['periodo' => ['required', 'date_format:Ym']])['periodo'];
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $count = $closing->close($tenantId, $period);

        return redirect()->route('provider-payments.courier-movements.visitas', ['periodo' => $period])
            ->with('status', "Período {$period} cerrado. {$count} pagos de Visitas grabados.");
    }

    public function reopen(Request $request, ProcessDeletionAuthorizer $authorizer, VisitaDiariaClosingService $closing): RedirectResponse
    {
        $period = $request->validate(['periodo' => ['required', 'date_format:Ym']])['periodo'];
        $authorizer->authorize($request);
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $count = $closing->reopen($tenantId, $period);

        return redirect()->route('provider-payments.courier-movements.visitas', ['periodo' => $period])
            ->with('status', "Período {$period} reabierto. {$count} pagos de Visitas retirados.");
    }
}
