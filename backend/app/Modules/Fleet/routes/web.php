<?php

use App\Fleet\FleetAccess;
use App\Http\Controllers\Fleet\AuthController;
use App\Http\Controllers\Fleet\CoordinationController;
use App\Http\Controllers\Fleet\DocumentAlertSettingsController;
use App\Http\Controllers\Fleet\FuelController;
use App\Http\Controllers\Fleet\HomeController;
use App\Http\Controllers\Fleet\MaintenanceController;
use App\Http\Controllers\Fleet\MicrosoftRetMailController;
use App\Http\Controllers\Fleet\PermissionAdministrationController;
use App\Http\Controllers\Fleet\PickupWorkflowController;
use App\Http\Controllers\Fleet\RetDriverController;
use App\Http\Controllers\Fleet\RetFeasibilityController;
use App\Http\Controllers\Fleet\RetMerchantController;
use App\Http\Controllers\Fleet\RetMonitorController;
use App\Http\Controllers\Fleet\RetToolsController;
use App\Http\Controllers\Fleet\SchedulingController;
use App\Http\Controllers\Fleet\TenantController;
use App\Http\Controllers\Fleet\UserAdministrationController;
use App\Http\Controllers\Fleet\VehicleController;
use App\Http\Controllers\Fleet\VehicleDocumentController;
use App\Http\Controllers\Fleet\VehicleKilometerController;
use App\Http\Controllers\Fleet\VehicleWritingController;
use Illuminate\Support\Facades\Route;

Route::get('/retiro-cliente/completado', [PickupWorkflowController::class, 'done'])->name('fleet.customer.done');
Route::get('/retiro-cliente/{token}/{action}', [PickupWorkflowController::class, 'customerForm'])->middleware('throttle:30,1')->name('fleet.customer.form');
Route::post('/retiro-cliente/{token}/{action}', [PickupWorkflowController::class, 'customerAct'])->middleware('throttle:10,1')->name('fleet.customer.act');
Route::get('/retiro-conductor/completado', fn () => response('Retiro registrado. Puedes cerrar esta ventana.'))->name('fleet.driver.done');
Route::get('/retiro-conductor/{token}', [RetDriverController::class, 'show'])->middleware('throttle:30,1')->name('fleet.driver.show');
Route::post('/retiro-conductor/{token}', [RetDriverController::class, 'store'])->middleware('throttle:10,1')->name('fleet.driver.store');

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

            Route::get('/operaciones/kilometrajes', [VehicleKilometerController::class, 'index'])->middleware('fleet.permission:operations.fleet')->name('kilometers.index');
            Route::post('/operaciones/kilometrajes', [VehicleKilometerController::class, 'store'])->middleware('fleet.permission:operations.fleet,2')->name('kilometers.store');
            Route::post('/operaciones/kilometrajes/importar', [VehicleKilometerController::class, 'preview'])->middleware('fleet.permission:operations.fleet,2')->name('kilometers.preview');
            Route::get('/operaciones/combustible', [FuelController::class, 'index'])->middleware('fleet.permission:operations.fuel')->name('page.operations-fuel');
            Route::post('/operaciones/combustible/importar', [FuelController::class, 'store'])->middleware('fleet.permission:operations.fuel,2')->name('fuel.store');
            Route::get('/operaciones/flota', [VehicleController::class, 'index'])
                ->middleware('fleet.permission:operations.fleet')->name('page.operations-fleet');
            Route::get('/operaciones/flota/documentos-proximos-a-vencer.xlsx', [VehicleController::class, 'exportUpcomingDocuments'])->middleware('fleet.permission:operations.fleet')->name('vehicles.upcoming-documents.export');
            Route::get('/operaciones/flota/documentos-vencidos.xlsx', [VehicleController::class, 'exportExpiredDocuments'])->middleware('fleet.permission:operations.fleet')->name('vehicles.expired-documents.export');
            Route::patch('/operaciones/flota/prioridades', [VehicleController::class, 'reorder'])->middleware('fleet.permission:operations.fleet')->name('vehicles.priorities');
            Route::patch('/operaciones/flota/{vehicle}/estado', [VehicleController::class, 'updateStatus'])
                ->middleware('fleet.permission:operations.fleet,2')->name('vehicles.status');
            Route::post('/operaciones/flota/{vehicle}/redaccion', [VehicleWritingController::class, 'suggest'])
                ->middleware(['fleet.permission:operations.fleet,2', 'throttle:5,1'])->name('vehicles.writing');
            Route::get('/operaciones/flota/{vehicle}/documentos/{document}', [VehicleDocumentController::class, 'show'])->whereNumber('document')->middleware('fleet.permission:operations.fleet')->name('vehicles.documents.show');
            Route::post('/operaciones/flota/{vehicle}/documentos', [VehicleDocumentController::class, 'store'])->middleware(['fleet.permission:operations.fleet,2', 'throttle:10,1'])->name('vehicles.documents.store');
            Route::get('/operaciones/flota/{vehicle}', [VehicleController::class, 'show'])
                ->middleware('fleet.permission:operations.fleet')->name('vehicles.show');

            Route::get('/operaciones/mantenciones', [MaintenanceController::class, 'index'])
                ->middleware('fleet.permission:operations.maintenance')->name('page.operations-maintenance');
            Route::get('/operaciones/mantenciones/exportar.xlsx', [MaintenanceController::class, 'export'])
                ->middleware('fleet.permission:operations.maintenance')->name('maintenance.export');
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

            Route::put('/administrador/avisos-documentos', [DocumentAlertSettingsController::class, 'update'])->middleware('fleet.permission:admin.permissions,3')->name('documents.settings.update');
            Route::get('/administrador/parametros-mantenciones', [MaintenanceController::class, 'settings'])
                ->middleware('fleet.permission:admin.permissions,3')->name('maintenance.settings');
            Route::put('/administrador/parametros-mantenciones/vehiculos/{vehicle}', [MaintenanceController::class, 'updateInterval'])->middleware('fleet.permission:admin.permissions,3')->name('maintenance.interval.update');
            Route::put('/administrador/parametros-mantenciones', [MaintenanceController::class, 'updateSettings'])
                ->middleware('fleet.permission:admin.permissions,3')->name('maintenance.settings.update');

            Route::get('/coordinacion/retiros-fijos', [CoordinationController::class, 'fixedIndex'])
                ->middleware('fleet.permission:coordination.fixed-pickups')->name('page.coordination-fixed-pickups');
            Route::post('/coordinacion/retiros-fijos', [CoordinationController::class, 'fixedStore'])
                ->middleware('fleet.permission:coordination.fixed-pickups,2')->name('fixed.store');
            Route::post('/coordinacion/retiros-fijos/pasar', [CoordinationController::class, 'fixedConvert'])
                ->middleware('fleet.permission:coordination.fixed-pickups,2')->name('fixed.convert');
            Route::put('/coordinacion/retiros-fijos/{fixedPickup}', [CoordinationController::class, 'fixedUpdate'])
                ->middleware('fleet.permission:coordination.fixed-pickups,2')->name('fixed.update');
            Route::post('/coordinacion/retiros-fijos/{fixedPickup}/activar', [CoordinationController::class, 'fixedToggle'])
                ->middleware('fleet.permission:coordination.fixed-pickups,2')->name('fixed.toggle');
            Route::put('/coordinacion/puntos/{branch}', [CoordinationController::class, 'pointUpdate'])
                ->middleware('fleet.permission:coordination.fixed-pickups,2')->name('points.update');

            Route::get('/coordinacion/monitor', [RetMonitorController::class, 'index'])->name('monitor.index');

            Route::get('/coordinacion/solicitudes', [CoordinationController::class, 'requestsIndex'])
                ->middleware('fleet.permission:coordination.requests')->name('page.coordination-requests');
            Route::get('/coordinacion/excepciones-horario', [CoordinationController::class, 'latePickupIndex'])->name('requests.late.index');
            Route::get('/coordinacion/solicitudes/nueva', [CoordinationController::class, 'requestCreate'])
                ->middleware('fleet.permission:coordination.requests,2')->name('requests.create');
            Route::post('/coordinacion/clientes/{client}/puntos-retiro', [PickupWorkflowController::class, 'point'])->middleware('fleet.permission:coordination.requests,2')->name('requests.point.save');
            Route::post('/coordinacion/solicitudes/{serviceRequest}/coordinar-reagendamiento', [PickupWorkflowController::class, 'reschedule'])->middleware('fleet.permission:operations.scheduling,2')->name('requests.requester-reschedule');
            Route::post('/coordinacion/solicitudes/{serviceRequest}/tareas/{task}/resolver', [PickupWorkflowController::class, 'resolve'])->middleware('fleet.permission:coordination.requests,2')->name('requests.task.resolve');
            Route::post('/coordinacion/solicitudes/{serviceRequest}/aviso-recepcion', [PickupWorkflowController::class, 'receipt'])->middleware('fleet.permission:coordination.requests,2')->name('requests.receipt.prepare');
            Route::post('/coordinacion/solicitudes/{serviceRequest}/avisos-cliente/{notification}', [PickupWorkflowController::class, 'send'])->middleware(['fleet.permission:coordination.requests,2', 'throttle:2,1'])->name('requests.receipt.send');
            Route::put('/administrador/avisos-retiros', [PickupWorkflowController::class, 'mailSettings'])->middleware('fleet.permission:admin.permissions,3')->name('requests.mail.settings');
            Route::get('/coordinacion/clientes/{client}/puntos', [CoordinationController::class, 'clientPoints'])
                ->middleware('fleet.permission:coordination.requests')->name('clients.points');
            Route::post('/coordinacion/solicitudes', [CoordinationController::class, 'requestStore'])
                ->middleware('fleet.permission:coordination.requests,2')->name('requests.store');
            Route::get('/coordinacion/solicitudes/{serviceRequest}', [CoordinationController::class, 'requestShow'])
                ->middleware('fleet.permission:coordination.requests')->name('requests.show');
            Route::post('/coordinacion/solicitudes/{serviceRequest}/agendar', [CoordinationController::class, 'schedule'])
                ->middleware('fleet.permission:operations.scheduling,2')->name('requests.schedule');
            Route::post('/coordinacion/solicitudes/{serviceRequest}/retirado', [CoordinationController::class, 'retire'])
                ->middleware('fleet.permission:coordination.requests,2')->name('requests.retire');
            Route::post('/coordinacion/solicitudes/{serviceRequest}/reprogramar', [CoordinationController::class, 'reprogramRequest'])
                ->middleware('fleet.permission:coordination.reprogramming.request,2')->name('requests.reprogram');
            Route::get('/coordinacion/reprogramaciones', [CoordinationController::class, 'reprogramIndex'])
                ->middleware('fleet.permission:coordination.reprogramming.approve,3')->name('reprogramming.index');
            Route::post('/coordinacion/reprogramaciones/{reprogramming}/resolver', [CoordinationController::class, 'reprogramDecide'])
                ->middleware('fleet.permission:coordination.reprogramming.approve,3')->name('reprogramming.decide');

            Route::get('/operaciones/programacion', [SchedulingController::class, 'index'])
                ->middleware('fleet.permission:operations.scheduling')->name('page.operations-scheduling');
            Route::post('/operaciones/programacion/{serviceRequest}/asignar', [SchedulingController::class, 'assign'])
                ->middleware('fleet.permission:operations.scheduling,2')->name('scheduling.assign');
            Route::get('/operaciones/programacion/{serviceRequest}/conflictos', [SchedulingController::class, 'preview'])
                ->middleware('fleet.permission:operations.scheduling,2')->name('scheduling.preview');
            Route::post('/operaciones/programacion/{serviceRequest}/sobrecarga', [SchedulingController::class, 'requestOverload'])
                ->middleware('fleet.permission:operations.scheduling,2')->name('scheduling.overload.request');
            Route::post('/operaciones/programacion/sobrecarga/{authorization}/resolver', [SchedulingController::class, 'decide'])
                ->middleware('fleet.permission:operations.scheduling.overload.approve,3')->name('scheduling.overload.decide');
            Route::put('/administrador/parametros-programacion/corte', [SchedulingController::class, 'updateCutoff'])->middleware('fleet.permission:admin.permissions,3')->name('scheduling.cutoff.update');
            Route::post('/administrador/parametros-programacion/aprobadores', [SchedulingController::class, 'addApprover'])->middleware('fleet.permission:admin.permissions,3')->name('scheduling.approvers.add');
            Route::patch('/administrador/parametros-programacion/aprobadores/{approver}', [SchedulingController::class, 'toggleApprover'])->middleware('fleet.permission:admin.permissions,3')->name('scheduling.approvers.toggle');
            Route::get('/administrador/parametros-programacion', [SchedulingController::class, 'settings'])
                ->middleware('fleet.permission:admin.permissions,3')->name('scheduling.settings');
            Route::put('/administrador/parametros-programacion', [SchedulingController::class, 'updateSettings'])
                ->middleware('fleet.permission:admin.permissions,3')->name('scheduling.settings.update');

            Route::get('/coordinacion/clientes/{client}/servicios', [CoordinationController::class, 'clientServices'])->middleware('fleet.permission:coordination.requests')->name('clients.services');
            Route::put('/coordinacion/solicitudes/{serviceRequest}', [CoordinationController::class, 'requestUpdate'])->middleware('fleet.permission:coordination.requests,2')->name('requests.update');
            Route::post('/coordinacion/solicitudes/{serviceRequest}/anular', [CoordinationController::class, 'cancel'])->middleware('fleet.permission:coordination.requests,2')->name('requests.cancel');
            foreach (['coordinacion/factibilidad' => 'coordination-feasibility', 'comercial/factibilidad' => 'commercial-feasibility', 'coordinacion/revision-financiera' => 'finance-feasibility', 'operaciones/factibilidad' => 'operations-feasibility'] as $path => $name) {
                Route::get($path, [RetFeasibilityController::class, 'index'])->name('page.'.$name);
            }
            Route::post('/coordinacion/factibilidad', [RetFeasibilityController::class, 'store'])->name('feasibility.store');
            Route::post('/coordinacion/factibilidad/reconocer', [RetFeasibilityController::class, 'recognize'])->middleware('throttle:30,1')->name('feasibility.recognize');
            Route::get('/coordinacion/factibilidad/{feasibility}', [RetFeasibilityController::class, 'show'])->name('feasibility.show');
            Route::post('/coordinacion/factibilidad/{feasibility}', [RetFeasibilityController::class, 'act'])->name('feasibility.act');
            Route::prefix('administrador/correos-microsoft')->name('microsoft-mail.')->middleware('fleet.permission:admin.permissions,3')->group(function (): void {
                Route::get('/', [MicrosoftRetMailController::class, 'index'])->name('index');
                Route::put('/', [MicrosoftRetMailController::class, 'configure'])->name('configure');
                Route::post('/conectar', [MicrosoftRetMailController::class, 'connect'])->middleware('throttle:5,1')->name('connect');
                Route::post('/comprobar', [MicrosoftRetMailController::class, 'check'])->middleware('throttle:12,1')->name('check');
                Route::post('/desconectar', [MicrosoftRetMailController::class, 'disconnect'])->name('disconnect');
                Route::post('/prueba', [MicrosoftRetMailController::class, 'send'])->middleware('throttle:2,1')->name('send');
            });
            Route::get('/administrador/ret-configuracion', [RetToolsController::class, 'settings'])->middleware('fleet.permission:admin.masters,3')->name('ret.settings');
            Route::put('/administrador/ret-configuracion', [RetToolsController::class, 'saveSettings'])->middleware('fleet.permission:admin.masters,3')->name('ret.settings.save');
            Route::put('/administrador/ret-vehiculos/{vehicle}', [RetToolsController::class, 'saveVehicle'])->middleware('fleet.permission:admin.masters,3')->name('ret.vehicles.save');
            Route::put('/administrador/ret-puntos/{branch}', [RetToolsController::class, 'savePoint'])->middleware('fleet.permission:admin.masters,3')->name('ret.points.save');
            Route::put('/administrador/ret-clientes/{client}/servicios', [RetToolsController::class, 'clientServices'])->middleware('fleet.permission:admin.masters,3')->name('ret.clients.services');
            Route::get('/administrador/ret-limpiar-pruebas', [RetToolsController::class, 'cleanup'])->name('ret.cleanup');
            Route::post('/administrador/ret-limpiar-pruebas', [RetToolsController::class, 'destroy'])->name('ret.cleanup.destroy');
            Route::post('/coordinacion/avisos/{notification}/reintentar', [RetToolsController::class, 'retry'])->middleware('fleet.permission:coordination.requests,2')->name('ret.notification.retry');

            Route::get('/administrador/maestros', [RetMerchantController::class, 'index'])->middleware('fleet.permission:admin.masters')->name('page.admin-masters');
            Route::get('/administrador/maestros/comerciantes/{client}', [RetMerchantController::class, 'show'])->middleware('fleet.permission:admin.masters')->name('merchants.show');
            Route::put('/administrador/maestros/comerciantes/{client}', [RetMerchantController::class, 'update'])->middleware('fleet.permission:admin.masters,3')->name('merchants.update');
            Route::post('/coordinacion/solicitudes/{serviceRequest}/excepcion', [CoordinationController::class, 'requestLatePickup'])->name('requests.late.request');
            Route::post('/coordinacion/solicitudes/{serviceRequest}/excepcion/decision', [CoordinationController::class, 'decideLatePickup'])->name('requests.late.decide');
            Route::post('/coordinacion/solicitudes/{serviceRequest}/decision', [CoordinationController::class, 'decision'])->middleware('fleet.permission:operations.scheduling,2')->name('requests.decision');
            foreach (FleetAccess::PAGES as $permissionCode => $page) {
                if (in_array($permissionCode, ['operations.fuel', 'admin.masters', 'operations.fleet', 'operations.maintenance', 'operations.scheduling', 'coordination.requests', 'coordination.fixed-pickups', 'coordination.feasibility', 'commercial.feasibility', 'finance.feasibility', 'operations.feasibility'], true)) {
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
