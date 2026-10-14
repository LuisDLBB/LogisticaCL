<?php

namespace App\Fleet;

use App\Models\VehicleMaintenance;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VehicleMaintenanceReference
{
    public function forVehicles(Collection $ids, Carbon $week): Collection
    {
        $end = $week->copy()->endOfWeek();
        $parameters = DB::table('VEH_maintenance_parameters')->whereIn('vehicle_id', $ids)->whereDate('effective_from', '<=', $end->toDateString())->orderByDesc('effective_from')->orderByDesc('id')->get()->unique('vehicle_id')->keyBy('vehicle_id');
        $baselines = DB::table('VEH_maintenance_baselines')->whereIn('vehicle_id', $ids)->whereDate('observed_week', '<=', $end->toDateString())->orderByDesc('observed_week')->orderByDesc('id')->get()->unique('vehicle_id')->keyBy('vehicle_id');
        $closed = VehicleMaintenance::query()->whereIn('vehicle_id', $ids)->where('status', 'closed')->where('closed_at', '<=', $end->copy()->utc())->orderByDesc('closed_at')->orderByDesc('id')->get()->unique('vehicle_id')->keyBy('vehicle_id');

        return $ids->mapWithKeys(function ($id) use ($parameters, $baselines, $closed): array {
            $parameter = $parameters->get($id);
            $imported = $baselines->get($id);
            $maintenance = $closed->get($id);
            $baseline = $imported?->last_maintenance_km;
            $source = $baseline === null ? 'Sin mantención registrada' : $imported->source;
            if ($maintenance && (! $imported || $maintenance->closed_at->copy()->timezone('America/Santiago')->toDateString() >= $imported->observed_week)) {
                $baseline = $maintenance->closed_odometer_km;
                $source = 'Cierre de mantención #'.$maintenance->id.' · '.$maintenance->closed_at->timezone('America/Santiago')->format('d/m/Y');
            }

            return [$id => ['baseline' => $baseline, 'baseline_source' => $source, 'interval' => $parameter?->interval_km, 'parameter_id' => $parameter?->id, 'parameter_from' => $parameter?->effective_from]];
        });
    }
}
