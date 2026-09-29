<?php

return [
    'process_deletion_key' => env('PROVIDER_PAYMENTS_DELETE_MASTER_KEY'),
    'billing_companies' => [
        '4N' => [
            'name' => '4 NORTES LOGISTICA SPA',
            'tax_id' => '77.346.078-7',
            'address' => 'GALVARINO 9440 A - QUILICURA',
            'email' => 'proveedores@4nlogistica.cl',
        ],
        'PMCB' => [
            'name' => 'TRANSPORTE Y DISTRIBUCION PMCB',
            'tax_id' => '77.639.015-1',
            'address' => 'EDUARDO FREI MONTALVA 9215 - QUILICURA',
            'email' => 'proveedores@4nlogistica.cl',
        ],
    ],
];
