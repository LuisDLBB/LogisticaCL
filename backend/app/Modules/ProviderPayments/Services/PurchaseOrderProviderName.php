<?php

namespace App\Modules\ProviderPayments\Services;

class PurchaseOrderProviderName
{
    public static function display(?string $rut, ?string $snapshot): string
    {
        $key = preg_replace('/[^0-9K]/', '', strtoupper((string) $rut));

        return $key === '773907617' ? 'TRANSPORTE BAG SPA' : (string) $snapshot;
    }
}
