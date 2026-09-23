<?php

use App\Modules\ProviderPayments\Http\Controllers\ClientBranchMaintainerController;
use App\Modules\ProviderPayments\Http\Controllers\ClientMaintainerController;
use App\Modules\ProviderPayments\Http\Controllers\CostCenterKeyMaintainerController;
use App\Modules\ProviderPayments\Http\Controllers\CostCenterMaintainerController;
use App\Modules\ProviderPayments\Http\Controllers\CostCenterWeightRateMaintainerController;
use App\Modules\ProviderPayments\Http\Controllers\CourierMovementImportController;
use App\Modules\ProviderPayments\Http\Controllers\CourierMovementCompileController;
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
        Route::get('/movimientos', [ProviderPaymentsDashboardController::class, 'movements'])->name('movements.index');
        Route::delete('/movimientos/procesos', [ProviderPaymentsDashboardController::class, 'destroyProcess'])->name('movements.processes.destroy');
        Route::view('/carga-movimientos-courier', 'provider-payments::courier-movements-upload', ['processType' => 'variables', 'processTitle' => 'Courier Variables'])->name('courier-movements.upload');
        Route::view('/carga-movimientos-courier/lanas', 'provider-payments::courier-movements-upload', ['processType' => 'lanas', 'processTitle' => 'Courier Lanas'])->name('courier-movements.lanas');
        Route::view('/carga-movimientos-courier/retornos', 'provider-payments::courier-movements-upload', ['processType' => 'retornos', 'processTitle' => 'Courier Retornos'])->name('courier-movements.retornos');
        Route::view('/carga-movimientos-courier/especiales', 'provider-payments::placeholder', ['title' => 'Courier Especiales'])->name('courier-movements.especiales');
        Route::view('/carga-movimientos-courier/rutas-cv', 'provider-payments::placeholder', ['title' => 'Rutas CV'])->name('courier-movements.rutas-cv');
        Route::view('/carga-movimientos-courier/servicios', 'provider-payments::placeholder', ['title' => 'Servicios Courier'])->name('courier-movements.servicios');
        Route::view('/carga-movimientos-courier/acuerdos', 'provider-payments::placeholder', ['title' => 'Acuerdos Courier'])->name('courier-movements.acuerdos');
        Route::post('/carga-movimientos-courier/validar', [CourierMovementImportController::class, 'validateFile'])->name('courier-movements.validate');
        Route::post('/carga-movimientos-courier/no-cargar-coberturas', [CourierMovementImportController::class, 'excludeCoverages'])->name('courier-movements.exclude-coverages');
        Route::post('/carga-movimientos-courier/no-cargar-servicios', [CourierMovementImportController::class, 'excludeServices'])->name('courier-movements.exclude-services');
        Route::get('/carga-movimientos-courier/errores.csv', [CourierMovementImportController::class, 'downloadErrors'])->name('courier-movements.errors.download');
        Route::post('/carga-movimientos-courier/cargar', [CourierMovementImportController::class, 'storeMovements'])->name('courier-movements.store');
        Route::get('/compilar-movimientos-courier', [CourierMovementCompileController::class, 'index'])->name('courier-movements.compile');
        Route::get('/compilar-movimientos-courier/trabajar', [CourierMovementCompileController::class, 'work'])->name('courier-movements.compile.work');
        Route::get('/compilar-movimientos-courier/trabajar/revisar-llaves-cc', [CourierMovementCompileController::class, 'reviewKeys'])->name('courier-movements.compile.keys.review');
        Route::post('/compilar-movimientos-courier/trabajar/guardar-llaves-cc', [CourierMovementCompileController::class, 'saveReviewedKeys'])->name('courier-movements.compile.keys.save');
        Route::post('/compilar-movimientos-courier/trabajar', [CourierMovementCompileController::class, 'compile'])->name('courier-movements.compile.store');
        Route::post('/compilar-movimientos-courier/trabajar/generar-llaves-cc', [CourierMovementCompileController::class, 'generateMissingKeys'])->name('courier-movements.compile.keys.generate');
        Route::delete('/compilar-movimientos-courier/procesos', [CourierMovementCompileController::class, 'destroyProcess'])->name('courier-movements.compile.destroy');
        Route::post('/compilar-movimientos-courier/trabajar/estados-no-pagar', [CourierMovementCompileController::class, 'markNonPayable'])->name('courier-movements.compile.non-payable.mark');
        Route::post('/compilar-movimientos-courier/trabajar/proveedor-interno', [CourierMovementCompileController::class, 'markInternalProvider'])->name('courier-movements.compile.internal-provider.mark');
        Route::post('/compilar-movimientos-courier/trabajar/actualizar-proveedores-4n', [CourierMovementCompileController::class, 'updateFourNorthProviders'])->name('courier-movements.compile.providers-4n.update');

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
        Route::get('/mantenedor/tarifas-cc', [CostCenterWeightRateMaintainerController::class, 'index'])->name('maintainers.tarifas-cc');
        Route::post('/mantenedor/tarifas-cc', [CostCenterWeightRateMaintainerController::class, 'store'])->name('maintainers.tarifas-cc.store');
        Route::put('/mantenedor/tarifas-cc/{rate}', [CostCenterWeightRateMaintainerController::class, 'update'])->name('maintainers.tarifas-cc.update');
        Route::redirect('/mantenedor/pesos', '/pago-proveedores/mantenedor/pesos/transformados')->name('maintainers.pesos');
        Route::get('/mantenedor/pesos/transformados', [WeightMaintainerController::class, 'transformed'])->name('maintainers.pesos.transformados');
        Route::post('/mantenedor/pesos/transformados', [WeightMaintainerController::class, 'storeReal'])->name('maintainers.pesos.transformados.store');
        Route::put('/mantenedor/pesos/transformados/{weight}', [WeightMaintainerController::class, 'update'])->name('maintainers.pesos.transformados.update');
        Route::get('/mantenedor/pesos/reales', [WeightMaintainerController::class, 'real'])->name('maintainers.pesos.reales');
        Route::post('/mantenedor/pesos/reales/sincronizar', [WeightMaintainerController::class, 'syncRealWeights'])->name('maintainers.pesos.reales.sync');
        Route::get('/mantenedor/proveedores', [OperationalMasterMaintainerController::class, 'providers'])->name('maintainers.proveedores');
        Route::post('/mantenedor/proveedores', [OperationalMasterMaintainerController::class, 'storeProvider'])->name('maintainers.proveedores.store');
        Route::put('/mantenedor/proveedores/{provider}', [OperationalMasterMaintainerController::class, 'updateProvider'])->name('maintainers.proveedores.update');
        Route::get('/mantenedor/bancos', [OperationalMasterMaintainerController::class, 'banks'])->name('maintainers.bancos');
        Route::post('/mantenedor/bancos', [OperationalMasterMaintainerController::class, 'storeBank'])->name('maintainers.bancos.store');
        Route::put('/mantenedor/bancos/{banco}', [OperationalMasterMaintainerController::class, 'updateBank'])->name('maintainers.bancos.update');
        Route::post('/mantenedor/bancos/tipos-cuenta', [OperationalMasterMaintainerController::class, 'storeBankAccountType'])->name('maintainers.bancos.tipos-cuenta.store');
        Route::put('/mantenedor/bancos/tipos-cuenta/{accountType}', [OperationalMasterMaintainerController::class, 'updateBankAccountType'])->name('maintainers.bancos.tipos-cuenta.update');
        Route::get('/mantenedor/vehiculos', [OperationalMasterMaintainerController::class, 'vehicles'])->name('maintainers.vehiculos');
        Route::post('/mantenedor/vehiculos', [OperationalMasterMaintainerController::class, 'storeVehicle'])->name('maintainers.vehiculos.store');
        Route::put('/mantenedor/vehiculos/{vehicle}', [OperationalMasterMaintainerController::class, 'updateVehicle'])->name('maintainers.vehiculos.update');
        Route::get('/mantenedor/estados', [OperationalMasterMaintainerController::class, 'statuses'])->name('maintainers.estados');
        Route::post('/mantenedor/estados', [OperationalMasterMaintainerController::class, 'storeStatus'])->name('maintainers.estados.store');
        Route::put('/mantenedor/estados/{status}', [OperationalMasterMaintainerController::class, 'updateStatus'])->name('maintainers.estados.update');
        Route::get('/mantenedor/llave-centro-costos', [CostCenterKeyMaintainerController::class, 'create'])->name('maintainers.llave-centro-costos');
        Route::post('/mantenedor/llave-centro-costos', [CostCenterKeyMaintainerController::class, 'store'])->name('maintainers.llave-centro-costos.store');
        Route::post('/mantenedor/llave-centro-costos/nueva', [CostCenterKeyMaintainerController::class, 'storeManual'])->name('maintainers.llave-centro-costos.manual-store');
        Route::post('/mantenedor/llave-centro-costos/replicar-proveedor', [CostCenterKeyMaintainerController::class, 'replicateProvider'])->name('maintainers.llave-centro-costos.replicate-provider');
        Route::post('/mantenedor/llave-centro-costos/guardar-cambios', [CostCenterKeyMaintainerController::class, 'updateMany'])->name('maintainers.llave-centro-costos.update-many');
        Route::put('/mantenedor/llave-centro-costos/{key}', [CostCenterKeyMaintainerController::class, 'update'])->name('maintainers.llave-centro-costos.update');

    });

Route::get('/pago-proveedores/carga-movimientos-courier/revisar-parametros', [CourierMovementImportController::class, 'reviewParameters'])->name('provider-payments.courier-movements.review-parameters');
