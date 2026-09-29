<?php

namespace App\Fleet;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Illuminate\Support\Collection;

class VehicleCatalog
{
    public function owner(Vehicle $vehicle, Collection $tenants): ?Tenant
    {
        $companyTaxId = $this->normalizeTaxId($vehicle->rut_empresa);
        if ($companyTaxId === '') {
            return null;
        }

        $owner = $tenants->first(fn (Tenant $tenant): bool => $this->normalizeTaxId($tenant->tax_id) === $companyTaxId);
        if ($owner === null) {
            return null;
        }

        $source = strtoupper(trim((string) $vehicle->company_source));

        return $source === '' || $source === strtoupper($owner->code) ? $owner : null;
    }

    public function canSeeSharedPool(User $user, Tenant $serviceTenant, FleetAccess $access): bool
    {
        return $access->allows($user, $serviceTenant, 'operations.fleet')
            && $access->allows($user, $serviceTenant, 'operations.vehicle_pool');
    }

    public function canView(?Tenant $owner, Tenant $serviceTenant, bool $sharedPool, bool $canReviewUnknownOwner): bool
    {
        if ($owner === null) {
            return $canReviewUnknownOwner;
        }

        return $owner->id === $serviceTenant->id || $sharedPool;
    }

    public function isOperational(Vehicle $vehicle, ?Tenant $owner): bool
    {
        return $owner !== null
            && (bool) $vehicle->is_active
            && in_array(mb_strtolower(trim((string) $vehicle->operational_status)), ['activa', 'available'], true)
            && ! VehicleMaintenance::query()->where('vehicle_id', $vehicle->id)->where('status', 'in_progress')->exists();
    }

    private function normalizeTaxId(?string $taxId): string
    {
        return preg_replace('/[^0-9K]/i', '', strtoupper((string) $taxId)) ?? '';
    }
}
