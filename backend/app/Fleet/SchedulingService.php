<?php

namespace App\Fleet;

use App\Models\ServiceRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SchedulingService
{
    public function __construct(private readonly VehicleCatalog $catalog, private readonly CoordinationService $coordination, private readonly RetNotificationService $notifications) {}

    public function isDriver(User $user): bool
    {
        $profile = Str::of((string) $user->profile_name)->ascii()->lower()->squish()->toString();

        $roles = DB::table('MBA_tenant_users')->join('MBA_tenants', 'MBA_tenants.id', '=', 'MBA_tenant_users.tenant_id')
            ->join('fleet_membership_profiles', 'fleet_membership_profiles.tenant_user_id', '=', 'MBA_tenant_users.id')
            ->join('fleet_profiles', 'fleet_profiles.id', '=', 'fleet_membership_profiles.fleet_profile_id')
            ->where('MBA_tenant_users.user_id', $user->id)->where('MBA_tenant_users.is_active', true)
            ->where('fleet_membership_profiles.is_active', true)->where('fleet_profiles.is_active', true)
            ->where('MBA_tenants.is_active', true)->pluck('fleet_profiles.code');

        return $roles->isNotEmpty() && (in_array($profile, ['conductor', 'conductor a5', 'app conductor'], true)
            || $roles->contains(fn ($role): bool => in_array(str_replace('_', ' ', Str::lower($role)), ['conductor', 'conductor a5', 'app conductor'], true)));
    }

    public function drivers(): Collection
    {
        return User::query()->orderBy('name')->get()->filter(fn (User $user): bool => $this->isDriver($user))->values();
    }

    public function pool(): Collection
    {
        $tenants = Tenant::query()->whereIn('code', ['4N', 'PMCB'])->get();

        return Vehicle::query()->orderBy('plate')->get()->map(function (Vehicle $vehicle) use ($tenants): array {
            $owner = $this->catalog->owner($vehicle, $tenants);
            $planned = VehicleMaintenance::query()->where('vehicle_id', $vehicle->id)
                ->whereIn('status', ['pending', 'scheduled'])->orderBy('scheduled_at')->get();

            $unavailabilityReason = $this->catalog->unavailabilityReason($vehicle, $owner);

            return [
                'vehicle' => $vehicle, 'owner' => $owner,
                'selectable' => $unavailabilityReason === null,
                'unavailability_reason' => $unavailabilityReason,
                'planned_maintenances' => $planned,
            ];
        })->filter(fn (array $row): bool => $row['owner'] !== null)->values();
    }

    public function limit(int $tenantId): int
    {
        return (int) (DB::table('fleet_scheduling_settings')->where('tenant_id', $tenantId)->value('max_concurrent_pickups') ?? 3);
    }

    public function concurrences(ServiceRequest $ret, int $driverId, int $vehicleId, string $date, string $start, string $end): array
    {
        $base = ServiceRequest::query()->where('id', '!=', $ret->id)->whereDate('service_date', $date)
            ->whereIn('status', ['requested', 'scheduled', 'reprogramming_pending', 'rescheduled'])
            ->where('window_start', '<', $this->time($end))->where('window_end', '>', $this->time($start));

        return [
            'driver' => (clone $base)->where('driver_user_id', $driverId)->count() + 1,
            'vehicle' => (clone $base)->where('vehicle_id', $vehicleId)->count() + 1,
        ];
    }

    public function validateResources(int $driverId, int $vehicleId): array
    {
        $driver = User::query()->find($driverId);
        if ($driver === null || ! $this->isDriver($driver)) {
            throw ValidationException::withMessages(['driver_user_id' => 'Selecciona un conductor habilitado.']);
        }
        $vehicle = Vehicle::query()->find($vehicleId);
        $owner = $vehicle ? $this->catalog->owner($vehicle, Tenant::query()->whereIn('code', ['4N', 'PMCB'])->get()) : null;
        if ($vehicle === null || ! $this->catalog->isOperational($vehicle, $owner)) {
            throw ValidationException::withMessages(['vehicle_id' => $vehicle ? $vehicle->plate.' no se puede asignar: '.$this->catalog->unavailabilityReason($vehicle, $owner).'.' : 'Selecciona un vehículo operativo del pool 4N / PMCB.']);
        }

        $eligibility = app(RetVehicleEligibility::class);
        if ($eligibility->required($vehicle) === null) {
            throw ValidationException::withMessages(['vehicle_id' => 'Falta verificar el tipo o peso bruto del vehículo en RET Contactos y correos.']);
        }
        if (! $eligibility->compatible($driver, $vehicle)) {
            throw ValidationException::withMessages(['vehicle_id' => 'La licencia del conductor no habilita este vehículo. Revisa la clase registrada.']);
        }

        return [$driver, $vehicle];
    }

    public function assertCapacity(ServiceRequest $ret, int $driverId, int $vehicleId, string $date, string $start, string $end, bool $weightWarningAcknowledged = false): array
    {
        if (! in_array($ret->status, ['scheduled', 'rescheduled'], true)) {
            app(LatePickupAuthorization::class)->assertAllowed($ret, $date);
        }
        app(RetVehicleEligibility::class)->assertLoad($ret, Vehicle::findOrFail($vehicleId), $weightWarningAcknowledged);
        $this->acquireCapacityLock();
        $counts = $this->concurrences($ret, $driverId, $vehicleId, $date, $start, $end);
        $limit = $this->limit($ret->tenant_id);
        if ($counts['driver'] <= $limit && $counts['vehicle'] <= $limit) {
            return $counts;
        }
        $approved = DB::table('service_request_overload_authorizations')
            ->where('service_request_id', $ret->id)->where('driver_user_id', $driverId)
            ->where('vehicle_id', $vehicleId)->where('service_date', $date)
            ->where('window_start', $this->time($start))->where('window_end', $this->time($end))
            ->where('status', 'approved')->where('limit_snapshot', $limit)
            ->where('driver_concurrences', '>=', $counts['driver'])
            ->where('vehicle_concurrences', '>=', $counts['vehicle'])->exists();
        if (! $approved) {
            throw ValidationException::withMessages(['overload' => "La asignación llega a {$counts['driver']} coincidencias del conductor y {$counts['vehicle']} del vehículo (límite {$limit}). Solicita autorización a jefatura."]);
        }

        return $counts;
    }

    public function assign(ServiceRequest $ret, User $actor, int $driverId, int $vehicleId, ?string $reason, bool $weightWarningAcknowledged = false): void
    {
        DB::transaction(function () use ($ret, $actor, $driverId, $vehicleId, $reason, $weightWarningAcknowledged): void {
            $current = ServiceRequest::query()->lockForUpdate()->findOrFail($ret->id);
            if (! in_array($current->status, ['requested', 'scheduled', 'rescheduled'], true)) {
                throw ValidationException::withMessages(['ret' => 'Este RET no permite cambiar su asignación.']);
            }
            $previous = ['driver_user_id' => $current->driver_user_id, 'vehicle_id' => $current->vehicle_id];
            if ($current->status !== 'requested' && ($previous['driver_user_id'] !== $driverId || $previous['vehicle_id'] !== $vehicleId) && blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'Indica el motivo del cambio posterior al agendamiento.']);
            }
            $this->validateResources($driverId, $vehicleId);
            $date = $current->service_date->toDateString();
            $this->assertCapacity($current, $driverId, $vehicleId, $date, $current->window_start, $current->window_end, $weightWarningAcknowledged);
            if ($previous['driver_user_id'] === $driverId && $previous['vehicle_id'] === $vehicleId) {
                return;
            }
            DB::table('service_request_overload_authorizations')->where('service_request_id', $current->id)
                ->where(function ($query) use ($driverId, $vehicleId): void {
                    $query->where('driver_user_id', '!=', $driverId)->orWhere('vehicle_id', '!=', $vehicleId);
                })->whereIn('status', ['pending', 'approved'])->update(['status' => 'expired', 'updated_at' => now()]);
            $current->update([
                'driver_user_id' => $driverId, 'vehicle_id' => $vehicleId,
                'assigned_by_user_id' => $actor->id, 'assigned_at' => now(),
            ]);
            $this->coordination->event($current, $actor, 'assignment_changed', [
                'previous' => $previous, 'new' => ['driver_user_id' => $driverId, 'vehicle_id' => $vehicleId],
                'reason' => $reason,
            ]);
            if (in_array($current->status, ['scheduled', 'rescheduled'], true)) {
                $this->prepareEmail($current);
            }
        }, 3);
    }

    public function requestOverload(ServiceRequest $ret, User $actor, int $driverId, int $vehicleId, string $date, string $start, string $end, string $reason): void
    {
        DB::transaction(function () use ($ret, $actor, $driverId, $vehicleId, $date, $start, $end, $reason): void {
            $current = ServiceRequest::query()->lockForUpdate()->findOrFail($ret->id);
            $this->validateResources($driverId, $vehicleId);
            $this->acquireCapacityLock();
            $counts = $this->concurrences($current, $driverId, $vehicleId, $date, $start, $end);
            $limit = $this->limit($current->tenant_id);
            if ($counts['driver'] <= $limit && $counts['vehicle'] <= $limit) {
                throw ValidationException::withMessages(['overload' => 'Esta combinación no supera el límite actual. Puedes asignarla directamente.']);
            }
            DB::table('service_request_overload_authorizations')->where('service_request_id', $current->id)
                ->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);
            DB::table('service_request_overload_authorizations')->insert([
                'service_request_id' => $current->id, 'driver_user_id' => $driverId, 'vehicle_id' => $vehicleId,
                'service_date' => $date, 'window_start' => $this->time($start), 'window_end' => $this->time($end),
                'driver_concurrences' => $counts['driver'], 'vehicle_concurrences' => $counts['vehicle'],
                'limit_snapshot' => $limit, 'reason' => $reason, 'status' => 'pending',
                'requested_by_user_id' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->coordination->event($current, $actor, 'overload_requested', [
                'driver_user_id' => $driverId, 'vehicle_id' => $vehicleId, 'date' => $date,
                'window_start' => $start, 'window_end' => $end, 'counts' => $counts, 'reason' => $reason,
            ]);
        }, 3);
    }

    public function schedule(ServiceRequest $ret, User $actor, bool $weightWarningAcknowledged = false): void
    {
        DB::transaction(function () use ($ret, $actor, $weightWarningAcknowledged): void {
            $current = ServiceRequest::query()->lockForUpdate()->findOrFail($ret->id);
            if ($current->status !== 'requested') {
                throw ValidationException::withMessages(['ret' => 'Solo se puede agendar un RET solicitado.']);
            }
            $required = [
                'fecha vigente' => $current->service_date, 'dirección efectiva' => $current->effective_address,
                'jornada' => $current->shift, 'inicio de ventana' => $current->window_start,
                'fin de ventana' => $current->window_end, 'conductor' => $current->driver_user_id,
                'vehículo/patente' => $current->vehicle_id, 'cantidad de bultos' => $current->packages,
            ];
            $missing = array_keys(array_filter($required, fn ($value): bool => blank($value)));
            if ($missing !== []) {
                throw ValidationException::withMessages(['ret' => 'Faltan: '.implode(', ', $missing).'.']);
            }
            if ((int) $current->packages < 1) {
                throw ValidationException::withMessages(['packages' => 'La cantidad de bultos debe ser mayor que cero.']);
            }
            if ($this->time($current->window_start) >= $this->time($current->window_end)) {
                throw ValidationException::withMessages(['window_end' => 'La ventana debe terminar después de su inicio.']);
            }
            $this->validateResources($current->driver_user_id, $current->vehicle_id);
            $this->assertCapacity($current, $current->driver_user_id, $current->vehicle_id, $current->service_date->toDateString(), $current->window_start, $current->window_end, $weightWarningAcknowledged);
            $current->update(['status' => 'scheduled', 'scheduled_at' => now()]);
            $this->prepareEmail($current);
            $this->coordination->event($current, $actor, 'scheduled', [
                'driver_user_id' => $current->driver_user_id, 'vehicle_id' => $current->vehicle_id,
            ]);
        }, 3);
    }

    public function prepareEmail(ServiceRequest $ret): void
    {
        $this->notifications->prepare($ret);
    }

    private function time(string $value): string
    {
        return substr($value, 0, 5).':00';
    }

    private function acquireCapacityLock(): void
    {
        $id = DB::table('fleet_scheduling_settings')->min('id');
        if ($id !== null) {
            DB::table('fleet_scheduling_settings')->where('id', $id)->update(['updated_at' => now()]);
        }
    }
}
