<?php

namespace App\Modules\Operations\Providers;

use App\Http\Middleware\EnsureOperationsStaff;
use App\Http\Middleware\EnsurePortalAccess;
use App\Http\Middleware\RecordUserActivity;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class OperationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        DevCommands::artisan('queue:work operations --queue=operations --sleep=1 --tries=1 --timeout=1200 --memory=512', 'operaciones');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'operations');
        Route::middleware(['web', 'auth', 'auth.session', EnsurePortalAccess::class, RecordUserActivity::class])->group(function (): void {
            Route::middleware(EnsureOperationsStaff::class)->group(__DIR__.'/../routes/web.php');
            Route::group([], __DIR__.'/../routes/driver.php');
        });
    }
}
