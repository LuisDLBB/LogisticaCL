<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\CourierMovement;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProviderPaymentsDashboardController
{
    public function __invoke(Request $request): View
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

        return view('provider-payments::dashboard', compact('merchantCounts', 'statusCounts', 'serviceCounts', 'periods', 'selectedPeriod', 'recordCount'));
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
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    foreach (['tracking_number', 'merchant_name', 'service_name', 'status', 'destination_commune_name', 'nombre_proceso'] as $column) {
                        $query->orWhere($column, 'like', '%'.$search.'%');
                    }
                });
            })
            ->orderByDesc('id')->paginate(100)->withQueryString();

        return view('provider-payments::movements-index', compact(
            'movements', 'periods', 'period', 'process', 'status', 'merchant', 'commune', 'search',
            'processOptions', 'statusOptions', 'merchantOptions', 'communeOptions',
        ));
    }
}
