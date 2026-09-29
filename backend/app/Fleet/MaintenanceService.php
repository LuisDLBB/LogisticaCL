<?php

namespace App\Fleet;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Models\VehicleMaintenanceEvent;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class MaintenanceService
{
    public const STATUSES = [
        'pending' => 'Pendiente',
        'scheduled' => 'Agendada',
        'in_progress' => 'En mantención',
        'closed' => 'Cerrada',
        'cancelled' => 'Cancelada',
    ];

    public const TRANSITIONS = [
        'pending' => ['scheduled', 'cancelled'],
        'scheduled' => ['pending', 'in_progress', 'cancelled'],
        'in_progress' => ['closed', 'cancelled'],
        'closed' => [],
        'cancelled' => [],
    ];

    public function event(VehicleMaintenance $maintenance, ?User $actor, string $type, array $details = [], ?string $before = null, ?string $after = null, ?int $odometer = null, ?CarbonInterface $recordedAt = null): void
    {
        VehicleMaintenanceEvent::create([
            'vehicle_maintenance_id' => $maintenance->id,
            'tenant_id' => $maintenance->tenant_id,
            'actor_user_id' => $actor?->id,
            'event_type' => $type,
            'previous_status' => $before,
            'new_status' => $after,
            'odometer_km' => $odometer,
            'recorded_at' => $recordedAt,
            'details' => $details,
            'created_at' => now(),
        ]);
    }

    public function recordReading(VehicleMaintenance $maintenance, User $actor, int $kilometers, CarbonInterface $readingAt): bool
    {
        $vehicle = Vehicle::query()->findOrFail($maintenance->vehicle_id);
        $lastReading = DB::table('vehicle_maintenance_events as events')
            ->join('vehicle_maintenances as maintenances', 'maintenances.id', '=', 'events.vehicle_maintenance_id')
            ->where('maintenances.vehicle_id', $vehicle->id)
            ->where('events.event_type', 'odometer')
            ->orderByDesc('events.recorded_at')
            ->orderByDesc('events.id')
            ->value('events.recorded_at');

        $isCurrent = ($lastReading === null || $readingAt->greaterThanOrEqualTo($lastReading))
            && ($vehicle->odometer_km === null || $kilometers >= $vehicle->odometer_km);
        $this->event($maintenance, $actor, 'odometer', [
            'previous_vehicle_odometer_km' => $vehicle->odometer_km,
            'updated_vehicle_odometer' => $isCurrent,
            'reason' => $isCurrent ? null : 'Lectura menor o anterior; conservada sin reducir el valor actual',
        ], odometer: $kilometers, recordedAt: $readingAt);

        if ($isCurrent) {
            $vehicle->update(['odometer_km' => $kilometers]);
        }

        return $isCurrent;
    }

    public function syncNextDate(int $vehicleId): void
    {
        $latestPerType = VehicleMaintenance::query()->where('vehicle_id', $vehicleId)
            ->where('status', 'closed')->orderByDesc('closed_at')->orderByDesc('id')->get()
            ->unique('maintenance_type');
        $nextDate = $latestPerType->pluck('next_due_at')->filter()->min();
        Vehicle::query()->whereKey($vehicleId)->update(['next_maintenance_at' => $nextDate?->toDateString()]);
    }

    public function alert(VehicleMaintenance $maintenance, ?int $currentKm, int $warningDays, int $warningKm): array
    {
        if ($maintenance->status === 'cancelled') {
            return ['level' => 'none', 'label' => 'Cancelada', 'missing_reading' => false];
        }
        $dueDate = $maintenance->status === 'closed' ? $maintenance->next_due_at : $maintenance->scheduled_at;
        $dueKm = $maintenance->next_due_km;
        $missingReading = $dueKm !== null && $currentKm === null;
        $days = $dueDate ? Carbon::parse($dueDate)->startOfDay()->diffInDays(today(), false) : null;
        $kmRemaining = $dueKm !== null && $currentKm !== null ? $dueKm - $currentKm : null;

        if (($days !== null && $days > 0) || ($kmRemaining !== null && $kmRemaining <= 0)) {
            return ['level' => 'overdue', 'label' => 'Vencida', 'missing_reading' => $missingReading];
        }
        if (($days !== null && $days >= -$warningDays) || ($kmRemaining !== null && $kmRemaining <= $warningKm)) {
            return ['level' => 'upcoming', 'label' => 'Próxima', 'missing_reading' => $missingReading];
        }

        return ['level' => 'none', 'label' => $missingReading ? 'Sin lectura' : 'Sin alerta', 'missing_reading' => $missingReading];
    }
}
