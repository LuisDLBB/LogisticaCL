<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\BoardMaintenanceClosing;
use App\Fleet\ExpiredVehicleDocumentsExcel;
use App\Fleet\FleetAccess;
use App\Fleet\MaintenanceService;
use App\Fleet\VehicleBoardAlerts;
use App\Fleet\VehicleCatalog;
use App\Fleet\VehicleDocuments;
use App\Fleet\VehicleMaintenanceReference;
use App\Fleet\WeeklyVehicleControl;
use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\Tenant;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VehicleController extends Controller
{
    public function __construct(private VehicleBoardAlerts $alerts) {}

    public function index(Request $request, FleetAccess $access, VehicleCatalog $catalog, WeeklyVehicleControl $control): View
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

        $today = today('America/Santiago');
        $kmWeek = $today->copy()->startOfWeek();
        $kmRows = $control->rows($control->vehicles($request, $access, $catalog), $kmWeek, $request, $access)->keyBy('vehicle.id');
        $bookedVehicleIds = ServiceRequest::query()
            ->whereDate('service_date', $today)
            ->whereIn('status', ['scheduled', 'rescheduled'])
            ->whereNotNull('vehicle_id')->pluck('vehicle_id');
        $agendaTenantIds = $tenants->filter(fn ($entry) => $entry->id === $serviceTenant->id || ($sharedPool && $access->allows($request->user(), $entry, 'operations.scheduling')))->pluck('id');
        $todayServices = ServiceRequest::with(['driver', 'serviceType'])->whereIn('tenant_id', $agendaTenantIds)->whereDate('service_date', $today)->whereIn('status', ['scheduled', 'rescheduled'])->whereNotNull('vehicle_id')->orderBy('window_start')->get()->groupBy('vehicle_id');
        $assignedCounts = ServiceRequest::query()->whereDate('service_date', '>=', $today)->whereIn('status', ['scheduled', 'rescheduled', 'reprogramming_pending'])->whereNotNull('vehicle_id')->selectRaw('vehicle_id, COUNT(*) as total')->groupBy('vehicle_id')->pluck('total', 'vehicle_id');
        $maintenanceStates = VehicleMaintenance::query()
            ->whereIn('status', ['in_progress', 'pending', 'scheduled'])
            ->get()->groupBy('vehicle_id');

        $visibleVehicles = Vehicle::query()->orderBy('plate')->get()
            ->map(function (Vehicle $vehicle) use ($catalog, $tenants, $bookedVehicleIds, $maintenanceStates, $assignedCounts, $request, $access, $serviceTenant, $kmRows, $todayServices): array {
                $owner = $catalog->owner($vehicle, $tenants);
                $reason = $catalog->unavailabilityReason($vehicle, $owner);
                $states = $maintenanceStates->get($vehicle->id, collect());
                $km = $kmRows->get($vehicle->id);
                $planned = $this->alerts->maintenanceRequired($vehicle, $states, $km);
                $papers = $this->alerts->paperwork($vehicle);
                $expiredPapers = collect($papers)->where('expired', true)->isNotEmpty();
                $booked = $bookedVehicleIds->contains($vehicle->id);
                $archived = ! $vehicle->is_active || in_array(mb_strtolower(trim($vehicle->operational_status ?? '')), ['vendido', 'sold', 'robado', 'stolen'], true);
                $displayState = $archived ? 'unavailable' : ($expiredPapers ? 'papers' : ($reason !== null ? 'unavailable' : ($booked ? 'booked' : ($planned ? 'warning' : 'available'))));

                return [
                    'today_services' => $todayServices->get($vehicle->id, collect()),
                    'km' => $km,
                    'paperwork_alerts' => $papers,
                    'vehicle' => $vehicle,
                    'owner' => $owner,
                    'operational' => $reason === null,
                    'unavailability_reason' => $reason === null ? null : $reason.($vehicle->availability_reason ? ' · '.$vehicle->availability_reason : ''),
                    'unavailability_category' => $reason,
                    'assigned_count' => (int) ($assignedCounts[$vehicle->id] ?? 0),
                    'can_move' => $owner && $access->allows($request->user(), $serviceTenant, 'operations.fleet', 2) && $access->allows($request->user(), $owner, 'operations.fleet', 2),
                    'display_state' => $displayState,
                    'display_label' => match ($displayState) {
                        'unavailable' => 'No disponible',
                        'booked' => 'Agendado hoy',
                        'warning' => 'Programar mantención',
                        'papers' => 'Papeles vencidos',
                        default => 'Disponible',
                    },
                    'open_maintenances' => $states->where('tenant_id', $owner?->id)->values(),
                    'can_close_maintenance' => $owner && $access->allows($request->user(), $owner, 'operations.maintenance.close', 2),
                    'in_maintenance' => $states->where('status', 'in_progress')->isNotEmpty(),
                    'planned_maintenance' => $planned,
                    'maintenance_reminder' => $planned || $states->whereIn('status', ['pending', 'scheduled'])->isNotEmpty(),
                    'maintenance_scheduled' => $states->whereIn('status', ['pending', 'scheduled'])->contains(fn ($job): bool => $job->status === 'scheduled' && $job->scheduled_at && ($job->execution_type === 'internal' ? filled($job->responsible_user_id) : filled($job->provider_id))),
                ];
            })
            ->filter(fn (array $row): bool => $catalog->canView($row['owner'], $serviceTenant, $sharedPool, $canReviewUnknownOwner))
            ->values();

        $statuses = $visibleVehicles->pluck('vehicle.operational_status')->filter()->unique()->sort()->values();
        $types = $visibleVehicles->pluck('vehicle.vehicle_type')->filter()->unique()->sort()->values();
        $priorities = DB::table('VEH_board_priorities')->where('user_id', $request->user()->id)->where('tenant_id', $serviceTenant->id)->get()->keyBy(fn ($priority): string => $priority->state.'|'.$priority->vehicle_id);
        $visibleVehicles = $visibleVehicles->map(function (array $row) use ($priorities): array {
            $state = $row['display_state'] === 'unavailable' && $row['unavailability_category'] === 'En mantención' ? 'maintenance' : $row['display_state'];

            return [...$row, 'priority_position' => $priorities->get($state.'|'.$row['vehicle']->id)?->position ?? PHP_INT_MAX];
        })->sortBy([['priority_position', 'asc'], ['vehicle.plate', 'asc']])->values();
        $search = mb_strtoupper(trim($filters['patente'] ?? ''));
        $rows = $visibleVehicles
            ->filter(fn (array $row): bool => $company === 'all' || $row['owner']?->code === $company)
            ->filter(fn (array $row): bool => empty($filters['estado']) || $row['vehicle']->operational_status === $filters['estado'])
            ->filter(fn (array $row): bool => empty($filters['tipo']) || $row['vehicle']->vehicle_type === $filters['tipo'])
            ->filter(fn (array $row): bool => $search === '' || str_contains(mb_strtoupper($row['vehicle']->plate.' '.$row['vehicle']->internal_code), $search))
            ->filter(fn (array $row): bool => ! $request->boolean('pool') || $row['operational'])
            ->values();

        $fleetKmData = $rows->filter(fn ($row) => $row['km'] && $row['km']['can_edit'])->mapWithKeys(fn ($row) => [$row['vehicle']->id => ['plate' => $row['vehicle']->plate, 'baseline' => $row['km']['baseline'], 'due' => $row['km']['due'], 'current' => $row['km']['current'], 'notes' => $row['km']['reading']?->notes]]);

        $sharedProviderTenantId = $tenants->firstWhere('code', '4N')?->id;
        $closingProviders = Provider::query()->whereIn('tenant_id', $rows->pluck('owner.id')->filter()->push($sharedProviderTenantId)->filter()->unique())->where('is_active', true)->whereRaw('LOWER(operator_type) IN (?, ?, ?, ?, ?)', ['vehículos', 'vehiculos', 'taller', 'concesionaria', 'automotriz'])->orderBy('legal_name')->get();

        return view('fleet::vehicles.index', [
            'canManageDocumentAlerts' => $access->allows($request->user(), $serviceTenant, 'admin.permissions', 3),
            'fleetKmData' => $fleetKmData,
            'fleetClosingData' => $rows->filter(fn ($row) => $row['can_close_maintenance'])->mapWithKeys(fn ($row) => [$row['vehicle']->id => ['workshop' => in_array($row['unavailability_category'], ['En mantención', 'En reparación'], true), 'canClose' => $row['can_close_maintenance'], 'odometer' => $row['vehicle']->odometer_km, 'jobs' => $row['open_maintenances']->map(fn ($job) => $job->only(['id', 'maintenance_type', 'execution_type', 'provider_id', 'quotation_number', 'estimated_cost', 'reported_odometer_km']))->values(), 'providers' => $closingProviders->whereIn('tenant_id', array_filter([$row['owner']?->id, $sharedProviderTenantId]))->values()->map(fn ($provider) => ['id' => $provider->id, 'name' => $provider->operational_name ?: $provider->legal_name])]]),
            'kmWeek' => $kmWeek,
            'serviceTenant' => $serviceTenant,
            'company' => $company,
            'companyCodes' => $companyCodes,
            'sharedPool' => $sharedPool,
            'filters' => $filters,
            'statuses' => $statuses,
            'types' => $types,
            'rows' => $rows,
            'totalCount' => $rows->filter(fn (array $row): bool => $row['vehicle']->is_active && ! in_array(mb_strtolower($row['vehicle']->operational_status), ['vendido', 'sold', 'robado', 'stolen'], true))->count(),
            'operationalCount' => $rows->whereIn('display_state', ['available', 'warning'])->count(),
            'unavailableRows' => $rows->whereIn('display_state', ['unavailable', 'booked'])->values(),
            'unavailabilityCounts' => $rows->whereIn('display_state', ['unavailable', 'booked'])->countBy(fn (array $row): string => $row['unavailability_category'] ?? 'Agendado hoy'),
        ]);
    }

    public function reorder(Request $request, FleetAccess $access, VehicleCatalog $catalog, WeeklyVehicleControl $control): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', 'in:available,booked,warning,maintenance,unavailable,papers'],
            'vehicle_ids' => ['required', 'array', 'min:1', 'max:500'],
            'vehicle_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $tenant = $access->tenant($request);
        $visible = $this->index($request, $access, $catalog, $control)->getData()['rows']->keyBy('vehicle.id');
        foreach ($data['vehicle_ids'] as $id) {
            $row = $visible->get($id);
            abort_unless($row, 404);
            $state = $row['display_state'] === 'unavailable' && $row['unavailability_category'] === 'En mantención' ? 'maintenance' : $row['display_state'];
            if ($state !== $data['state']) {
                throw ValidationException::withMessages(['state' => 'El estado de un vehículo cambió. Actualiza el tablero antes de ordenar.']);
            }
        }
        $now = now();
        $entries = collect($data['vehicle_ids'])->map(fn ($id, $position): array => [
            'user_id' => $request->user()->id, 'tenant_id' => $tenant->id, 'vehicle_id' => $id,
            'state' => $data['state'], 'position' => $position, 'created_at' => $now, 'updated_at' => $now,
        ])->all();
        DB::table('VEH_board_priorities')->upsert($entries, ['user_id', 'tenant_id', 'state', 'vehicle_id'], ['position', 'updated_at']);

        return response()->json(['message' => 'Tu orden de prioridad quedó guardado.']);
    }

    public function updateStatus(Request $request, Vehicle $vehicle, FleetAccess $access, VehicleCatalog $catalog, BoardMaintenanceClosing $closing): RedirectResponse
    {
        $tenant = $access->tenant($request);
        $owner = $catalog->owner($vehicle, Tenant::query()->get());
        abort_unless($catalog->canView($owner, $tenant, $catalog->canSeeSharedPool($request->user(), $tenant, $access), false), 404);
        abort_unless($owner && $access->allows($request->user(), $owner, 'operations.fleet', 2), 403);
        $today = today('America/Santiago');
        $data = $request->validate([
            'target_state' => ['required', 'in:available,warning,unavailable'],
            'reason_code' => ['required_if:target_state,unavailable', 'nullable', 'in:maintenance,repair,stolen,sold,other'],
            'reason' => ['required', 'string', 'max:1000'],
            'next_maintenance_at' => ['required_if:target_state,warning', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today->toDateString(), 'before_or_equal:'.$today->copy()->addDays(30)->toDateString()],
            'expected_status' => ['required', 'string', 'max:30'],
            'expected_revision' => ['required', 'string', 'max:40'],
            'assigned_acknowledged' => ['sometimes', 'accepted'],
            'close_maintenance' => ['sometimes', 'boolean'],
            'closing' => ['sometimes', 'array'],
        ]);
        DB::transaction(function () use ($vehicle, $request, $data, $owner, $today, $closing, $access): void {
            $locked = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);
            if ($locked->operational_status !== $data['expected_status'] || $locked->updated_at->toISOString() !== $data['expected_revision']) {
                throw ValidationException::withMessages(['vehicle_state' => 'El vehículo cambió mientras lo revisabas. Actualiza el tablero antes de intentarlo nuevamente.']);
            }
            if ($data['target_state'] !== 'unavailable' && collect($this->alerts->paperwork($locked))->where('expired', true)->isNotEmpty()) {
                throw ValidationException::withMessages(['vehicle_state' => 'Tiene papeles vencidos. Adjunta los documentos renovados para habilitarlo.']);
            }
            $maintenances = $locked->maintenances()->whereIn('status', ['in_progress', 'pending', 'scheduled'])->lockForUpdate()->get();
            $inWorkshop = in_array(mb_strtolower(trim($locked->operational_status)), ['maintenance', 'in_maintenance', 'mantencion', 'mantención', 'en mantención', 'en mantencion', 'repair', 'in_repair', 'reparacion', 'reparación', 'en reparación', 'en reparacion', 'taller'], true) || $maintenances->where('status', 'in_progress')->isNotEmpty();
            if ($data['target_state'] === 'available' && ($data['close_maintenance'] ?? false)) {
                if (! $inWorkshop) {
                    throw ValidationException::withMessages(['vehicle_state' => 'El vehículo no está en taller. Inicia la mantención antes de registrar su cierre.']);
                }
                abort_unless($access->allows($request->user(), $owner, 'operations.maintenance.close', 2), 403);
                $closing->finish($locked, $owner, $request->user(), $maintenances, $request->input('closing', []), $data['reason']);
                $maintenances = $locked->maintenances()->whereIn('status', ['in_progress', 'pending', 'scheduled'])->lockForUpdate()->get();
            } elseif ($data['target_state'] === 'available' && $inWorkshop) {
                throw ValidationException::withMessages(['vehicle_state' => 'Completa el cierre de mantención con proveedor, monto final y factura antes de habilitar el vehículo.']);
            }
            if ($data['target_state'] !== 'unavailable' && $maintenances->where('status', 'in_progress')->isNotEmpty()) {
                throw ValidationException::withMessages(['vehicle_state' => 'No se puede habilitar: el vehículo tiene una mantención en curso. Registra primero su cierre.']);
            }
            if ($data['target_state'] === 'available' && $this->alerts->maintenanceRequired($locked, $maintenances)) {
                throw ValidationException::withMessages(['vehicle_state' => 'Tiene una mantención pendiente, incompleta o prevista para esta semana. Gestiona ese registro antes de habilitarlo.']);
            }
            $assigned = ServiceRequest::query()->where('vehicle_id', $locked->id)->whereDate('service_date', '>=', $today)->whereIn('status', ['scheduled', 'rescheduled', 'reprogramming_pending'])->lockForUpdate()->pluck('id');
            if ($data['target_state'] === 'unavailable' && $assigned->isNotEmpty() && ! ($data['assigned_acknowledged'] ?? false)) {
                throw ValidationException::withMessages(['vehicle_state' => 'Tiene '.$assigned->count().' retiros asignados. Confirma que coordinarás su reasignación.']);
            }
            $before = $locked->only(['operational_status', 'is_active', 'availability_reason', 'next_maintenance_at', 'odometer_km']);
            $status = $data['target_state'] === 'unavailable' ? match ($data['reason_code']) {
                'maintenance' => 'En mantención', 'repair' => 'En reparación', 'stolen' => 'Robado', 'sold' => 'Vendido', default => 'Fuera de servicio',
            } : 'Activa';
            $locked->update([
                'operational_status' => $status,
                'is_active' => ! in_array($status, ['Robado', 'Vendido'], true),
                'availability_reason' => $data['target_state'] === 'unavailable' ? $data['reason'] : null,
                'next_maintenance_at' => $data['target_state'] === 'warning' ? $data['next_maintenance_at'] : ($data['target_state'] === 'available' ? null : $locked->next_maintenance_at),
            ]);
            DB::table('VEH_vehicle_status_events')->insert([
                'is_test' => app()->environment(['local', 'testing']) && config('fleet.auto_test_mode'),
                'vehicle_id' => $locked->id, 'tenant_id' => $owner->id, 'actor_user_id' => $request->user()->id,
                'target_state' => $data['target_state'], 'reason' => $data['reason'],
                'before' => json_encode($before), 'after' => json_encode($locked->only(array_keys($before))),
                'affected_request_ids' => json_encode($assigned->all()), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'Estado del vehículo actualizado y registrado en su historial.');
    }

    public function exportExpiredDocuments(Request $request, FleetAccess $access, VehicleCatalog $catalog, WeeklyVehicleControl $control): BinaryFileResponse
    {
        $selection = $request->validate(['vehicle' => ['nullable', 'integer', 'min:1']]);
        $rows = $this->index($request, $access, $catalog, $control)->getData()['rows'];
        if (isset($selection['vehicle'])) {
            abort_unless($rows->contains(fn (array $row): bool => $row['vehicle']->id === (int) $selection['vehicle']), 404);
            $rows = $rows->filter(fn (array $row): bool => $row['vehicle']->id === (int) $selection['vehicle']);
        }
        $records = $rows->where('display_state', 'papers')->sortBy('vehicle.plate')->flatMap(fn (array $row) => collect($row['paperwork_alerts'])->where('expired', true)->map(fn (array $paper): array => [
            'plate' => $row['vehicle']->plate, 'document' => $paper['label'], 'date' => $paper['date'],
        ]))->values()->all();
        $path = app(ExpiredVehicleDocumentsExcel::class)->create($records);

        return response()->download($path, 'documentos-vencidos-'.today('America/Santiago')->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }

    public function exportUpcomingDocuments(Request $request, FleetAccess $access, VehicleCatalog $catalog, WeeklyVehicleControl $control): BinaryFileResponse
    {
        $rows = $this->index($request, $access, $catalog, $control)->getData()['rows'];
        $records = $rows->filter(fn (array $row): bool => $row['vehicle']->is_active && ! in_array(mb_strtolower(trim($row['vehicle']->operational_status ?? '')), ['vendido', 'sold', 'robado', 'stolen'], true))
            ->sortBy('vehicle.plate')->flatMap(fn (array $row) => collect($row['paperwork_alerts'])
            ->filter(fn (array $paper): bool => $paper['days'] >= 0 && $paper['days'] <= 7)
            ->map(fn (array $paper): array => ['plate' => $row['vehicle']->plate, 'document' => $paper['label'], 'date' => $paper['date']]))->values()->all();
        $today = today('America/Santiago');
        $path = app(ExpiredVehicleDocumentsExcel::class)->create($records, 'Próximos a vencer', 'Documento próximo a vencer');

        return response()->download($path, 'documentos-proximos-a-vencer-'.$today->format('Y-m-d').'-al-'.$today->copy()->addDays(7)->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
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
            ? $vehicle->maintenances()->with(['tenant', 'provider', 'responsible', 'historicalImport'])
                ->whereIn('tenant_id', $maintenanceTenantIds)
                ->orderByDesc('scheduled_at')->orderByDesc('id')->get()
            : collect();
        $latestClosed = $maintenances->where('status', 'closed')->sortByDesc('closed_at')->unique('maintenance_type');
        $settings = DB::table('fleet_maintenance_settings')->get()->keyBy('tenant_id');
        $today = today('America/Santiago');
        $datedHistory = $maintenances->map(function (VehicleMaintenance $maintenance): array {
            $historicalDate = $maintenance->historicalImport?->requested_at;
            $date = $maintenance->closed_at?->copy()->timezone('America/Santiago')
                ?? ($historicalDate ? Carbon::parse($historicalDate->format('Y-m-d'), 'America/Santiago') : null)
                ?? $maintenance->started_at?->copy()->timezone('America/Santiago')
                ?? $maintenance->scheduled_at?->copy()->timezone('America/Santiago');
            $type = Str::lower(Str::ascii(trim($maintenance->maintenance_type)));

            return ['date' => $date, 'is_repair' => $maintenance->status === 'closed' && preg_match('/^(reparacion|reparaciones|repair)(?:\\b|$)/', $type) === 1];
        })->filter(fn (array $record): bool => $record['date'] !== null && $record['date']->lte($today->copy()->endOfDay()));
        $repairSummary = [
            'years' => collect([$today->year - 1, $today->year])->map(fn (int $year): array => [
                'year' => $year,
                'count' => $datedHistory->filter(fn (array $record): bool => $record['is_repair'] && $record['date']->year === $year)->count(),
                'has_history' => $datedHistory->contains(fn (array $record): bool => $record['date']->year === $year),
            ]),
            'months' => collect([0, 1, 2])->map(function (int $offset) use ($today, $datedHistory): array {
                $month = $today->copy()->startOfMonth()->subMonths($offset);

                return [
                    'label' => $month->locale('es')->translatedFormat('F'),
                    'year' => $month->year,
                    'count' => $datedHistory->filter(fn (array $record): bool => $record['is_repair'] && $record['date']->format('Y-m') === $month->format('Y-m'))->count(),
                ];
            }),
        ];

        return view('fleet::vehicles.show', [
            'vehicle' => $vehicle,
            'owner' => $owner,
            'serviceTenant' => $serviceTenant,
            'operational' => $catalog->isOperational($vehicle, $owner),
            'inMaintenance' => VehicleMaintenance::query()->where('vehicle_id', $vehicle->id)->where('status', 'in_progress')->exists(),
            'plannedMaintenance' => VehicleMaintenance::query()->where('vehicle_id', $vehicle->id)->whereIn('status', ['pending', 'scheduled'])->exists(),
            'maintenances' => $maintenances,
            'repairSummary' => $repairSummary,
            'latestClosedIds' => $latestClosed->pluck('id')->all(),
            'maintenanceSettings' => $settings,
            'maintenanceService' => $maintenanceService,
            'maintenanceReference' => app(VehicleMaintenanceReference::class)->forVehicles(collect([$vehicle->id]), today('America/Santiago')->startOfWeek())->get($vehicle->id),
            'vehicleDocuments' => $owner && $access->allows($request->user(), $owner, 'operations.fleet') ? app(VehicleDocuments::class)->forVehicle($vehicle, $owner) : collect(),
            'weeklyReadings' => DB::table('VEH_weekly_readings as readings')->leftJoin('MBA_users', 'MBA_users.id', '=', 'readings.actor_user_id')->where('readings.vehicle_id', $vehicle->id)->orderByDesc('readings.week_start')->orderByDesc('readings.id')->select('readings.*', 'MBA_users.name as actor_name')->get(),
            'statusEvents' => DB::table('VEH_vehicle_status_events as events')->leftJoin('MBA_users', 'MBA_users.id', '=', 'events.actor_user_id')->where('events.vehicle_id', $vehicle->id)->orderByDesc('events.id')->select('events.*', 'MBA_users.name as actor_name')->limit(50)->get(),
            'canReadDriverHistory' => $access->allows($request->user(), $serviceTenant, 'operations.scheduling'),
            'driverAssignments' => $access->allows($request->user(), $serviceTenant, 'operations.scheduling')
                ? ServiceRequest::query()->with('driver')->where('tenant_id', $serviceTenant->id)->where('vehicle_id', $vehicle->id)->whereNotNull('driver_user_id')->orderByDesc('service_date')->orderByDesc('id')->limit(20)->get()
                : collect(),
            'canReadMaintenance' => $canReadMaintenance,
            'canUploadDocuments' => $owner && $access->allows($request->user(), $serviceTenant, 'operations.fleet', 2) && $access->allows($request->user(), $owner, 'operations.fleet', 2),
            'canCreateMaintenance' => $owner && $canReadMaintenance && $access->allows($request->user(), $owner, 'operations.maintenance', 2),
        ]);
    }
}
