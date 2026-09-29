<?php

use App\Fleet\FleetAccess;
use App\Http\Controllers\Fleet\AuthController;
use App\Http\Controllers\Fleet\CoordinationController;
use App\Http\Controllers\Fleet\HomeController;
use App\Http\Controllers\Fleet\MaintenanceController;
use App\Http\Controllers\Fleet\PermissionAdministrationController;
use App\Http\Controllers\Fleet\TenantController;
use App\Http\Controllers\Fleet\UserAdministrationController;
use App\Http\Controllers\Fleet\VehicleController;
use Illuminate\Support\Facades\Route;

Route::prefix('control-flota')->name('fleet.')->group(function (): void {
    Route::get('/ingresar', [AuthController::class, 'showLogin'])->name('login')->middleware('guest');
    Route::post('/ingresar', [AuthController::class, 'login'])->name('login.submit')->middleware(['guest', 'throttle:5,1']);
    Route::post('/salir', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

    Route::middleware('auth')->group(function (): void {
        Route::post('/empresa', [TenantController::class, 'switch'])->name('tenant.switch');

        Route::middleware('fleet.tenant')->group(function (): void {
            Route::get('/', [HomeController::class, 'index'])->name('home');

            Route::get('/administrador/usuarios', [UserAdministrationController::class, 'index'])
                ->middleware('fleet.permission:admin.users')->name('users.index');
            Route::post('/administrador/usuarios', [UserAdministrationController::class, 'create'])
                ->middleware('fleet.permission:admin.users,3')->name('users.create');
            Route::post('/administrador/usuarios/asociar', [UserAdministrationController::class, 'associate'])
                ->middleware('fleet.permission:admin.users,3')->name('users.associate');
            Route::patch('/administrador/usuarios/{membership}', [UserAdministrationController::class, 'update'])
                ->middleware('fleet.permission:admin.users,3')->name('users.update');

            Route::get('/administrador/permisos', [PermissionAdministrationController::class, 'index'])
                ->middleware('fleet.permission:admin.permissions')->name('permissions.index');
            Route::put('/administrador/permisos/perfiles/{profile}', [PermissionAdministrationController::class, 'updateProfile'])
                ->middleware('fleet.permission:admin.permissions,3')->name('permissions.profile.update');
            Route::put('/administrador/permisos/usuarios/{membership}', [PermissionAdministrationController::class, 'updateUser'])
                ->middleware('fleet.permission:admin.permissions,3')->name('permissions.user.update');

            Route::get('/operaciones/flota', [VehicleController::class, 'index'])
                ->middleware('fleet.permission:operations.fleet')->name('page.operations-fleet');
            Route::get('/operaciones/flota/{vehicle}', [VehicleController::class, 'show'])
                ->middleware('fleet.permission:operations.fleet')->name('vehicles.show');

            Route::get('/operaciones/mantenciones', [MaintenanceController::class, 'index'])
                ->middleware('fleet.permission:operations.maintenance')->name('page.operations-maintenance');
            Route::get('/operaciones/mantenciones/nueva', [MaintenanceController::class, 'create'])
                ->middleware('fleet.permission:operations.maintenance,2')->name('maintenance.create');
            Route::post('/operaciones/mantenciones', [MaintenanceController::class, 'store'])
                ->middleware('fleet.permission:operations.maintenance,2')->name('maintenance.store');
            Route::get('/operaciones/mantenciones/{maintenance}', [MaintenanceController::class, 'show'])
                ->middleware('fleet.permission:operations.maintenance')->name('maintenance.show');
            Route::put('/operaciones/mantenciones/{maintenance}', [MaintenanceController::class, 'update'])
                ->middleware('fleet.permission:operations.maintenance,2')->name('maintenance.update');
            Route::post('/operaciones/mantenciones/{maintenance}/cerrar', [MaintenanceController::class, 'close'])
                ->middleware('fleet.permission:operations.maintenance.close,2')->name('maintenance.close');
            Route::post('/operaciones/mantenciones/{maintenance}/corregir', [MaintenanceController::class, 'correct'])
                ->middleware('fleet.permission:operations.maintenance.correct,3')->name('maintenance.correct');
            Route::post('/operaciones/mantenciones/{maintenance}/documentos', [MaintenanceController::class, 'upload'])
                ->middleware('fleet.permission:operations.maintenance,2')->name('maintenance.documents.upload');
            Route::get('/operaciones/mantenciones/{maintenance}/documentos/{document}', [MaintenanceController::class, 'download'])
                ->middleware('fleet.permission:operations.maintenance')->name('maintenance.documents.download');

            Route::get('/administrador/parametros-mantenciones', [MaintenanceController::class, 'settings'])
                ->middleware('fleet.permission:admin.permissions,3')->name('maintenance.settings');
            Route::put('/administrador/parametros-mantenciones', [MaintenanceController::class, 'updateSettings'])
                ->middleware('fleet.permission:admin.permissions,3')->name('maintenance.settings.update');

            Route::get('/coordinacion/retiros-fijos', [CoordinationController::class, 'fixedIndex'])
                ->middleware('fleet.permission:coordination.fixed-pickups')->name('page.coordination-fixed-pickups');
            Route::post('/coordinacion/retiros-fijos/pasar', [CoordinationController::class, 'fixedConvert'])
                ->middleware('fleet.permission:coordination.fixed-pickups,2')->name('fixed.convert');
            Route::put('/coordinacion/retiros-fijos/{fixedPickup}', [CoordinationController::class, 'fixedUpdate'])
                ->middleware('fleet.permission:coordination.fixed-pickups,2')->name('fixed.update');
            Route::post('/coordinacion/retiros-fijos/{fixedPickup}/activar', [CoordinationController::class, 'fixedToggle'])
                ->middleware('fleet.permission:coordination.fixed-pickups,2')->name('fixed.toggle');
            Route::put('/coordinacion/puntos/{branch}', [CoordinationController::class, 'pointUpdate'])
                ->middleware('fleet.permission:coordination.fixed-pickups,2')->name('points.update');

            Route::get('/coordinacion/solicitudes', [CoordinationController::class, 'requestsIndex'])
                ->middleware('fleet.permission:coordination.requests')->name('page.coordination-requests');
            Route::get('/coordinacion/solicitudes/nueva', [CoordinationController::class, 'requestCreate'])
                ->middleware('fleet.permission:coordination.requests,2')->name('requests.create');
            Route::get('/coordinacion/clientes/{client}/puntos', [CoordinationController::class, 'clientPoints'])
                ->middleware('fleet.permission:coordination.requests')->name('clients.points');
            Route::post('/coordinacion/solicitudes', [CoordinationController::class, 'requestStore'])
                ->middleware('fleet.permission:coordination.requests,2')->name('requests.store');
            Route::get('/coordinacion/solicitudes/{serviceRequest}', [CoordinationController::class, 'requestShow'])
                ->middleware('fleet.permission:coordination.requests')->name('requests.show');
            Route::post('/coordinacion/solicitudes/{serviceRequest}/agendar', [CoordinationController::class, 'schedule'])
                ->middleware('fleet.permission:coordination.requests,2')->name('requests.schedule');
            Route::post('/coordinacion/solicitudes/{serviceRequest}/retirado', [CoordinationController::class, 'retire'])
                ->middleware('fleet.permission:coordination.requests,2')->name('requests.retire');
            Route::post('/coordinacion/solicitudes/{serviceRequest}/reprogramar', [CoordinationController::class, 'reprogramRequest'])
                ->middleware('fleet.permission:coordination.reprogramming.request,2')->name('requests.reprogram');
            Route::get('/coordinacion/reprogramaciones', [CoordinationController::class, 'reprogramIndex'])
                ->middleware('fleet.permission:coordination.reprogramming.approve,3')->name('reprogramming.index');
            Route::post('/coordinacion/reprogramaciones/{reprogramming}/resolver', [CoordinationController::class, 'reprogramDecide'])
                ->middleware('fleet.permission:coordination.reprogramming.approve,3')->name('reprogramming.decide');

            foreach (FleetAccess::PAGES as $permissionCode => $page) {
                if (in_array($permissionCode, ['operations.fleet', 'operations.maintenance', 'coordination.requests', 'coordination.fixed-pickups'], true)) {
                    continue;
                }

                Route::get($page['slug'], [HomeController::class, 'placeholder'])
                    ->defaults('permissionCode', $permissionCode)
                    ->middleware('fleet.permission:'.$permissionCode)
                    ->name('page.'.str_replace('.', '-', $permissionCode));
            }
        });
    });
});
