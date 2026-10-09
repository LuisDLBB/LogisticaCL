<?php

use App\Modules\Operations\Http\Controllers\OperationDriverController;
use Illuminate\Support\Facades\Route;

Route::prefix('operaciones/mi-ruta')->name('operations.driver.')->group(function (): void {
    Route::get('/', [OperationDriverController::class, 'index'])->name('index');
    Route::post('/tomar', [OperationDriverController::class, 'claim'])->name('claim');
    Route::get('/{journey}', [OperationDriverController::class, 'show'])->whereNumber('journey')->name('show');
    Route::get('/{journey}/guias', [OperationDriverController::class, 'guides'])->whereNumber('journey')->name('guides');
    Route::post('/{journey}/iniciar', [OperationDriverController::class, 'start'])->whereNumber('journey')->name('start');
    Route::post('/{journey}/paradas/{stop}/trayecto', [OperationDriverController::class, 'depart'])->whereNumber(['journey', 'stop'])->name('depart');
    Route::post('/{journey}/paradas/{stop}/llegada', [OperationDriverController::class, 'arrive'])->whereNumber(['journey', 'stop'])->name('arrive');
    Route::post('/{journey}/paradas/{stop}/completar', [OperationDriverController::class, 'complete'])->whereNumber(['journey', 'stop'])->name('complete');
    Route::post('/{journey}/finalizar', [OperationDriverController::class, 'finish'])->whereNumber('journey')->name('finish');
    Route::post('/{journey}/traspasos', [OperationDriverController::class, 'transfer'])->whereNumber('journey')->name('transfers.store');
    Route::post('/{journey}/devoluciones-bodega', [OperationDriverController::class, 'warehouse'])->whereNumber('journey')->name('warehouse.store');
    Route::get('/{journey}/devoluciones-bodega/{receipt}/foto', [OperationDriverController::class, 'warehousePhoto'])->whereNumber(['journey', 'receipt'])->name('warehouse.photo');
    Route::post('/traspasos/{transfer}/recibir', [OperationDriverController::class, 'receive'])->whereNumber('transfer')->name('transfers.receive');
    Route::get('/{journey}/evidencias/{evidence}', [OperationDriverController::class, 'evidence'])->whereNumber(['journey', 'evidence'])->name('evidence');
});
