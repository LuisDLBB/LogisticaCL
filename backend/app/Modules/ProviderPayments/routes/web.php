<?php

use App\Modules\ProviderPayments\Http\Controllers\ProviderPaymentsDashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('pago-proveedores')
    ->name('provider-payments.')
    ->group(function (): void {
        Route::get('/', ProviderPaymentsDashboardController::class)->name('dashboard');
    });
