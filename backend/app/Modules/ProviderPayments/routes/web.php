<?php

use App\Modules\ProviderPayments\Http\Controllers\ClientBranchMaintainerController;
use App\Modules\ProviderPayments\Http\Controllers\ClientMaintainerController;
use App\Modules\ProviderPayments\Http\Controllers\CostCenterKeyMaintainerController;
use App\Modules\ProviderPayments\Http\Controllers\CostCenterMaintainerController;
use App\Modules\ProviderPayments\Http\Controllers\CourierMovementImportController;
use App\Modules\ProviderPayments\Http\Controllers\CoverageMaintainerController;
use App\Modules\ProviderPayments\Http\Controllers\OperationalMasterMaintainerController;
use App\Modules\ProviderPayments\Http\Controllers\ProviderPaymentsDashboardController;
use App\Modules\ProviderPayments\Http\Controllers\ServiceTypeMaintainerController;
use App\Modules\ProviderPayments\Http\Controllers\WeightMaintainerController;
use Illuminate\Support\Facades\Route;

Route::prefix('pago-proveedores')
    ->name('provider-payments.')
    ->group(function (): void {
        Route::get('/', ProviderPaymentsDashboardController::class)->name('dashboard');
        Route::view('/carga-movimientos-courier', 'provider-payments::courier-movements-upload')->name('courier-movements.upload');
        Route::post('/carga-movimientos-courier/validar', [CourierMovementImportController::class, 'validateFile'])->name('courier-movements.validate');
        Route::post('/carga-movimientos-courier/no-cargar-coberturas', [CourierMovementImportController::class, 'excludeCoverages'])->name('courier-movements.exclude-coverages');
        Route::get('/carga-movimientos-courier/errores.csv', [CourierMovementImportController::class, 'downloadErrors'])->name('courier-movements.errors.download');
        Route::post('/carga-movimientos-courier/cargar', [CourierMovementImportController::class, 'storeMovements'])->name('courier-movements.store');
        Route::view('/compilar-movimientos-courier', 'provider-payments::placeholder', ['title' => 'Compilar Movimientos Courier'])->name('courier-movements.compile');

        Route::get('/mantenedor/coberturas', [CoverageMaintainerController::class, 'create'])->name('maintainers.coberturas');
        Route::post('/mantenedor/coberturas', [CoverageMaintainerController::class, 'store'])->name('maintainers.coberturas.store');
        Route::put('/mantenedor/coberturas/{coverage}', [CoverageMaintainerController::class, 'update'])->name('maintainers.coberturas.update');
        Route::get('/mantenedor/clientes', [ClientMaintainerController::class, 'create'])->name('maintainers.clientes');
        Route::post('/mantenedor/clientes', [ClientMaintainerController::class, 'store'])->name('maintainers.clientes.store');
        Route::put('/mantenedor/clientes/{client}', [ClientMaintainerController::class, 'update'])->name('maintainers.clientes.update');
        Route::get('/mantenedor/sucursales', [ClientBranchMaintainerController::class, 'index'])->name('maintainers.sucursales');
        Route::post('/mantenedor/sucursales', [ClientBranchMaintainerController::class, 'store'])->name('maintainers.sucursales.store');
        Route::put('/mantenedor/sucursales/{branch}', [ClientBranchMaintainerController::class, 'update'])->name('maintainers.sucursales.update');
        Route::get('/mantenedor/servicios', [ServiceTypeMaintainerController::class, 'index'])->name('maintainers.servicios');
        Route::post('/mantenedor/servicios', [ServiceTypeMaintainerController::class, 'store'])->name('maintainers.servicios.store');
        Route::get('/mantenedor/centro-de-costos', [CostCenterMaintainerController::class, 'index'])->name('maintainers.centro-de-costos');
        Route::post('/mantenedor/centro-de-costos', [CostCenterMaintainerController::class, 'store'])->name('maintainers.centro-de-costos.store');
        Route::redirect('/mantenedor/pesos', '/pago-proveedores/mantenedor/pesos/transformados')->name('maintainers.pesos');
        Route::get('/mantenedor/pesos/transformados', [WeightMaintainerController::class, 'transformed'])->name('maintainers.pesos.transformados');
        Route::get('/mantenedor/pesos/reales', [WeightMaintainerController::class, 'real'])->name('maintainers.pesos.reales');
        Route::post('/mantenedor/pesos/reales', [WeightMaintainerController::class, 'storeReal'])->name('maintainers.pesos.reales.store');
        Route::get('/mantenedor/proveedores', [OperationalMasterMaintainerController::class, 'providers'])->name('maintainers.proveedores');
        Route::post('/mantenedor/proveedores', [OperationalMasterMaintainerController::class, 'storeProvider'])->name('maintainers.proveedores.store');
        Route::put('/mantenedor/proveedores/{provider}', [OperationalMasterMaintainerController::class, 'updateProvider'])->name('maintainers.proveedores.update');
        Route::get('/mantenedor/bancos', [OperationalMasterMaintainerController::class, 'banks'])->name('maintainers.bancos');
        Route::post('/mantenedor/bancos', [OperationalMasterMaintainerController::class, 'storeBank'])->name('maintainers.bancos.store');
        Route::put('/mantenedor/bancos/{account}', [OperationalMasterMaintainerController::class, 'updateBank'])->name('maintainers.bancos.update');
        Route::get('/mantenedor/vehiculos', [OperationalMasterMaintainerController::class, 'vehicles'])->name('maintainers.vehiculos');
        Route::post('/mantenedor/vehiculos', [OperationalMasterMaintainerController::class, 'storeVehicle'])->name('maintainers.vehiculos.store');
        Route::put('/mantenedor/vehiculos/{vehicle}', [OperationalMasterMaintainerController::class, 'updateVehicle'])->name('maintainers.vehiculos.update');
        Route::get('/mantenedor/estados', [OperationalMasterMaintainerController::class, 'statuses'])->name('maintainers.estados');
        Route::post('/mantenedor/estados', [OperationalMasterMaintainerController::class, 'storeStatus'])->name('maintainers.estados.store');
        Route::put('/mantenedor/estados/{status}', [OperationalMasterMaintainerController::class, 'updateStatus'])->name('maintainers.estados.update');
        Route::get('/mantenedor/llave-centro-costos', [CostCenterKeyMaintainerController::class, 'create'])->name('maintainers.llave-centro-costos');
        Route::post('/mantenedor/llave-centro-costos', [CostCenterKeyMaintainerController::class, 'store'])->name('maintainers.llave-centro-costos.store');
        Route::post('/mantenedor/llave-centro-costos/nueva', [CostCenterKeyMaintainerController::class, 'storeManual'])->name('maintainers.llave-centro-costos.manual-store');
        Route::put('/mantenedor/llave-centro-costos/{key}', [CostCenterKeyMaintainerController::class, 'update'])->name('maintainers.llave-centro-costos.update');

    });

Route::get('/pago-proveedores/carga-movimientos-courier/revisar-parametros', [CourierMovementImportController::class, 'reviewParameters'])->name('provider-payments.courier-movements.review-parameters');
