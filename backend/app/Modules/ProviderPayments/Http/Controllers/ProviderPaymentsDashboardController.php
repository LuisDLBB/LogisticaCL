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

        $processNames = (clone $movements)->whereNotNull('nombre_proceso')->distinct()->orderBy('nombre_proceso')->pluck('nombre_proceso');
        $recordCount = (clone $movements)->count();

        return view('provider-payments::dashboard', compact('merchantCounts', 'statusCounts', 'periods', 'selectedPeriod', 'processNames', 'recordCount'));
    }
}
