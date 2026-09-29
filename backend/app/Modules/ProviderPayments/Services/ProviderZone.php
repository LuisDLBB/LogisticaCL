<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\Provider;

class ProviderZone
{
    public static function isDsGroup(?string $taxId): bool
    {
        return preg_replace('/[^0-9]/', '', (string) $taxId) === '772015259';
    }

    public static function resolve(?string $taxId, ?int $providerId, ?string $zone): ?string
    {
        if (self::isDsGroup($taxId)) {
            return 'RM';
        }

        if ($providerId !== null) {
            $providerTaxId = Provider::query()->whereKey($providerId)->value('tax_id');

            if (self::isDsGroup($providerTaxId)) {
                return 'RM';
            }
        }

        return $zone;
    }
}
