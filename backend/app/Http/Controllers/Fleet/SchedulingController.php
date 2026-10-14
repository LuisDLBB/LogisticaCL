<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\CoordinationService;
use App\Fleet\FleetAccess;
use App\Fleet\LatePickupAuthorization;
use App\Fleet\RetVehicleEligibility;
use App\Fleet\SchedulingService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestReprogramming;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SchedulingController extends Controller
{
    public function index(Request $request, FleetAccess $access, SchedulingService $service): View
    {
        $tenant = $access->tenant($request);
        $filters = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'], 'jornada' => ['nullable', 'in:AM,PM'],
            'client_id' => ['nullable', 'integer'], 'ret' => ['nullable', 'string', 'max:32'],
            'estado' => ['nullable', Rule::in(['requested', 'scheduled', 'reprogramming_pending', 'rescheduled', 'retired', 'cancelled'])],
            'driver_user_id' => ['nullable', 'integer'], 'vehicle_id' => ['nullable', 'integer'],
        ]);
        $date = $filters['fecha'] ?? now()->toDateString();
        $rows = ServiceRequest::query()->with(['client', 'branch', 'driver', 'vehicle'])
            ->where('tenant_id', $tenant->id)->whereDate('service_date', $date)
            ->when(filled($filters['jornada'] ?? null), fn ($query) => $query->where('shift', $filters['jornada']))
            ->when(filled($filters['client_id'] ?? null), fn ($query) => $query->where('client_id', $filters['client_id']))
            ->when(filled($filters['ret'] ?? null), fn ($query) => $query->where('ret_code', 'like', '%'.$filters['ret'].'%'))
            ->when(filled($filters['estado'] ?? null), fn ($query) => $query->where('status', $filters['estado']))
            ->when(filled($filters['driver_user_id'] ?? null), fn ($query) => $query->where('driver_user_id', $filters['driver_user_id']))
            ->when(filled($filters['vehicle_id'] ?? null), fn ($query) => $query->where('vehicle_id', $filters['vehicle_id']))
            ->orderBy('window_start')->orderBy('id')->get();
        $drivers = $service->drivers();
        $pool = $service->pool();
        $clients = Client::query()->where('tenant_id', $tenant->id)->orderBy('commercial_name')->get();
        $limit = $service->limit($tenant->id);
        $canEdit = $access->allows($request->user(), $tenant, 'operations.scheduling', 2)
            && $access->allows($request->user(), $tenant, 'operations.vehicle_pool', 1);
        $canApprove = $access->allows($request->user(), $tenant, 'operations.scheduling.overload.approve', 3);
        $pending = $canApprove ? DB::table('service_request_overload_authorizations as approvals')
            ->join('service_requests as rets', 'rets.id', '=', 'approvals.service_request_id')
            ->join('MBA_users as drivers', 'drivers.id', '=', 'approvals.driver_user_id')
            ->join('MBA_vehicles', 'MBA_vehicles.id', '=', 'approvals.vehicle_id')
            ->where('rets.tenant_id', $tenant->id)->where('approvals.status', 'pending')
            ->select('approvals.*', 'rets.ret_code', 'drivers.name as driver_name', 'MBA_vehicles.plate')
            ->orderBy('approvals.created_at')->get() : collect();
        $warnings = $rows->mapWithKeys(function (ServiceRequest $ret) use ($service, $limit): array {
            if (! $ret->driver_user_id || ! $ret->vehicle_id) {
                return [$ret->id => null];
            }
            $counts = $service->concurrences($ret, $ret->driver_user_id, $ret->vehicle_id, $ret->service_date->toDateString(), $ret->window_start, $ret->window_end);

            return [$ret->id => ['driver' => $counts['driver'], 'vehicle' => $counts['vehicle'], 'limit' => $limit]];
        });

        return view('fleet::scheduling.index', compact('tenant', 'filters', 'date', 'rows', 'drivers', 'pool', 'clients', 'limit', 'canEdit', 'canApprove', 'pending', 'warnings'));
    }

    public function assign(Request $request, ServiceRequest $serviceRequest, FleetAccess $access, SchedulingService $service): RedirectResponse
    {
        $this->authorizeRet($request, $serviceRequest, $access);
        $data = $request->validate([
            'driver_user_id' => ['required', 'integer', 'exists:MBA_users,id'],
            'vehicle_id' => ['required', 'integer', 'exists:MBA_vehicles,id'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $service->assign($serviceRequest, $request->user(), $data['driver_user_id'], $data['vehicle_id'], $data['reason'] ?? null);

        return back()->with('status', 'Asignación guardada.');
    }

    public function preview(Request $request, ServiceRequest $serviceRequest, FleetAccess $access, SchedulingService $service): JsonResponse
    {
        $this->authorizeRet($request, $serviceRequest, $access);
        $data = $request->validate([
            'driver_user_id' => ['required', 'integer', 'exists:MBA_users,id'],
            'vehicle_id' => ['required', 'integer', 'exists:MBA_vehicles,id'],
        ]);
        [, $vehicle] = $service->validateResources($data['driver_user_id'], $data['vehicle_id']);
        app(RetVehicleEligibility::class)->assertLoad($serviceRequest, $vehicle);
        $counts = $service->concurrences($serviceRequest, $data['driver_user_id'], $data['vehicle_id'],
            $serviceRequest->service_date->toDateString(), $serviceRequest->window_start, $serviceRequest->window_end);

        return response()->json($counts + ['limit' => $service->limit($serviceRequest->tenant_id)]);
    }

    public function requestOverload(Request $request, ServiceRequest $serviceRequest, FleetAccess $access, SchedulingService $service): RedirectResponse
    {
        $this->authorizeRet($request, $serviceRequest, $access);
        $data = $request->validate([
            'driver_user_id' => ['required', 'integer', 'exists:MBA_users,id'],
            'vehicle_id' => ['required', 'integer', 'exists:MBA_vehicles,id'],
            'reason' => ['required', 'string', 'max:2000'],
            'reprogramming_id' => ['nullable', 'integer', 'exists:service_request_reprogrammings,id'],
        ]);
        $date = $serviceRequest->service_date->toDateString();
        $start = $serviceRequest->window_start;
        $end = $serviceRequest->window_end;
        if (isset($data['reprogramming_id'])) {
            $reprogramming = ServiceRequestReprogramming::query()->where('service_request_id', $serviceRequest->id)
                ->where('status', 'pending')->findOrFail($data['reprogramming_id']);
            $date = $reprogramming->proposed_date->toDateString();
            $start = $reprogramming->proposed_window_start;
            $end = $reprogramming->proposed_window_end;
        } elseif (! in_array($serviceRequest->status, ['requested', 'scheduled', 'rescheduled'], true)) {
            throw ValidationException::withMessages(['ret' => 'Este RET no admite una nueva asignación.']);
        }
        $service->requestOverload($serviceRequest, $request->user(), $data['driver_user_id'], $data['vehicle_id'], $date, $start, $end, $data['reason']);

        return back()->with('status', 'Autorización solicitada a jefatura.');
    }

    public function decide(Request $request, int $authorization, FleetAccess $access, CoordinationService $coordination): RedirectResponse
    {
        $tenant = $access->tenant($request);
        abort_unless($access->allows($request->user(), $tenant, 'operations.scheduling.overload.approve', 3), 403);
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'decision_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($authorization, $request, $tenant, $data, $coordination): void {
            $approval = DB::table('service_request_overload_authorizations')->where('id', $authorization)->lockForUpdate()->first();
            abort_unless($approval !== null, 404);
            $ret = ServiceRequest::query()->where('tenant_id', $tenant->id)->findOrFail($approval->service_request_id);
            if ($approval->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'La solicitud ya fue resuelta.']);
            }
            if ($approval->requested_by_user_id === $request->user()->id) {
                throw ValidationException::withMessages(['decision' => 'El solicitante no puede aprobar ni resolver su propia solicitud.']);
            }
            DB::table('service_request_overload_authorizations')->where('id', $authorization)->update([
                'status' => $data['decision'], 'decided_by_user_id' => $request->user()->id,
                'decision_notes' => $data['decision_notes'] ?? null,
                'decided_at' => now(), 'updated_at' => now(),
            ]);
            $coordination->event($ret, $request->user(), 'overload_'.$data['decision'], ['authorization_id' => $authorization, 'notes' => $data['decision_notes'] ?? null]);
        }, 3);

        return back()->with('status', 'Decisión de sobrecarga registrada.');
    }

    public function settings(Request $request, FleetAccess $access, SchedulingService $service): View
    {
        $tenant = $access->tenant($request);

        $cutoff = app(LatePickupAuthorization::class)->cutoff($tenant->id);
        $approvers = DB::table('fleet_pickup_approvers')->join('MBA_users', 'MBA_users.id', '=', 'fleet_pickup_approvers.user_id')
            ->where('fleet_pickup_approvers.tenant_id', $tenant->id)->orderBy('area')->orderBy('MBA_users.name')
            ->get(['fleet_pickup_approvers.*', 'MBA_users.name', 'MBA_users.email']);

        return view('fleet::scheduling.settings', ['tenant' => $tenant, 'limit' => $service->limit($tenant->id), 'cutoff' => $cutoff, 'approvers' => $approvers]);
    }

    public function updateSettings(Request $request, FleetAccess $access): RedirectResponse
    {
        $tenant = $access->tenant($request);
        $data = $request->validate(['max_concurrent_pickups' => ['required', 'integer', 'between:1,100']]);
        DB::table('fleet_scheduling_settings')->updateOrInsert(['tenant_id' => $tenant->id], [
            'max_concurrent_pickups' => $data['max_concurrent_pickups'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $access->audit($request->user(), $tenant, null, 'scheduling_limit_updated', null, $data);

        return back()->with('status', 'Límite de coincidencias actualizado.');
    }

    public function updateCutoff(Request $request, FleetAccess $access): RedirectResponse
    {
        $tenant = $access->tenant($request);
        $data = $request->validate(['pickup_cutoff_time' => ['required', 'date_format:H:i']]);
        DB::transaction(function () use ($request, $access, $tenant, $data): void {
            $before = app(LatePickupAuthorization::class)->settings($tenant->id);
            if (substr($before->pickup_cutoff_time, 0, 5) === $data['pickup_cutoff_time']) {
                return;
            }
            DB::table('fleet_scheduling_settings')->where('tenant_id', $tenant->id)->update([
                'pickup_cutoff_time' => $data['pickup_cutoff_time'].':00',
                'pickup_authorization_revision' => $before->pickup_authorization_revision + 1, 'updated_at' => now(),
            ]);
            $access->audit($request->user(), $tenant, null, 'pickup_cutoff_updated', ['pickup_cutoff_time' => $before->pickup_cutoff_time], $data);
        }, 3);

        return back()->with('status', 'Horario de corte actualizado.');
    }

    public function addApprover(Request $request, FleetAccess $access): RedirectResponse
    {
        $tenant = $access->tenant($request);
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'area' => ['required', 'in:operations,commercial']]);
        DB::transaction(function () use ($request, $access, $tenant, $data): void {
            $settings = app(LatePickupAuthorization::class)->settings($tenant->id);
            $user = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])->first();
            $valid = $user && DB::table('MBA_tenant_users')
                ->join('fleet_membership_profiles', 'fleet_membership_profiles.tenant_user_id', '=', 'MBA_tenant_users.id')
                ->join('fleet_profiles', 'fleet_profiles.id', '=', 'fleet_membership_profiles.fleet_profile_id')
                ->where('MBA_tenant_users.tenant_id', $tenant->id)->where('MBA_tenant_users.user_id', $user->id)
                ->where('MBA_tenant_users.is_active', true)->where('fleet_membership_profiles.is_active', true)->where('fleet_profiles.is_active', true)->exists();
            if (! $valid) {
                throw ValidationException::withMessages(['email' => 'El correo debe pertenecer a una cuenta activa de esta empresa en la plataforma.']);
            }
            $keys = ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'area' => $data['area']];
            $before = DB::table('fleet_pickup_approvers')->where($keys)->first();
            if ($before?->is_active) {
                return;
            }
            DB::table('fleet_pickup_approvers')->updateOrInsert($keys, ['is_active' => true, 'created_at' => $before?->created_at ?? now(), 'updated_at' => now()]);
            DB::table('fleet_scheduling_settings')->where('tenant_id', $tenant->id)->update(['pickup_authorization_revision' => $settings->pickup_authorization_revision + 1, 'updated_at' => now()]);
            $access->audit($request->user(), $tenant, null, 'pickup_approver_added', $before ? (array) $before : null, $keys);
        }, 3);

        return back()->with('status', 'Responsable agregado a la matriz de aprobación.');
    }

    public function toggleApprover(Request $request, int $approver, FleetAccess $access): RedirectResponse
    {
        $tenant = $access->tenant($request);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $approver, $access, $tenant, $data): void {
            $settings = app(LatePickupAuthorization::class)->settings($tenant->id);
            $before = DB::table('fleet_pickup_approvers')->where('tenant_id', $tenant->id)->where('id', $approver)->first();
            abort_unless($before, 404);
            if ((bool) $before->is_active === (bool) $data['is_active']) {
                return;
            }
            if ($data['is_active']) {
                $activeMember = DB::table('MBA_tenant_users')
                    ->join('fleet_membership_profiles', 'fleet_membership_profiles.tenant_user_id', '=', 'MBA_tenant_users.id')
                    ->join('fleet_profiles', 'fleet_profiles.id', '=', 'fleet_membership_profiles.fleet_profile_id')
                    ->where('MBA_tenant_users.tenant_id', $tenant->id)->where('MBA_tenant_users.user_id', $before->user_id)
                    ->where('MBA_tenant_users.is_active', true)->where('fleet_membership_profiles.is_active', true)->where('fleet_profiles.is_active', true)->exists();
                if (! $activeMember) {
                    throw ValidationException::withMessages(['is_active' => 'La cuenta o su perfil ya no están activos en esta empresa.']);
                }
            }
            DB::table('fleet_pickup_approvers')->where('id', $approver)->update(['is_active' => $data['is_active'], 'updated_at' => now()]);
            DB::table('fleet_scheduling_settings')->where('tenant_id', $tenant->id)->update(['pickup_authorization_revision' => $settings->pickup_authorization_revision + 1, 'updated_at' => now()]);
            $access->audit($request->user(), $tenant, null, 'pickup_approver_updated', (array) $before, $data);
        }, 3);

        return back()->with('status', $data['is_active'] ? 'Responsable reactivado.' : 'Responsable quitado de la matriz activa. Su historial se conserva.');
    }

    private function authorizeRet(Request $request, ServiceRequest $ret, FleetAccess $access): void
    {
        $tenant = $access->tenant($request);
        abort_unless($tenant !== null && $ret->tenant_id === $tenant->id, 404);
        abort_unless($access->allows($request->user(), $tenant, 'operations.scheduling', 2)
            && $access->allows($request->user(), $tenant, 'operations.vehicle_pool'), 403);
    }
}
