<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\CourierMovement;
use App\Models\Coverage;
use App\Models\Tenant;
use App\Modules\ProviderPayments\Services\CourierPaymentSummary;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProviderPaymentsDashboardController
{
    public function __invoke(Request $request, CourierPaymentSummary $paymentSummary): View
    {
        $tenant = Tenant::query()->where('code', '4N')->first();
        $periods = $tenant ? CourierMovement::query()->where('tenant_id', $tenant->id)->whereNotNull('nombre_proceso')
            ->selectRaw('SUBSTR(nombre_proceso, 1, 6) AS period')
            ->where('nombre_proceso', 'like', '______-%')
            ->distinct()->orderByDesc('period')->pluck('period')->all() : [];
        $selectedPeriod = (string) $request->query('period', $periods[0] ?? '');
        if (! in_array($selectedPeriod, $periods, true)) {
            $selectedPeriod = $periods[0] ?? '';
        }
        $movements = CourierMovement::query()
            ->when($tenant, fn ($query) => $query->where('tenant_id', $tenant->id), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($selectedPeriod !== '', fn ($query) => $query->where('nombre_proceso', 'like', $selectedPeriod.'-%'), fn ($query) => $query->whereRaw('1 = 0'));

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

        $serviceCounts = (clone $movements)
            ->whereNotNull('nombre_proceso')
            ->selectRaw('SUBSTR(nombre_proceso, 8) AS service_name, count(*) AS total')
            ->groupByRaw('SUBSTR(nombre_proceso, 8)')
            ->orderByDesc('total')
            ->get();
        $recordCount = (clone $movements)->count();
        $paymentDashboard = $paymentSummary->forPeriod($tenant?->id, $selectedPeriod);

        return view('provider-payments::dashboard', compact('merchantCounts', 'statusCounts', 'serviceCounts', 'periods', 'selectedPeriod', 'recordCount', 'paymentDashboard'));
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
        foreach ($movements as $movement) {
            $provider = $providersByCommune->get(Str::of((string) $movement->destination_commune_name)->squish()->lower()->ascii()->toString());
            $movement->setAttribute('resolved_operational_name', $provider?->operational_name ?: $provider?->legal_name);
        }

        return view('provider-payments::movements-index', compact(
            'movements', 'periods', 'period', 'process', 'status', 'merchant', 'commune', 'search', 'minimumTransformedWeight',
            'processOptions', 'statusOptions', 'merchantOptions', 'communeOptions',
        ));
    }

    public function destroyProcess(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'process_name' => ['required', 'string', 'regex:/^\d{6}-.+$/', 'max:100'],
        ]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $processName = $validated['process_name'];
        $deleted = CourierMovement::query()
            ->where('tenant_id', $tenant->id)
            ->where('nombre_proceso', $processName)
            ->delete();

        return redirect()->route('provider-payments.dashboard', ['period' => substr($processName, 0, 6)])
            ->with('status', $deleted > 0
                ? sprintf('Proceso %s eliminado: %s registros borrados.', $processName, number_format($deleted, 0, ',', '.'))
                : sprintf('El proceso %s ya no tenía registros.', $processName));
    }
}
