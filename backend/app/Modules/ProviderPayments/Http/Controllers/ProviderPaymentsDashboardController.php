<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\ApoyoAlza;
use App\Models\Client;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\Coverage;
use App\Models\Tenant;
use App\Modules\ProviderPayments\Services\AcuerdoClosingService;
use App\Modules\ProviderPayments\Services\ApoyoAlzaClosingService;
use App\Modules\ProviderPayments\Services\BaseServicioClosingService;
use App\Modules\ProviderPayments\Services\CourierPaymentSummary;
use App\Modules\ProviderPayments\Services\CourierSpecialPaymentRollback;
use App\Modules\ProviderPayments\Services\MonthlyPaymentClosingService;
use App\Modules\ProviderPayments\Services\ProcessDeletionAuthorizer;
use App\Modules\ProviderPayments\Services\RutaCvClosingService;
use App\Modules\ProviderPayments\Services\VisitaDiariaClosingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProviderPaymentsDashboardController
{
    public function __invoke(Request $request, CourierPaymentSummary $paymentSummary): View
    {
        $tenant = Tenant::query()->where('code', '4N')->first();
        $periods = $tenant ? CourierMovement::query()->where('tenant_id', $tenant->id)->whereNotNull('nombre_proceso')
            ->select('nombre_proceso')
            ->where('nombre_proceso', 'like', '______-%')
            ->distinct()->orderByDesc('nombre_proceso')->pluck('nombre_proceso')
            ->map(fn (string $process): string => substr($process, 0, 6))->unique()->values()->all() : [];
        $latestClosedPeriod = $tenant ? DB::table('PPR_Cierres_Pagos')->where('tenant_id', $tenant->id)->max('periodo') : null;
        if ($latestClosedPeriod !== null && ! in_array($latestClosedPeriod, $periods, true)) {
            $periods[] = $latestClosedPeriod;
            rsort($periods);
        }
        $selectedPeriod = (string) $request->query('period', $periods[0] ?? '');
        if (! in_array($selectedPeriod, $periods, true)) {
            $selectedPeriod = $periods[0] ?? '';
        }
        $movements = CourierMovement::query()
            ->when($tenant, fn ($query) => $query->where('tenant_id', $tenant->id), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($selectedPeriod !== '', fn ($query) => $query->where('nombre_proceso', '>=', $selectedPeriod.'-')->where('nombre_proceso', '<', $selectedPeriod.'.'), fn ($query) => $query->whereRaw('1 = 0'));

        $merchantCounts = (clone $movements)
            ->selectRaw('merchant_name, count(*) as total')
            ->groupBy('merchant_name')
            ->orderByDesc('total')
            ->get();

        $statusCounts = (clone $movements)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->orderByDesc('total')
            ->get();

        $sourceProcessCounts = (clone $movements)
            ->whereNotNull('nombre_proceso')
            ->selectRaw('SUBSTR(nombre_proceso, 8) AS service_name, count(*) AS total')
            ->groupByRaw('SUBSTR(nombre_proceso, 8)')
            ->orderByDesc('total')
            ->get();
        $serviceCounts = CourierPaymentMovement::query()
            ->when($tenant, fn ($query) => $query->where('tenant_id', $tenant->id), fn ($query) => $query->whereRaw('1 = 0'))
            ->where('periodo', $selectedPeriod)
            ->selectRaw("nombre_proceso, COUNT(*) AS total, SUM(CASE WHEN condicion_pago = 'SI' THEN COALESCE(valor, 0) ELSE 0 END) AS amount")
            ->groupBy('nombre_proceso')->orderByDesc('total')->get()
            ->each(function (CourierPaymentMovement $process) use ($selectedPeriod): void {
                $process->service_name = str_starts_with($process->nombre_proceso, $selectedPeriod.'-')
                    ? substr($process->nombre_proceso, 7) : $process->nombre_proceso;
            });
        $paymentCountsByProcess = $serviceCounts->groupBy('service_name')
            ->map(fn ($processes): int => (int) $processes->sum('total'));
        $closure = $tenant ? DB::table('PPR_Cierres_Pagos')->where('tenant_id', $tenant->id)->where('periodo', $selectedPeriod)->first() : null;
        $monthClosed = $closure !== null;
        $pendingProcessCounts = $tenant && ! $monthClosed ? (clone $movements)
            ->whereNotIn('id', CourierPaymentMovement::query()->where('tenant_id', $tenant->id)
                ->whereNotNull('courier_movement_id')->select('courier_movement_id'))
            ->selectRaw('SUBSTR(nombre_proceso, 8) AS service_name, count(*) AS total')
            ->groupByRaw('SUBSTR(nombre_proceso, 8)')
            ->orderByDesc('total')->get() : collect();
        $processChecklist = collect([
            'Variable' => 'Variables',
            'Lanas' => 'Lanas',
            'Retornos' => 'Retornos',
            'Peumo' => 'Peumo',
            'Especiales' => 'Especiales',
            'Ruta CV' => 'Ruta CV',
            'Servicios' => 'Servicios',
            'Acuerdos' => 'Acuerdos',
            'Apoyo' => 'Apoyo Alza',
            'Visitas' => 'Visitas Diarias',
        ])->map(fn (string $label, string $process): array => [
            'label' => $label,
            'worked' => ($paymentCountsByProcess[$process] ?? 0) > 0,
        ])->values();
        $processChecklist->push(['label' => 'Cierre definitivo', 'worked' => $monthClosed]);
        $processAmounts = $serviceCounts
            ->groupBy('service_name')
            ->map(fn ($processes): int => (int) $processes->sum('amount'))
            ->filter(fn (int $amount): bool => $amount > 0)
            ->sortDesc();
        $clientPaymentRows = CourierPaymentMovement::query()
            ->when($tenant, fn ($query) => $query->where('tenant_id', $tenant->id), fn ($query) => $query->whereRaw('1 = 0'))
            ->where('periodo', $selectedPeriod)->where('condicion_pago', 'SI')
            ->selectRaw('client_id, comerciante_pila, SUM(COALESCE(valor, 0)) AS amount')
            ->groupBy('client_id', 'comerciante_pila')->get();
        $clients = $tenant ? Client::query()->where('tenant_id', $tenant->id)
            ->whereIn('id', $clientPaymentRows->pluck('client_id')->filter()->unique())
            ->get(['id', 'source_merchant_name', 'commercial_name', 'legal_name'])->keyBy('id') : collect();
        $clientAmounts = $clientPaymentRows
            ->groupBy(function (CourierPaymentMovement $row) use ($clients): string {
                $client = $clients->get($row->client_id);

                return trim((string) ($client?->source_merchant_name ?: $client?->commercial_name ?: $client?->legal_name ?: $row->comerciante_pila)) ?: 'Sin cliente';
            })
            ->map(fn ($rows): int => (int) $rows->sum('amount'))
            ->filter(fn (int $amount): bool => $amount > 0)
            ->sortDesc();
        $recordCount = (int) $sourceProcessCounts->sum('total');
        $paymentDashboard = $paymentSummary->forPeriod($tenant?->id, $selectedPeriod);

        return view('provider-payments::dashboard', compact('merchantCounts', 'statusCounts', 'serviceCounts', 'sourceProcessCounts', 'paymentCountsByProcess', 'pendingProcessCounts', 'processChecklist', 'processAmounts', 'clientAmounts', 'periods', 'selectedPeriod', 'recordCount', 'paymentDashboard', 'monthClosed', 'closure'));
    }

    public function movements(Request $request): View
    {
        $tenant = Tenant::query()->where('code', '4N')->first();
        $periods = $tenant ? CourierMovement::query()->where('tenant_id', $tenant->id)->whereNotNull('nombre_proceso')
            ->selectRaw('SUBSTR(nombre_proceso, 1, 6) AS period')->where('nombre_proceso', 'like', '______-%')
            ->distinct()->orderByDesc('period')->pluck('period')->all() : [];
        $period = (string) $request->query('period', $periods[0] ?? '');
        if (! in_array($period, $periods, true)) {
            $period = $periods[0] ?? '';
        }
        $process = trim((string) $request->query('process', ''));
        $status = trim((string) $request->query('status', ''));
        $merchant = trim((string) $request->query('merchant', ''));
        $commune = trim((string) $request->query('commune', ''));
        $search = trim((string) $request->query('q', ''));
        $minimumTransformedWeight = $request->filled('minimum_transformed_weight')
            ? (int) $request->validate(['minimum_transformed_weight' => ['nullable', 'integer', 'min:0']])['minimum_transformed_weight']
            : null;

        $base = CourierMovement::query()
            ->when($tenant, fn ($query) => $query->where('tenant_id', $tenant->id), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($period !== '', fn ($query) => $query->where('nombre_proceso', 'like', $period.'-%'), fn ($query) => $query->whereRaw('1 = 0'));
        $processOptions = (clone $base)->whereNotNull('nombre_proceso')->selectRaw('SUBSTR(nombre_proceso, 8) AS value')->distinct()->orderBy('value')->pluck('value');
        $statusOptions = (clone $base)->whereNotNull('status')->where('status', '<>', '')->distinct()->orderBy('status')->pluck('status');
        $merchantOptions = (clone $base)->whereNotNull('merchant_name')->where('merchant_name', '<>', '')->distinct()->orderBy('merchant_name')->pluck('merchant_name');
        $communeOptions = (clone $base)->whereNotNull('destination_commune_name')->where('destination_commune_name', '<>', '')->distinct()->orderBy('destination_commune_name')->pluck('destination_commune_name');

        $movements = (clone $base)
            ->when($process !== '', fn ($query) => $query->where('nombre_proceso', $period.'-'.$process))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($merchant !== '', fn ($query) => $query->where('merchant_name', $merchant))
            ->when($commune !== '', fn ($query) => $query->where('destination_commune_name', $commune))
            ->when($minimumTransformedWeight !== null, fn ($query) => $query->where('peso_transformado', '>', $minimumTransformedWeight))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    foreach (['tracking_number', 'merchant_name', 'service_name', 'status', 'destination_commune_name', 'nombre_proceso'] as $column) {
                        $query->orWhere($column, 'like', '%'.$search.'%');
                    }
                });
            })
            ->orderByDesc('id')->paginate(100)->withQueryString();
        $providersByCommune = $tenant ? Coverage::query()
            ->where('tenant_id', $tenant->id)->where('is_active', true)->whereNotNull('provider_id')->with('provider:id,operational_name,legal_name')
            ->get()->filter(fn (Coverage $coverage): bool => $coverage->provider !== null)
            ->mapWithKeys(fn (Coverage $coverage): array => [Str::of($coverage->commune_name)->squish()->lower()->ascii()->toString() => $coverage->provider]) : collect();
        $fixedProcessPayments = $tenant ? CourierPaymentMovement::query()
            ->where('tenant_id', $tenant->id)->whereIn('tipo_pago', ['Ruta CV', 'Servicios', 'Acuerdos', 'Apoyo Alza', 'Visitas Diarias'])
            ->whereIn('courier_movement_id', $movements->pluck('id'))
            ->get(['courier_movement_id', 'nombre_operacional', 'razon_social_proveedor'])
            ->keyBy('courier_movement_id') : collect();
        foreach ($movements as $movement) {
            if (in_array($movement->source_system, ['Ruta CV', 'Servicios', 'Acuerdos', 'Apoyo Alza', 'Visitas Diarias'], true)) {
                $payment = $fixedProcessPayments->get($movement->id);
                $movement->setAttribute('resolved_operational_name', $payment?->nombre_operacional ?: $payment?->razon_social_proveedor);

                continue;
            }
            $provider = $providersByCommune->get(Str::of((string) $movement->destination_commune_name)->squish()->lower()->ascii()->toString());
            $movement->setAttribute('resolved_operational_name', $provider?->operational_name ?: $provider?->legal_name);
        }

        return view('provider-payments::movements-index', compact(
            'movements', 'periods', 'period', 'process', 'status', 'merchant', 'commune', 'search', 'minimumTransformedWeight',
            'processOptions', 'statusOptions', 'merchantOptions', 'communeOptions',
        ));
    }

    public function destroyProcess(Request $request, ProcessDeletionAuthorizer $authorizer, CourierSpecialPaymentRollback $specialRollback, RutaCvClosingService $rutaCvClosing, BaseServicioClosingService $servicioClosing, AcuerdoClosingService $acuerdoClosing, ApoyoAlzaClosingService $apoyoClosing, VisitaDiariaClosingService $visitaClosing): RedirectResponse
    {
        $validated = $request->validate([
            'process_name' => ['required', 'string', 'regex:/^\d{6}-.+$/', 'max:100'],
            'return_to' => ['nullable', 'in:work'],
        ]);
        $authorizer->authorize($request);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $processName = $validated['process_name'];
        MonthlyPaymentClosingService::assertOpen($tenant->id, substr($processName, 0, 6));
        $returnRoute = ($validated['return_to'] ?? '') === 'work'
            ? 'provider-payments.courier-movements.compile.work'
            : 'provider-payments.dashboard';
        if (str_ends_with($processName, '-Apoyo')) {
            $period = substr($processName, 0, 6);
            $count = $apoyoClosing->reopen($tenant->id, $period);

            return redirect()->route('provider-payments.courier-movements.apoyo-alza', ['periodo' => $period])
                ->with('status', "Período {$period} reabierto. {$count} pagos de Apoyo Alza retirados; ya puedes corregir y volver a cerrar.");
        }
        $period = substr($processName, 0, 6);
        $baseProcess = match ($processName) {
            $period.'-Acuerdos' => 'Acuerdos',
            $period.'-Ruta CV' => 'Ruta CV',
            $period.'-Variable' => 'Variables',
            default => null,
        };
        if ($baseProcess !== null && ApoyoAlza::query()->where('tenant_id', $tenant->id)
            ->where('periodo', $period)->where('proceso_base', $baseProcess)
            ->whereNotNull('closed_at')->exists()) {
            throw ValidationException::withMessages(['process_name' => 'Primero reabre Apoyo Alza de este período con la clave maestra para modificar el proceso base.']);
        }
        if (str_ends_with($processName, '-Ruta CV')) {
            $period = substr($processName, 0, 6);
            $count = $rutaCvClosing->reopen($tenant->id, $period);

            return redirect()->route('provider-payments.courier-movements.rutas-cv', ['periodo' => $period])
                ->with('status', "Período {$period} reabierto. {$count} pagos de Ruta CV retirados; ya puedes corregir y volver a cerrar.");
        }
        if (str_ends_with($processName, '-Visitas')) {
            $count = $visitaClosing->reopen($tenant->id, $period);

            return redirect()->route('provider-payments.courier-movements.visitas', ['periodo' => $period])
                ->with('status', "Período {$period} reabierto. {$count} pagos de Visitas retirados.");
        }
        if (str_ends_with($processName, '-Servicios')) {
            $period = substr($processName, 0, 6);
            $count = $servicioClosing->reopen($tenant->id, $period);

            return redirect()->route('provider-payments.courier-movements.servicios', ['periodo' => $period, 'estado' => 'todos'])
                ->with('status', "Período {$period} reabierto. {$count} pagos de Servicios retirados; ya puedes corregir y volver a cerrar.");
        }
        if (str_ends_with($processName, '-Acuerdos')) {
            $period = substr($processName, 0, 6);
            $count = $acuerdoClosing->reopen($tenant->id, $period);

            return redirect()->route('provider-payments.courier-movements.acuerdos', ['periodo' => $period])
                ->with('status', "Período {$period} reabierto. {$count} pagos de Acuerdos retirados; ya puedes corregir y volver a cerrar.");
        }
        if (str_ends_with($processName, '-Especiales')) {
            $result = $specialRollback->rollback($tenant->id, $processName);

            return redirect()->route($returnRoute, ['period' => substr($processName, 0, 6)])
                ->with('status', sprintf('Especiales revertidos. Movimientos nuevos eliminados: %s. Pagos anteriores restaurados: %s.',
                    number_format($result['deleted_movements'], 0, ',', '.'), number_format($result['restored'], 0, ',', '.')));
        }
        [$deletedMovements, $deletedPayments] = DB::transaction(function () use ($tenant, $processName): array {
            $movementIds = DB::table('PPR_movimientos_courier')->select('id')
                ->where('tenant_id', $tenant->id)->where('nombre_proceso', $processName);
            $hasSpecialPayments = DB::table('PPR_Pago_Movimientos_Courier')
                ->where('tenant_id', $tenant->id)->whereIn('courier_movement_id', $movementIds)
                ->where(fn ($query) => $query->where('tipo_pago', 'Especiales')
                    ->orWhere('nombre_proceso', 'Especiales')
                    ->orWhere('nombre_proceso', 'like', '%-Especiales'))->exists();
            if ($hasSpecialPayments) {
                throw ValidationException::withMessages(['process_name' => 'Este proceso tiene pagos Especiales asociados. Revierte Especiales antes de eliminar sus movimientos de origen.']);
            }
            $deletedPayments = DB::table('PPR_Pago_Movimientos_Courier')
                ->where('tenant_id', $tenant->id)
                ->whereIn('courier_movement_id', DB::table('PPR_movimientos_courier')
                    ->select('id')
                    ->where('tenant_id', $tenant->id)
                    ->where('nombre_proceso', $processName))
                ->delete();

            $deletedMovements = CourierMovement::query()
                ->where('tenant_id', $tenant->id)
                ->where('nombre_proceso', $processName)
                ->delete();

            return [$deletedMovements, $deletedPayments];
        });

        return redirect()->route($returnRoute, ['period' => substr($processName, 0, 6)])
            ->with('status', $deletedMovements > 0
                ? sprintf('Proceso %s eliminado. Movimientos: %s. Registros de pago: %s.', $processName, number_format($deletedMovements, 0, ',', '.'), number_format($deletedPayments, 0, ',', '.'))
                : sprintf('El proceso %s ya no tenía registros.', $processName));
    }
}
