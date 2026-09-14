<?php

namespace App\Modules\ProviderPayments\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ProviderPaymentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'provider-payments');

        Route::middleware('web')
            ->group(__DIR__.'/../routes/web.php');
    }
}
