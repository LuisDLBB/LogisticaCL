<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use Illuminate\View\View;

class ProviderPaymentsDashboardController
{
    public function __invoke(): View
    {
        return view('provider-payments::dashboard');
    }
}
