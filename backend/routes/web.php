<?php

use App\Http\Controllers\PortalController;
use App\Http\Middleware\EnsurePortalAccess;
use App\Http\Middleware\RecordUserActivity;
use Illuminate\Support\Facades\Route;

Route::get('/', [PortalController::class, 'index'])->name('login');
Route::get('/login', [PortalController::class, 'index'])->name('portal.login');
Route::post('/login', [PortalController::class, 'login'])->middleware('guest')->name('portal.login.store');
Route::middleware(['auth', 'auth.session', EnsurePortalAccess::class])->group(function (): void {
    Route::get('/inicio', [PortalController::class, 'home'])->name('portal.home');
    Route::get('/modulos/{module}', [PortalController::class, 'module'])->middleware(RecordUserActivity::class)->name('portal.module');
    Route::get('/mi-cuenta', [PortalController::class, 'profile'])->name('portal.profile');
    Route::put('/mi-cuenta', [PortalController::class, 'updateProfile'])->name('portal.profile.update');
    Route::put('/mi-cuenta/clave', [PortalController::class, 'password'])->middleware('throttle:6,1')->name('portal.password');
    Route::post('/salir', [PortalController::class, 'logout'])->name('logout');
});
