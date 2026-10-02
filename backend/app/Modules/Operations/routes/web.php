<?php

use App\Modules\Operations\Http\Controllers\OperationDepartureController;
use App\Modules\Operations\Http\Controllers\OperationLoadController;
use App\Modules\Operations\Http\Controllers\OperationLotController;
use App\Modules\Operations\Http\Controllers\OperationSetupController;
use Illuminate\Support\Facades\Route;

Route::prefix('operaciones')->name('operations.')->group(function (): void {
    Route::get('/', [OperationLotController::class, 'index'])->name('dashboard');
    Route::get('/cargas/{type}', [OperationLoadController::class, 'index'])->where('type', 'master|reception')->name('loads.index');
    Route::post('/cargas/{type}', [OperationLoadController::class, 'store'])->where('type', 'master|reception')->middleware('throttle:10,1')->name('loads.store');
    Route::get('/carga/{load}', [OperationLoadController::class, 'show'])->whereNumber('load')->name('loads.show');
    Route::post('/procesos', [OperationLotController::class, 'store'])->name('lots.store');
    Route::get('/procesos/{lot}', [OperationLotController::class, 'show'])->whereNumber('lot')->name('lots.show');
    Route::post('/procesos/{lot}/incidencias/{issue}', [OperationLotController::class, 'resolve'])->whereNumber(['lot', 'issue'])->name('issues.resolve');
    Route::get('/configuracion', [OperationSetupController::class, 'index'])->name('setup');
    Route::post('/ubicaciones', [OperationSetupController::class, 'location'])->name('locations.store');
    Route::post('/configuraciones', [OperationSetupController::class, 'configuration'])->name('configurations.store');
    Route::get('/procesos/{lot}/salidas', [OperationDepartureController::class, 'index'])->whereNumber('lot')->name('departures.index');
    Route::post('/procesos/{lot}/salidas', [OperationDepartureController::class, 'store'])->whereNumber('lot')->name('departures.store');
    Route::get('/salidas/{departure}', [OperationDepartureController::class, 'show'])->whereNumber('departure')->name('departures.show');
    Route::put('/salidas/{departure}/transporte', [OperationDepartureController::class, 'assignment'])->whereNumber('departure')->name('departures.assignment');
    Route::post('/salidas/{departure}/aprobar', [OperationDepartureController::class, 'approve'])->whereNumber('departure')->name('departures.approve');
    Route::post('/salidas/{departure}/reabrir', [OperationDepartureController::class, 'reopen'])->whereNumber('departure')->name('departures.reopen');
    Route::post('/salidas/{departure}/cancelar', [OperationDepartureController::class, 'cancel'])->whereNumber('departure')->name('departures.cancel');
    Route::get('/guias/{guide}', [OperationDepartureController::class, 'guide'])->whereNumber('guide')->name('guides.show');
    Route::get('/guias/{guide}/resumen.csv', [OperationDepartureController::class, 'export'])->whereNumber('guide')->name('guides.export');
});
