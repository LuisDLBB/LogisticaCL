<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\FleetAccess;
use App\Fleet\VehicleBoardAlerts;
use App\Fleet\VehicleCatalog;
use App\Fleet\WeeklyVehicleControl;
use App\Http\Controllers\Controller;
use App\Models\RetFeasibility;
use App\Models\ServiceRequest;
use App\Models\VehicleMaintenance;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RetMonitorController extends Controller
{
    public function __construct(private VehicleBoardAlerts $alerts) {}

    public function index(Request $request, FleetAccess $access, VehicleCatalog $catalog, WeeklyVehicleControl $control): Response
    {
        $tenant = $access->tenant($request);
        $canRequests = $access->allows($request->user(), $tenant, 'coordination.requests');
        $canFeasibility = collect(['coordination.feasibility', 'commercial.feasibility', 'finance.feasibility', 'operations.feasibility', 'finance.feasibility.validate', 'commercial.feasibility.approve'])->contains(fn ($scope) => $access->allows($request->user(), $tenant, $scope));
        abort_unless($canRequests || $canFeasibility, 403);
        $filters = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'fragment' => ['nullable', 'boolean']]);
        $filters += ['fecha' => today('America/Santiago')->toDateString(), 'tipo' => 'all', 'estado' => 'all'];
        $items = collect();
        if ($canRequests && $filters['tipo'] !== 'feasibility') {
            $requests = ServiceRequest::with(['driver', 'vehicle'])->where('tenant_id', $tenant->id)->when($filters['fecha'], fn ($query, $date) => $query->whereDate('service_date', $date))->get();
            $tasks = DB::table('ret_coordination_tasks')->whereIn('service_request_id', $requests->modelKeys())->where('status', 'pending')->latest('id')->get()->unique('service_request_id')->keyBy('service_request_id');
            foreach ($requests as $ret) {
                $task = $tasks->get($ret->id);
                $color = match (true) {
                    $ret->status === 'cancelled' => 'red', $ret->status === 'retired' => 'green',
                    $task !== null || in_array($ret->status, ['correction_requested', 'reprogramming_pending'], true) => 'yellow',
                    in_array($ret->status, ['scheduled', 'rescheduled'], true) => 'blue', default => 'white',
                };
                $state = match ($color) {
                    'red' => 'Cancelado / anulado', 'green' => 'Retirado', 'yellow' => $task?->kind === 'reschedule' || $ret->status === 'reprogramming_pending' ? 'Reagendamiento pendiente' : 'Corrección pendiente', 'blue' => 'Agendado', default => 'Pendiente de agendamiento',
                };
                $items->push(['code' => $ret->ret_code, 'type' => 'Retiro', 'client' => $ret->client_name_snapshot, 'date' => $ret->service_date->format('Y-m-d'), 'time' => substr($ret->window_start, 0, 5).'–'.substr($ret->window_end, 0, 5), 'packages' => $color === 'red' ? 0 : $ret->packages, 'address' => $ret->effective_address.' · '.$ret->commune_snapshot, 'color' => $color, 'state' => $state, 'reason' => $color === 'red' ? $ret->cancellation_reason : ($task?->reason ?? ''), 'assignment' => ($ret->driver?->name ?: 'Sin conductor').' · '.($ret->vehicle?->plate ?: 'Sin patente'), 'test' => $ret->is_test, 'url' => route('fleet.requests.show', $ret)]);
            }
        }
        if ($canFeasibility && $filters['tipo'] !== 'requests') {
            $cases = RetFeasibility::with('client')->where('tenant_id', $tenant->id)->get()->filter(fn ($row) => ! $filters['fecha'] || empty($row->request_data['service_date']) || $row->request_data['service_date'] === $filters['fecha']);
            $events = DB::table('ret_feasibility_events')->whereIn('ret_feasibility_id', $cases->modelKeys())->whereIn('action', ['correction', 'reject'])->latest('id')->get()->unique('ret_feasibility_id')->keyBy('ret_feasibility_id');
            $linked = $canRequests ? ServiceRequest::where('tenant_id', $tenant->id)->whereIn('id', $cases->pluck('service_request_id')->filter())->get()->keyBy('id') : collect();
            $linkedTasks = DB::table('ret_coordination_tasks')->whereIn('service_request_id', $linked->keys())->where('status', 'pending')->latest('id')->get()->unique('service_request_id')->keyBy('service_request_id');
            foreach ($cases as $case) {
                $ret = $linked->get($case->service_request_id);
                if ($ret && $requests->contains('id', $ret->id)) {
                    continue;
                }
                $color = match (true) {
                    $case->status === 'rejected' || $ret?->status === 'cancelled' => 'red',
                    $ret?->status === 'retired' => 'green', $case->status === 'correction' || $linkedTasks->has($ret?->id) || in_array($ret?->status, ['correction_requested', 'reprogramming_pending'], true) => 'yellow',
                    in_array($ret?->status, ['scheduled', 'rescheduled'], true) => 'blue', default => 'white',
                };
                $details = json_decode($events->get($case->id)?->details ?? '{}', true);
                $data = $case->request_data ?? [];
                $items->push(['code' => 'RET-FAC-'.$case->id, 'type' => 'Factibilidad', 'client' => $case->client->commercial_name, 'date' => $data['service_date'] ?? '', 'time' => ($data['window_start'] ?? '—').'–'.($data['window_end'] ?? '—'), 'packages' => $color === 'red' ? 0 : ($data['packages'] ?? null), 'address' => ($data['origin'] ?? 'Origen por informar').(filled($data['destination'] ?? null) ? ' → '.$data['destination'] : ''), 'color' => $color, 'state' => match ($color) {
                    'blue' => 'Agendado · En Coordinación', 'green' => 'Retirado', 'red' => $case->status === 'rejected' ? 'Rechazado / anulado' : 'Cancelado / anulado', 'yellow' => 'Corrección / reagendamiento pendiente', default => $case->status === 'approved' ? 'Aprobado' : (RetFeasibilityController::STATES[$case->status] ?? $case->status)
                }, 'reason' => in_array($case->status, ['rejected', 'correction'], true) ? ($details['reason'] ?? $case->operations_data['reason'] ?? '') : ($linkedTasks->get($ret?->id)?->reason ?? $ret?->cancellation_reason ?? ''), 'assignment' => 'Servicio en factibilidad', 'test' => $case->is_test, 'url' => route('fleet.feasibility.show', $case)]);
            }
        }
        if (filled($filters['q'] ?? null)) {
            $term = mb_strtolower($filters['q']);
            $items = $items->filter(fn ($row) => str_contains(mb_strtolower($row['code'].' '.$row['client'].' '.$row['address']), $term));
        }
        $counts = $items->countBy('color');
        $total = $items->count();
        if ($filters['estado'] !== 'all') {
            $items = $items->where('color', $filters['estado']);
        }
        $priority = ['red' => 0, 'yellow' => 1, 'white' => 2, 'blue' => 3, 'green' => 4];
        $items = $items->sortBy(fn ($row) => $priority[$row['color']].'|'.$row['date'].'|'.$row['time'].'|'.$row['code'])->values();
        $page = min((int) ($filters['page'] ?? 1), max(1, (int) ceil($items->count() / 12)));
        $rows = new LengthAwarePaginator($items->forPage($page, 12)->values(), $items->count(), 12, $page, ['path' => route('fleet.monitor.index'), 'query' => []]);
        $fleetSummary = null;
        if ($access->allows($request->user(), $tenant, 'operations.fleet')) {
            $vehicles = $control->vehicles($request, $access, $catalog);
            $kmRows = $control->rows($vehicles, today('America/Santiago')->startOfWeek(), $request, $access);
            $jobs = VehicleMaintenance::query()->whereIn('vehicle_id', $vehicles->pluck('vehicle.id'))->whereIn('status', ['pending', 'scheduled'])->get()->groupBy('vehicle_id');
            $bookedIds = ServiceRequest::query()->where('tenant_id', $tenant->id)->whereDate('service_date', $filters['fecha'])->whereIn('status', ['scheduled', 'rescheduled'])->pluck('vehicle_id');
            $fleetSummary = ['available' => 0, 'booked' => 0, 'warning' => 0, 'workshop' => 0, 'unavailable' => 0, 'papers' => 0];
            foreach ($kmRows as $row) {
                $vehicle = $row['vehicle'];
                $state = match (true) {
                    collect($this->alerts->paperwork($vehicle))->where('expired', true)->isNotEmpty() => 'papers',
                    $row['in_workshop'] => 'workshop',
                    ! $catalog->isOperational($vehicle, $row['owner']) => 'unavailable',
                    $this->alerts->maintenanceRequired($vehicle, $jobs->get($vehicle->id, collect()), $row) => 'warning',
                    $bookedIds->contains($vehicle->id) => 'booked',
                    default => 'available',
                };
                $fleetSummary[$state]++;
            }
        }
        $updatedAt = now('America/Santiago')->format('H:i:s');

        return response()->view($request->boolean('fragment') ? 'fleet::coordination.monitor-board' : 'fleet::coordination.monitor', compact('rows', 'counts', 'total', 'filters', 'tenant', 'updatedAt', 'canRequests', 'canFeasibility', 'fleetSummary'))->header('Cache-Control', 'no-store, private');
    }
}
