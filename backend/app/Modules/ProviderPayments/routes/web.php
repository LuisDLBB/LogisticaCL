<?php

use App\Modules\ProviderPayments\Http\Controllers\ProviderPaymentsDashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('pago-proveedores')
    ->name('provider-payments.')
    ->group(function (): void {
        Route::get('/', ProviderPaymentsDashboardController::class)->name('dashboard');
        Route::view('/carga-movimientos-courier', 'provider-payments::placeholder', ['title' => 'Carga Movimientos Courier'])->name('courier-movements.upload');
        Route::view('/compilar-movimientos-courier', 'provider-payments::placeholder', ['title' => 'Compilar Movimientos Courier'])->name('courier-movements.compile');

        foreach (['Clientes', 'Sucursales', 'Servicios', 'Centro de Costos', 'Pesos', 'Proveedores', 'Bancos', 'Vehículos', 'Coberturas', 'Llave centro costos', 'Estados'] as $name) {
            Route::view('/mantenedor/'.str($name)->slug(), 'provider-payments::placeholder', ['title' => "Mantenedor: {$name}"])
                ->name('maintainers.'.str($name)->slug());
        }
    });
