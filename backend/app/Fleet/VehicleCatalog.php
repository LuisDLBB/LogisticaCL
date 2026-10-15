<?php

namespace App\Fleet;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Illuminate\Support\Collection;

class VehicleCatalog
{
    public function __construct(private VehicleBoardAlerts $alerts) {}

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
        return $this->unavailabilityReason($vehicle, $owner) === null;
    }

    public function unavailabilityReason(Vehicle $vehicle, ?Tenant $owner): ?string
    {
        if ($owner === null) {
            return 'Empresa del vehículo pendiente de verificar';
        }
        if (! $vehicle->is_active) {
            return 'Vehículo inactivo';
        }
        if (VehicleMaintenance::query()->where('vehicle_id', $vehicle->id)->where('status', 'in_progress')->exists()) {
            return 'En mantención';
        }
        $status = mb_strtolower(trim((string) $vehicle->operational_status));
        if (in_array($status, ['activa', 'available'], true)) {
            return collect($this->alerts->paperwork($vehicle))->where('expired', true)->isNotEmpty() ? 'Papeles vencidos' : null;
        }

        return match ($status) {
            'maintenance', 'in_maintenance', 'mantencion', 'mantención', 'en mantención', 'en mantencion' => 'En mantención',
            'repair', 'in_repair', 'reparacion', 'reparación', 'en reparación', 'en reparacion' => 'En reparación',
            'out_of_service', 'fuera de servicio' => 'Fuera de servicio',
            default => filled($vehicle->operational_status) ? $vehicle->operational_status : 'Disponibilidad pendiente de confirmar',
        };
    }

    private function normalizeTaxId(?string $taxId): string
    {
        return preg_replace('/[^0-9K]/i', '', strtoupper((string) $taxId)) ?? '';
    }
}
