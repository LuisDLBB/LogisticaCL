<?php

use App\Modules\Operations\Http\Controllers\OperationDepartureController;
use App\Modules\Operations\Http\Controllers\OperationLoadController;
use App\Modules\Operations\Http\Controllers\OperationLotController;
use App\Modules\Operations\Http\Controllers\OperationSetupController;
use App\Modules\Operations\Http\Controllers\OperationSystemReceptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('operaciones')->name('operations.')->group(function (): void {
    Route::get('/', [OperationLotController::class, 'index'])->name('dashboard');
    Route::get('/cargas/{type}', [OperationLoadController::class, 'index'])->where('type', 'master|reception')->name('loads.index');
    Route::post('/cargas/{type}', [OperationLoadController::class, 'store'])->where('type', 'master|reception')->middleware('throttle:10,1')->name('loads.store');
    Route::get('/carga/{load}', [OperationLoadController::class, 'show'])->whereNumber('load')->name('loads.show');
    Route::get('/recepcion-sistema', [OperationSystemReceptionController::class, 'index'])->name('system-receptions.index');
    Route::post('/recepcion-sistema', [OperationSystemReceptionController::class, 'store'])->name('system-receptions.store');
    Route::get('/recepcion-sistema/{reception}', [OperationSystemReceptionController::class, 'show'])->whereNumber('reception')->name('system-receptions.show');
    Route::put('/recepcion-sistema/{reception}/formato-qr', [OperationSystemReceptionController::class, 'updateQrFormat'])->whereNumber('reception')->name('system-receptions.qr-format');
    Route::post('/recepcion-sistema/{reception}/respaldo', [OperationSystemReceptionController::class, 'uploadPhoto'])->whereNumber('reception')->name('system-receptions.photo.store');
    Route::get('/recepcion-sistema/{reception}/respaldo', [OperationSystemReceptionController::class, 'photo'])->whereNumber('reception')->name('system-receptions.photo');
    Route::post('/recepcion-sistema/{reception}/verificar', [OperationSystemReceptionController::class, 'checkTracking'])->whereNumber('reception')->name('system-receptions.check');
    Route::post('/recepcion-sistema/{reception}/bultos', [OperationSystemReceptionController::class, 'scan'])->whereNumber('reception')->name('system-receptions.scan');
    Route::post('/recepcion-sistema/{reception}/cerrar', [OperationSystemReceptionController::class, 'complete'])->whereNumber('reception')->name('system-receptions.complete');
    Route::post('/procesos', [OperationLotController::class, 'store'])->name('lots.store');
    Route::get('/procesos/{lot}', [OperationLotController::class, 'show'])->whereNumber('lot')->name('lots.show');
    Route::post('/procesos/{lot}/incidencias', [OperationLotController::class, 'resolveMany'])->whereNumber('lot')->name('issues.resolve-many');
    Route::post('/procesos/{lot}/incidencias/{issue}', [OperationLotController::class, 'resolve'])->whereNumber(['lot', 'issue'])->name('issues.resolve');
    Route::get('/configuracion', [OperationSetupController::class, 'index'])->name('setup');
    Route::get('/origenes-postas', [OperationSetupController::class, 'postOrigins'])->name('post-origins');
    Route::put('/origenes-postas/{post}', [OperationSetupController::class, 'updatePostOrigin'])->whereNumber('post')->name('post-origins.update');
    Route::put('/agencias/{agency}', [OperationSetupController::class, 'updateAgency'])->whereNumber('agency')->name('agencies.update');
    Route::get('/transporte', [OperationSetupController::class, 'transport'])->name('transport');
    Route::get('/transporte/{type}/{record}/agencias', [OperationSetupController::class, 'transportAgencies'])->where(['type' => 'troncal|posta', 'record' => '[0-9]+'])->name('transport.agencies');
    Route::get('/transporte/{type}/{record}/coberturas', [OperationSetupController::class, 'transportCoverages'])->where(['type' => 'troncal|posta', 'record' => '[0-9]+'])->name('transport.coverages');
    Route::put('/transporte/{type}/{record}', [OperationSetupController::class, 'updateTransport'])->where(['type' => 'troncal|posta', 'record' => '[0-9]+'])->name('transport.update');
    Route::post('/ubicaciones', [OperationSetupController::class, 'location'])->name('locations.store');
    Route::post('/configuraciones', [OperationSetupController::class, 'configuration'])->name('configurations.store');
    Route::get('/procesos/{lot}/salidas', [OperationDepartureController::class, 'index'])->whereNumber('lot')->name('departures.index');
    Route::get('/procesos/{lot}/salidas/planilla.xlsx', [OperationDepartureController::class, 'spreadsheet'])->whereNumber('lot')->name('departures.spreadsheet');
    Route::post('/procesos/{lot}/salidas', [OperationDepartureController::class, 'store'])->whereNumber('lot')->name('departures.store');
    Route::get('/salidas/{departure}', [OperationDepartureController::class, 'show'])->whereNumber('departure')->name('departures.show');
    Route::put('/salidas/{departure}/transporte', [OperationDepartureController::class, 'assignment'])->whereNumber('departure')->name('departures.assignment');
    Route::post('/salidas/{departure}/aprobar', [OperationDepartureController::class, 'approve'])->whereNumber('departure')->name('departures.approve');
    Route::post('/salidas/{departure}/reabrir', [OperationDepartureController::class, 'reopen'])->whereNumber('departure')->name('departures.reopen');
    Route::post('/salidas/{departure}/cancelar', [OperationDepartureController::class, 'cancel'])->whereNumber('departure')->name('departures.cancel');
    Route::get('/guias/{guide}', [OperationDepartureController::class, 'guide'])->whereNumber('guide')->name('guides.show');
    Route::get('/guias/{guide}/resumen.csv', [OperationDepartureController::class, 'export'])->whereNumber('guide')->name('guides.export');
});
