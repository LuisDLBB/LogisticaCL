<?php

use App\Modules\Fleet\Providers\FleetServiceProvider;
use App\Modules\Operations\Providers\OperationsServiceProvider;
use App\Modules\ProviderPayments\Providers\ProviderPaymentsServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    ProviderPaymentsServiceProvider::class,
    OperationsServiceProvider::class,
    FleetServiceProvider::class,
];
