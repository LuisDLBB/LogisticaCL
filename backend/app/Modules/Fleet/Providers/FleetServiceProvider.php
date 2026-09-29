<?php

namespace App\Modules\Fleet\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FleetServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'fleet');

        Route::middleware('web')->group(__DIR__.'/../routes/web.php');
    }
}
