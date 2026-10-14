<?php

namespace App\Fleet;

use App\Models\Tenant;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WeeklyVehicleControl
{
    public function vehicles(Request $request, FleetAccess $access, VehicleCatalog $catalog): Collection
    {
        $tenant = $access->tenant($request);
        $tenants = Tenant::all();
        $shared = $catalog->canSeeSharedPool($request->user(), $tenant, $access);

        return Vehicle::query()->orderBy('plate')->get()->filter(function (Vehicle $vehicle) use ($request, $tenant, $tenants, $shared, $access, $catalog): bool {
            if (in_array(mb_strtolower(trim($vehicle->operational_status ?? '')), ['robado', 'stolen', 'vendido', 'sold'], true)) {
                return false;
            }
            $owner = $catalog->owner($vehicle, $tenants);

            return $owner && $catalog->canView($owner, $tenant, $shared, false) && $access->allows($request->user(), $owner, 'operations.fleet');
        })->map(fn (Vehicle $vehicle): array => ['vehicle' => $vehicle, 'owner' => $catalog->owner($vehicle, $tenants)])->values();
    }

    public function rows(Collection $vehicles, Carbon $week, Request $request, FleetAccess $access): Collection
    {
        $ids = $vehicles->pluck('vehicle.id');
        $readings = DB::table('VEH_weekly_readings')->whereIn('vehicle_id', $ids)->whereDate('week_start', '<=', $week->toDateString())->orderByDesc('week_start')->orderByDesc('id')->get()->groupBy('vehicle_id');
        $references = app(VehicleMaintenanceReference::class)->forVehicles($ids, $week);
        $workshopJobs = VehicleMaintenance::query()->whereIn('vehicle_id', $ids)->where('status', 'in_progress')->orderBy('started_at')->get()->groupBy('vehicle_id');
        $inWorkshop = $workshopJobs->keys()->all();
        $statusEvents = DB::table('VEH_vehicle_status_events')->whereIn('vehicle_id', $ids)->orderByDesc('id')->get()->groupBy('vehicle_id');
        $workshopStatuses = ['maintenance', 'in_maintenance', 'mantencion', 'mantención', 'en mantención', 'en mantencion', 'repair', 'in_repair', 'reparacion', 'reparación', 'en reparación', 'en reparacion', 'taller', 'en taller'];
        $settings = DB::table('fleet_maintenance_settings')->get()->keyBy('tenant_id');

        return $vehicles->map(function (array $entry) use ($readings, $references, $inWorkshop, $workshopJobs, $statusEvents, $workshopStatuses, $settings, $request, $access, $week): array {
            $v = $entry['vehicle'];
            $owner = $entry['owner'];
            $history = $readings->get($v->id, collect());
            $reading = $history->first();
            $reference = $references->get($v->id);
            $baseline = $reference['baseline'];
            $interval = $reference['interval'];
            $due = $baseline !== null && $interval !== null ? $baseline + $interval : null;
            $status = mb_strtolower(trim($v->operational_status ?? ''));
            $inWorkshopNow = in_array($v->id, $inWorkshop, true) || in_array($status, $workshopStatuses, true);
            $workshopSnapshot = null;
            $cutoff = null;
            if ($inWorkshopNow) {
                $entryEvent = $statusEvents->get($v->id, collect())->first(function ($event) use ($workshopStatuses): bool {
                    $before = json_decode($event->before, true);
                    $after = json_decode($event->after, true);

                    return in_array(mb_strtolower(trim($after['operational_status'] ?? '')), $workshopStatuses, true)
                        && ! in_array(mb_strtolower(trim($before['operational_status'] ?? '')), $workshopStatuses, true);
                });
                if ($entryEvent) {
                    $cutoff = Carbon::parse($entryEvent->created_at, 'UTC');
                    $workshopSnapshot = json_decode($entryEvent->before, true)['odometer_km'] ?? null;
                }
                $job = $workshopJobs->get($v->id, collect())->first(fn ($job): bool => $job->started_at !== null);
                if ($job && ($cutoff === null || $job->started_at->lt($cutoff))) {
                    $cutoff = $job->started_at;
                    $workshopSnapshot = $job->reported_odometer_km;
                }
                $reading = $history->first(function ($candidate) use ($cutoff): bool {
                    if (! $candidate->accepted || $candidate->odometer_km <= 0) {
                        return false;
                    }

                    return $cutoff === null || Carbon::parse($candidate->created_at, 'UTC')->lte($cutoff)
                        || (str_starts_with($candidate->source_key ?? '', 'weekly-baseline-2026:') && $candidate->week_start <= $cutoff->toDateString());
                });
            }
            if ($cutoff !== null && $cutoff->copy()->startOfWeek()->gt($week)) {
                $workshopSnapshot = null;
            }
            $current = $inWorkshopNow
                ? (($workshopSnapshot !== null && $workshopSnapshot > 0) ? $workshopSnapshot : ($reading?->odometer_km ?? ($cutoff === null ? $v->odometer_km : null)))
                : ($reading?->odometer_km ?? ($week->isSameWeek(today('America/Santiago')) ? $v->odometer_km : null));
            $issues = $reading ? json_decode($reading->issues ?? '[]', true) : [];
            $valid = $current !== null && $current > 0 && (! $reading || $reading->accepted) && ($baseline === null || $current >= $baseline);
            $remaining = $valid && $due !== null ? $due - $current : null;
            $blocked = ! $v->is_active || ! in_array($status, ['activa', 'available'], true) || in_array($v->id, $inWorkshop, true);
            $warning = $settings->get($owner->id)?->warning_km ?? 1000;
            $level = 'green';
            $label = 'Con margen';
            if ($blocked) {
                $level = 'red';
                $label = 'No operativo: '.(in_array($v->id, $inWorkshop, true) ? 'En mantención' : (! $v->is_active ? 'Inactivo' : ($v->operational_status ?: 'Inactivo')));
            } elseif (! $valid) {
                $level = 'yellow';
                $label = 'Verificar kilometraje';
            } elseif ($remaining === null) {
                $level = 'yellow';
                $label = 'Completar intervalo / última mantención';
            } elseif ($remaining <= 0) {
                $level = 'red';
                $label = 'Mantención vencida';
            } elseif ($remaining <= $warning) {
                $level = 'yellow';
                $label = 'Programar mantención';
            }
            $forecast = null;
            $averageDailyKm = null;
            $accepted = $history->filter(fn ($r): bool => $r->accepted && $r->odometer_km > 0)->unique('week_start')->values();
            if ($valid && $remaining !== null && $remaining > 0 && $accepted->count() >= 2) {
                $new = $accepted[0];
                $old = $accepted[1];
                $days = Carbon::parse($old->week_start)->diffInDays(Carbon::parse($new->week_start));
                $distance = $new->odometer_km - $old->odometer_km;
                if ($days > 0 && $distance > 0) {
                    $averageDailyKm = $distance / $days;
                    $forecast = Carbon::parse($new->week_start, 'America/Santiago')->addDays((int) ceil($remaining / $averageDailyKm));
                }
            }

            if (! $blocked && $valid && $level === 'green' && $forecast !== null && $forecast->lessThanOrEqualTo($week->copy()->endOfWeek())) {
                $level = 'yellow';
                $label = 'Programar esta semana';
            }

            $weeklyMissing = ! $reading || $reading->week_start !== $week->toDateString();
            $projectedRemaining = ! $weeklyMissing && $valid && $remaining !== null && $averageDailyKm !== null
                ? (int) floor($remaining - ($averageDailyKm * 7)) : null;
            $maintenanceNeeded = ! $weeklyMissing && $valid && $remaining !== null
                && ($remaining <= 1500 || ($projectedRemaining !== null && $projectedRemaining <= 1500)
                    || $remaining <= $warning);
            if ($maintenanceNeeded && $level === 'green') {
                $level = 'yellow';
                $label = 'Programar próxima semana';
            }
            $captureRequired = ! $maintenanceNeeded && ($weeklyMissing || ! $valid || $remaining === null || $blocked);

            return [...$entry, 'in_workshop' => $inWorkshopNow, 'reading' => $reading, 'baseline' => $baseline, 'baseline_source' => $reference['baseline_source'], 'interval' => $interval, 'current' => $current, 'due' => $due, 'remaining' => $remaining, 'issues' => $issues, 'level' => $level, 'label' => $label, 'forecast' => $forecast, 'weekly_missing' => $weeklyMissing, 'projected_remaining' => $projectedRemaining, 'average_weekly_km' => $averageDailyKm !== null ? (int) round($averageDailyKm * 7) : null, 'maintenance_needed' => $maintenanceNeeded, 'capture_required' => $captureRequired, 'can_edit' => $access->allows($request->user(), $owner, 'operations.fleet', 2), 'can_plan' => $access->allows($request->user(), $owner, 'operations.maintenance', 2)];
        });
    }
}
