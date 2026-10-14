<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\FleetAccess;
use App\Fleet\MaintenanceService;
use App\Fleet\VehicleCatalog;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class VehicleController extends Controller
{
    public function index(Request $request, FleetAccess $access, VehicleCatalog $catalog): View
    {
        $serviceTenant = $access->tenant($request);
        $tenants = Tenant::query()->get();
        $sharedPool = $catalog->canSeeSharedPool($request->user(), $serviceTenant, $access);
        $canReviewUnknownOwner = $access->allows($request->user(), $serviceTenant, 'operations.vehicle_pool', 3);
        $companyCodes = $sharedPool ? $tenants->pluck('code')->all() : [$serviceTenant->code];
        $company = $request->query('empresa', $sharedPool ? 'all' : $serviceTenant->code);
        abort_unless(in_array($company, array_merge($companyCodes, $sharedPool ? ['all'] : []), true), 403);

        $filters = $request->validate([
            'estado' => ['nullable', 'string', 'max:30'],
            'tipo' => ['nullable', 'string', 'max:50'],
            'patente' => ['nullable', 'string', 'max:50'],
            'pool' => ['nullable', 'in:1'],
        ]);

        $visibleVehicles = Vehicle::query()->orderBy('plate')->get()
            ->map(function (Vehicle $vehicle) use ($catalog, $tenants): array {
                $owner = $catalog->owner($vehicle, $tenants);

                return [
                    'vehicle' => $vehicle,
                    'owner' => $owner,
                    'operational' => $catalog->isOperational($vehicle, $owner),
                    'in_maintenance' => VehicleMaintenance::query()->where('vehicle_id', $vehicle->id)->where('status', 'in_progress')->exists(),
                    'planned_maintenance' => VehicleMaintenance::query()->where('vehicle_id', $vehicle->id)->whereIn('status', ['pending', 'scheduled'])->exists(),
                ];
            })
            ->filter(fn (array $row): bool => $catalog->canView($row['owner'], $serviceTenant, $sharedPool, $canReviewUnknownOwner))
            ->values();

        $statuses = $visibleVehicles->pluck('vehicle.operational_status')->filter()->unique()->sort()->values();
        $types = $visibleVehicles->pluck('vehicle.vehicle_type')->filter()->unique()->sort()->values();
        $search = mb_strtoupper(trim($filters['patente'] ?? ''));
        $rows = $visibleVehicles
            ->filter(fn (array $row): bool => $company === 'all' || $row['owner']?->code === $company)
            ->filter(fn (array $row): bool => empty($filters['estado']) || $row['vehicle']->operational_status === $filters['estado'])
            ->filter(fn (array $row): bool => empty($filters['tipo']) || $row['vehicle']->vehicle_type === $filters['tipo'])
            ->filter(fn (array $row): bool => $search === '' || str_contains(mb_strtoupper($row['vehicle']->plate.' '.$row['vehicle']->internal_code), $search))
            ->filter(fn (array $row): bool => ! $request->boolean('pool') || $row['operational'])
            ->values();

        return view('fleet::vehicles.index', [
            'serviceTenant' => $serviceTenant,
            'company' => $company,
            'companyCodes' => $companyCodes,
            'sharedPool' => $sharedPool,
            'filters' => $filters,
            'statuses' => $statuses,
            'types' => $types,
            'rows' => $rows,
            'totalCount' => $visibleVehicles->count(),
            'operationalCount' => $visibleVehicles->where('operational', true)->count(),
        ]);
    }

    public function show(Request $request, Vehicle $vehicle, FleetAccess $access, VehicleCatalog $catalog, MaintenanceService $maintenanceService): View
    {
        $serviceTenant = $access->tenant($request);
        $owner = $catalog->owner($vehicle, Tenant::query()->get());
        $sharedPool = $catalog->canSeeSharedPool($request->user(), $serviceTenant, $access);
        $canReviewUnknownOwner = $access->allows($request->user(), $serviceTenant, 'operations.vehicle_pool', 3);
        abort_unless($catalog->canView($owner, $serviceTenant, $sharedPool, $canReviewUnknownOwner), 404);

        $canReadMaintenance = $access->allows($request->user(), $serviceTenant, 'operations.maintenance');
        $maintenanceTenantIds = $sharedPool
            ? Tenant::query()->get()->filter(fn (Tenant $tenant): bool => $access->allows($request->user(), $tenant, 'operations.maintenance'))->pluck('id')
            : collect([$serviceTenant->id]);
        $maintenances = $canReadMaintenance
            ? $vehicle->maintenances()->with(['tenant', 'provider', 'responsible'])
                ->whereIn('tenant_id', $maintenanceTenantIds)
                ->orderByDesc('scheduled_at')->orderByDesc('id')->get()
            : collect();
        $latestClosed = $maintenances->where('status', 'closed')->sortByDesc('closed_at')->unique('maintenance_type');
        $settings = DB::table('fleet_maintenance_settings')->get()->keyBy('tenant_id');

        return view('fleet::vehicles.show', [
            'vehicle' => $vehicle,
            'owner' => $owner,
            'serviceTenant' => $serviceTenant,
            'operational' => $catalog->isOperational($vehicle, $owner),
            'inMaintenance' => VehicleMaintenance::query()->where('vehicle_id', $vehicle->id)->where('status', 'in_progress')->exists(),
            'plannedMaintenance' => VehicleMaintenance::query()->where('vehicle_id', $vehicle->id)->whereIn('status', ['pending', 'scheduled'])->exists(),
            'maintenances' => $maintenances,
            'latestClosedIds' => $latestClosed->pluck('id')->all(),
            'maintenanceSettings' => $settings,
            'maintenanceService' => $maintenanceService,
            'canReadMaintenance' => $canReadMaintenance,
            'canCreateMaintenance' => $owner && $canReadMaintenance && $access->allows($request->user(), $owner, 'operations.maintenance', 2),
        ]);
    }
}
