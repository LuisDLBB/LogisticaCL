<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\CourierMovement;
use Illuminate\View\View;

class ProviderPaymentsDashboardController
{
    public function __invoke(): View
    {
        $merchantCounts = CourierMovement::query()
            ->selectRaw('merchant_name, count(*) as total')
            ->groupBy('merchant_name')
            ->orderByDesc('total')
            ->get();

        $statusCounts = CourierMovement::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->orderByDesc('total')
            ->get();

        return view('provider-payments::dashboard', compact('merchantCounts', 'statusCounts'));
    }
}
