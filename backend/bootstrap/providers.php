<?php

use App\Providers\AppServiceProvider;
use App\Modules\ProviderPayments\Providers\ProviderPaymentsServiceProvider;

return [
    AppServiceProvider::class,
    ProviderPaymentsServiceProvider::class,
];
