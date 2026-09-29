<?php

namespace App\Modules\ProviderPayments\Providers;

use App\Http\Middleware\EnsurePortalAccess;
use App\Http\Middleware\RecordUserActivity;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ProviderPaymentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'provider-payments');

        Route::middleware(['web', 'auth', 'auth.session', EnsurePortalAccess::class, RecordUserActivity::class])
            ->group(__DIR__.'/../routes/web.php');
    }
}
