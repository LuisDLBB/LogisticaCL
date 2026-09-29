<?php

use App\Modules\Fleet\Providers\FleetServiceProvider;
use App\Modules\ProviderPayments\Providers\ProviderPaymentsServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    ProviderPaymentsServiceProvider::class,
    FleetServiceProvider::class,
];
