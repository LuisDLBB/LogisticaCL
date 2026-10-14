<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\CoordinationService;
use App\Fleet\FleetAccess;
use App\Fleet\LatePickupAuthorization;
use App\Fleet\RetNotificationService;
use App\Fleet\RetVehicleEligibility;
use App\Fleet\SchedulingService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientBranch;
use App\Models\FixedPickup;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestReprogramming;
use App\Models\ServiceType;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CoordinationController extends Controller
{
    public function fixedIndex(Request $request, FleetAccess $access): View
    {
        $tenant = $access->tenant($request);
        $filters = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'buscar' => ['nullable', 'string', 'max:160'],
        ]);
        $selectedDate = Carbon::parse($filters['fecha'] ?? now('America/Santiago')->toDateString(), 'America/Santiago');
        $rows = FixedPickup::query()->with(['client', 'branch'])->where('tenant_id', $tenant->id)->orderBy('source_client_name')->orderBy('source_point_name')->orderBy('id')->get();
        $created = ServiceRequest::query()->where('tenant_id', $tenant->id)->whereNotNull('fixed_pickup_id')
            ->whereDate('requested_date_original', $selectedDate->toDateString())
            ->pluck('fixed_pickup_id')->flip();
        $statuses = $rows->mapWithKeys(function (FixedPickup $item) use ($selectedDate, $created): array {
            $status = match (true) {
                ! $item->is_active => 'Inactivo',
                $item->association_status !== 'linked' => 'Punto pendiente',
                $item->branch?->address_status !== 'ready' => 'Dirección pendiente',
                isset($created[$item->id]) => 'En solicitudes',
                ! in_array($selectedDate->dayOfWeekIso, $item->weekdays ?? [], true) => 'Otro día',
                default => 'Habilitado',
            };

            return [$item->id => $status];
        });

        $statusCounts = $statuses->countBy();
        $rows = $rows->filter(function (FixedPickup $item) use ($filters, $statuses): bool {
            if ($statuses[$item->id] !== 'Habilitado') {
                return false;
            }

            return blank($filters['buscar'] ?? null)
                || str_contains(mb_strtolower($item->source_client_name.' '.$item->source_point_name.' '.($item->branch?->address ?? '')), mb_strtolower($filters['buscar']));
        });
        $packageDefaults = $rows->mapWithKeys(fn (FixedPickup $row): array => [$row->id => $row->usual_packages ?? 5]);
        $usualDrivers = User::whereIn('id', $rows->pluck('usual_driver_user_id')->filter())->pluck('name', 'id');
        $usualVehicles = Vehicle::whereIn('id', $rows->pluck('usual_vehicle_id')->filter())->pluck('plate', 'id');

        $canEdit = $access->allows($request->user(), $tenant, 'coordination.fixed-pickups', 2);
        $clients = Client::where('tenant_id', $tenant->id)->where('is_active', true)->get();
        $points = ClientBranch::whereIn('client_id', $clients->modelKeys())->where('is_active', true)->get();
        $serviceTypes = ServiceType::where('is_active', true)->get();
        $drivers = app(SchedulingService::class)->drivers();
        $vehicles = app(SchedulingService::class)->pool()->filter(fn ($row) => $row['selectable']);

        return view('fleet::coordination.fixed', compact('rows', 'statuses', 'statusCounts', 'selectedDate', 'filters', 'canEdit', 'clients', 'points', 'serviceTypes', 'drivers', 'vehicles', 'packageDefaults', 'usualDrivers', 'usualVehicles'));
    }

    public function fixedUpdate(Request $request, FixedPickup $fixedPickup, FleetAccess $access): RedirectResponse
    {
        $this->authorizeTenant($request, $access, $fixedPickup->tenant_id, 'coordination.fixed-pickups', 2);
        $data = $request->validate([
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'shift' => ['required', 'in:AM,PM,AM/PM'],
            'window_start' => ['required', 'date_format:H:i'],
            'window_end' => ['required', 'date_format:H:i', 'after:window_start'],
            'material' => ['required', 'string', 'max:160'],
            'usual_packages' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'string', 'max:100'], 'instructions' => ['nullable', 'string', 'max:2000'],
            'usual_driver_user_id' => ['nullable', 'integer', 'exists:users,id'], 'usual_vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
        ]);
        $fixedPickup->update($data);

        return back()->with('status', 'Retiro fijo actualizado.');
    }

    public function fixedToggle(Request $request, FixedPickup $fixedPickup, FleetAccess $access): RedirectResponse
    {
        $this->authorizeTenant($request, $access, $fixedPickup->tenant_id, 'coordination.fixed-pickups', 2);
        $fixedPickup->update(['is_active' => ! $fixedPickup->is_active]);

        return back()->with('status', $fixedPickup->is_active ? 'Retiro fijo activado.' : 'Retiro fijo desactivado.');
    }

    public function pointUpdate(Request $request, ClientBranch $branch, FleetAccess $access): RedirectResponse
    {
        $client = $branch->client;
        $this->authorizeTenant($request, $access, $client->tenant_id, 'coordination.fixed-pickups', 2);
        $data = $request->validate([
            'address' => ['required', 'string', 'max:255'],
            'commune_name' => ['required', 'string', 'max:100'],
            'operational_emails' => ['nullable', 'string', 'max:2000'],
        ]);
        $emails = trim((string) ($data['operational_emails'] ?? ''));
        if ($emails !== '') {
            foreach (preg_split('/[;,]/', $emails) as $email) {
                if (! filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages(['operational_emails' => 'Revisa los correos separados por coma o punto y coma.']);
                }
            }
        }
        $branch->update([
            'address' => trim($data['address']),
            'commune_name' => trim($data['commune_name']),
            'operational_emails' => $emails ?: null,
            'operational_email_pending' => $emails === '',
            'address_status' => 'ready',
        ]);
        FixedPickup::query()->where('client_branch_id', $branch->id)->update([
            'operational_emails' => $emails ?: null,
            'operational_email_pending' => $emails === '',
        ]);

        return back()->with('status', 'Punto actualizado; los RET anteriores conservan su dirección y correo originales.');
    }

    public function fixedConvert(Request $request, FleetAccess $access, CoordinationService $service): RedirectResponse
    {
        $tenant = $access->tenant($request);
        $data = $request->validate([
            'service_date' => ['required', 'date_format:Y-m-d'],
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
            'single_id' => ['nullable', 'integer'],
            'packages' => ['nullable', 'array'],
            'packages.*' => ['nullable', 'integer', 'min:1'],
        ]);
        $date = Carbon::parse($data['service_date']);
        $rows = FixedPickup::query()->with(['client', 'branch'])->where('tenant_id', $tenant->id)->get();
        $ids = $request->input('action') === 'all'
            ? $rows->filter(fn (FixedPickup $item): bool => $item->is_active && $item->association_status === 'linked'
                && $item->branch?->address_status === 'ready' && in_array($date->dayOfWeekIso, $item->weekdays ?? [], true)
                && ! ServiceRequest::query()->where('fixed_pickup_id', $item->id)->whereDate('requested_date_original', $date->toDateString())->exists())->modelKeys()
            : array_map('intval', $request->filled('single_id') ? [$request->input('single_id')] : ($data['ids'] ?? []));
        if ($ids === []) {
            throw ValidationException::withMessages(['ids' => 'Selecciona al menos un retiro habilitado.']);
        }
        $selected = $rows->whereIn('id', $ids);
        if ($selected->count() !== count(array_unique($ids))) {
            abort(403);
        }
        $created = DB::transaction(function () use ($selected, $date, $data, $request, $service): int {
            foreach ($selected as $item) {
                if (! $item->is_active || $item->association_status !== 'linked' || $item->branch?->address_status !== 'ready'
                    || ! in_array($date->dayOfWeekIso, $item->weekdays ?? [], true)) {
                    throw ValidationException::withMessages(['ids' => 'Hay un retiro inactivo, sin dirección validada o de otro día.']);
                }
                $packages = $data['packages'][$item->id] ?? $item->usual_packages;
                if (! is_numeric($packages) || (int) $packages < 1 || (string) (int) $packages !== (string) $packages) {
                    throw ValidationException::withMessages(['packages' => 'Indica la cantidad de bultos de cada retiro seleccionado.']);
                }
                $service->createRequest($request->user(), $item->client, $item->branch, $item->service_type_id, [
                    'service_date' => $date->toDateString(), 'shift' => $item->shift,
                    'window_start' => $item->window_start, 'window_end' => $item->window_end,
                    'packages' => (int) $packages, 'material' => $item->material, 'notes' => $item->instructions, 'is_test' => $item->is_test || $request->boolean('is_test'),
                ], $item);
            }

            return $selected->count();
        });

        return redirect()->route('fleet.page.coordination-fixed-pickups', ['fecha' => $date->toDateString()])->with('status', "$created retiro(s) pasados a Solicitudes.");
    }

    public function requestsIndex(Request $request, FleetAccess $access): View
    {
        $tenant = $access->tenant($request);
        $filters = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'client_id' => ['nullable', 'integer'], 'branch_id' => ['nullable', 'integer'],
            'estado' => ['nullable', 'in:requested,scheduled,reprogramming_pending,rescheduled,retired,cancelled,correction_requested,agenda,pending'],
        ]);
        $rows = ServiceRequest::query()->with(['client', 'branch', 'driver', 'vehicle', 'requester', 'serviceType'])->where('tenant_id', $tenant->id)
            ->when(filled($filters['fecha'] ?? null), fn ($query) => $query->whereDate('service_date', $filters['fecha']))
            ->when(filled($filters['client_id'] ?? null), fn ($query) => $query->where('client_id', $filters['client_id']))
            ->when(filled($filters['branch_id'] ?? null), fn ($query) => $query->where('client_branch_id', $filters['branch_id']))
            ->when(filled($filters['estado'] ?? null), fn ($query) => $query->whereIn('status', match ($filters['estado']) {
                'agenda' => ['scheduled', 'rescheduled'], 'pending' => ['requested', 'correction_requested'], default => [$filters['estado']]
            }))
            ->orderByDesc('service_date')->orderByDesc('id')->paginate(50)->withQueryString();
        $visibleIds = $rows->getCollection()->modelKeys();
        $customerCancellations = DB::table('service_request_events')->whereIn('service_request_id', $visibleIds)->where('action', 'customer_cancel')->get()->keyBy('service_request_id');
        $customerCorrections = DB::table('ret_coordination_tasks')->leftJoin('users', 'users.id', '=', 'ret_coordination_tasks.assigned_to_user_id')->whereIn('service_request_id', $visibleIds)->where('kind', 'correction')->where('status', 'pending')->select('ret_coordination_tasks.*', 'users.name as assigned_name')->get()->keyBy('service_request_id');
        $statusCounts = ServiceRequest::query()->where('tenant_id', $tenant->id)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $clients = Client::query()->where('tenant_id', $tenant->id)->orderBy('commercial_name')->get();
        $branches = ClientBranch::query()->with('client')->whereIn('client_id', $clients->modelKeys())->orderBy('name')->get();

        $canCreate = $access->allows($request->user(), $tenant, 'coordination.requests', 2);
        $canApprove = $access->allows($request->user(), $tenant, 'coordination.reprogramming.approve', 3);

        $myCoordinationTasks = DB::table('ret_coordination_tasks')->join('service_requests', 'service_requests.id', '=', 'ret_coordination_tasks.service_request_id')->where('service_requests.tenant_id', $tenant->id)->where('ret_coordination_tasks.status', 'pending')->where('ret_coordination_tasks.assigned_to_user_id', $request->user()->id)->select('ret_coordination_tasks.*', 'service_requests.ret_code', 'service_requests.client_name_snapshot')->get();

        return view('fleet::coordination.requests', compact('customerCancellations', 'customerCorrections', 'myCoordinationTasks', 'rows', 'filters', 'clients', 'branches', 'statusCounts', 'canCreate', 'canApprove'));
    }

    public function requestCreate(Request $request, FleetAccess $access): View
    {
        $tenant = $access->tenant($request);
        $clients = Client::query()->where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('commercial_name')->get();
        $requestingArea = $this->requestingArea($request, $tenant->id);

        return view('fleet::coordination.request-form', compact('clients', 'requestingArea', 'tenant'));
    }

    public function clientPoints(Request $request, int $client, FleetAccess $access): JsonResponse
    {
        $tenant = $access->tenant($request);
        $selected = Client::query()->where('tenant_id', $tenant->id)->findOrFail($client);
        $history = ServiceRequest::where('tenant_id', $tenant->id)->where('client_id', $selected->id)->where('is_test', false)->whereNotIn('status', ['cancelled'])->whereNotNull('packages')->orderByDesc('id')->get(['client_branch_id', 'packages', 'actual_packages', 'contact_name_snapshot', 'contact_phone_snapshot']);
        $last = $history->unique('client_branch_id')->keyBy('client_branch_id');
        $lastContactNames = $history->filter(fn ($ret): bool => filled($ret->contact_name_snapshot))->unique('client_branch_id')->keyBy('client_branch_id');
        $lastContactPhones = $history->filter(fn ($ret): bool => filled($ret->contact_phone_snapshot))->unique('client_branch_id')->keyBy('client_branch_id');
        $lastScheduled = ServiceRequest::where('tenant_id', $tenant->id)->where('client_id', $selected->id)
            ->where('is_test', false)->whereNotIn('status', ['cancelled'])
            ->where(function ($query): void {
                $query->whereNotNull('scheduled_at')->orWhereIn('status', ['scheduled', 'rescheduled', 'retired']);
            })->orderByDesc('scheduled_at')->orderByDesc('id')
            ->get(['client_branch_id', 'window_start', 'window_end', 'shift'])->unique('client_branch_id')->keyBy('client_branch_id');
        $points = ClientBranch::query()->with(['contacts' => fn ($query) => $query->where('is_active', true)->orderByDesc('is_primary')])
            ->where('client_id', $selected->id)->where('is_active', true)->orderBy('name')->get()
            ->map(fn (ClientBranch $point): array => [
                'id' => $point->id, 'name' => $point->name, 'suggested_packages' => isset($last[$point->id]) ? max(1, $last[$point->id]->actual_packages ?? $last[$point->id]->packages) : 5, 'address' => $point->address,
                'address_status' => $point->address_status, 'operational_emails' => $point->operational_emails,
                'commune' => $point->commune_name, 'reference' => $point->location_reference,
                'suggested_window_start' => isset($lastScheduled[$point->id]) ? substr($lastScheduled[$point->id]->window_start, 0, 5) : '00:00',
                'suggested_window_end' => isset($lastScheduled[$point->id]) ? substr($lastScheduled[$point->id]->window_end, 0, 5) : '00:00',
                'suggested_shift' => $lastScheduled[$point->id]->shift ?? 'AM',
                'suggested_contact_name' => $lastContactNames[$point->id]->contact_name_snapshot ?? $point->contacts->first()?->name ?? '',
                'suggested_contact_phone' => $lastContactPhones[$point->id]->contact_phone_snapshot ?? ($point->contacts->first()?->mobile_phone ?: $point->contacts->first()?->phone) ?? '',
                'contacts' => $point->contacts->map(fn ($contact): array => [
                    'id' => $contact->id, 'name' => $contact->name, 'email' => $contact->email, 'phone' => $contact->mobile_phone ?: $contact->phone,
                ])->all(),
            ]);

        return response()->json($points);
    }

    public function requestStore(Request $request, FleetAccess $access, CoordinationService $service): RedirectResponse
    {
        $tenant = $access->tenant($request);
        $data = $request->validate([
            'pickup_name' => ['nullable', 'string', 'max:255'],
            'commune_snapshot' => ['nullable', 'string', 'max:100'],
            'access_reference_snapshot' => ['nullable', 'string', 'max:2000'],
            'contact_name_snapshot' => ['nullable', 'string', 'max:255'],
            'contact_phone_snapshot' => ['nullable', 'string', 'max:50'],
            'operational_emails_snapshot' => ['nullable', 'string', 'max:2000'],
            'is_test' => ['nullable', 'boolean'],
            'route_notes' => ['nullable', 'string', 'max:2000'],
            'client_id' => ['required', 'integer'], 'client_branch_id' => ['required', 'integer'],
            'load_unit' => ['nullable', 'in:loose,pallets'],
            'pallet_type' => ['exclude_unless:load_unit,pallets', 'required', 'in:european,american'],
            'pallet_height_cm' => ['exclude_unless:load_unit,pallets', 'required', 'numeric', 'gt:0', 'max:999999.99'],
            'service_date' => ['required', 'date_format:Y-m-d'], 'shift' => ['required', 'in:AM,PM,AM/PM'],
            'window_start' => ['required', 'date_format:H:i'],
            'window_end' => ['required', 'date_format:H:i', 'after:window_start'],
            'requested_volume_m3' => ['nullable', 'numeric', 'gt:0', 'max:100000'], 'requested_weight_kg' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'packages' => ['required', 'integer', 'min:1'], 'material' => ['nullable', 'string', 'max:160'],
            'effective_address' => ['nullable', 'string', 'max:255'],
            'address_override_reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $client = Client::query()->where('tenant_id', $tenant->id)->where('is_active', true)->findOrFail($data['client_id']);
        $branch = ClientBranch::query()->where('client_id', $client->id)->findOrFail($data['client_branch_id']);
        $type = ServiceType::query()->where('is_active', true)->where('name', 'Retiro')->orderBy('id')->first();
        if ($type === null) {
            throw ValidationException::withMessages(['service_type_id' => 'Falta configurar el tipo de servicio Retiro en el maestro.']);
        }
        $data['requesting_area'] = $this->requestingArea($request, $tenant->id);
        $data['pickup_kind'] = 'Variable';
        $data['load_unit'] ??= 'loose';
        if (! empty($data['operational_emails_snapshot']) && app(RetNotificationService::class)->emails($data['operational_emails_snapshot']) === []) {
            throw ValidationException::withMessages(['operational_emails_snapshot' => 'Revisa los correos del retiro.']);
        }
        $data['pickup_name'] = null;
        $data['route_notes'] = null;
        $created = $service->createRequest($request->user(), $client, $branch, $type->id, $data);

        return redirect()->route('fleet.requests.show', ['serviceRequest' => $created, 'vista' => 'solicitante'])->with('status', 'Tu solicitud fue creada con el código '.$created->ret_code.($created->late_pickup_authorization ? '. Está pendiente de ambas aprobaciones por excepción de horario; aún no está agendada.' : ' y está pendiente de programación por Operaciones.'));
    }

    public function requestShow(Request $request, ServiceRequest $serviceRequest, FleetAccess $access): View
    {
        $this->authorizeTenant($request, $access, $serviceRequest->tenant_id, 'coordination.requests');
        $serviceRequest->load(['client', 'branch', 'fixedPickup', 'reprogrammings', 'driver', 'vehicle']);
        $notifications = DB::table('ret_notifications')->where('service_request_id', $serviceRequest->id)->latest('id')->get();
        $requester = User::find($serviceRequest->requested_by_user_id);
        $coordinationTasks = DB::table('ret_coordination_tasks')->leftJoin('users', 'users.id', '=', 'ret_coordination_tasks.assigned_to_user_id')->where('service_request_id', $serviceRequest->id)->where('status', 'pending')->select('ret_coordination_tasks.*', 'users.name as assigned_name')->get();
        $customerNotifications = DB::table('ret_customer_notifications')->where('service_request_id', $serviceRequest->id)->latest('id')->get();
        $canResolveTasks = $access->allows($request->user(), $access->tenant($request), 'admin.permissions', 3);
        $drivers = app(SchedulingService::class)->drivers();
        $vehicles = app(SchedulingService::class)->pool();
        $events = DB::table('service_request_events')->where('service_request_id', $serviceRequest->id)->leftJoin('users', 'users.id', '=', 'service_request_events.actor_user_id')->select('service_request_events.*', 'users.name as actor_name')->orderBy('service_request_events.created_at')->orderBy('service_request_events.id')->get();
        $cancelledByCustomer = $serviceRequest->status === 'cancelled' && $events->contains('action', 'customer_cancel');
        $canEdit = $access->allows($request->user(), $access->tenant($request), 'coordination.requests', 2);
        $canSchedule = $access->allows($request->user(), $access->tenant($request), 'operations.scheduling', 2)
            && $access->allows($request->user(), $access->tenant($request), 'operations.vehicle_pool');
        $requesterView = $request->query('vista') === 'solicitante' || ! $canSchedule;
        $showOperations = ! $requesterView;
        $canReprogram = $access->allows($request->user(), $access->tenant($request), 'coordination.reprogramming.request', 2);
        $canApprove = $access->allows($request->user(), $access->tenant($request), 'coordination.reprogramming.approve', 3);

        $lateAuthorization = $serviceRequest->late_pickup_authorization;
        $lateAreas = collect(['operations', 'commercial'])->filter(fn ($area): bool => app(LatePickupAuthorization::class)->canDecide($request->user(), $access->tenant($request), $area));

        return view('fleet::coordination.request-show', compact('cancelledByCustomer', 'coordinationTasks', 'customerNotifications', 'canResolveTasks', 'requesterView', 'showOperations', 'lateAuthorization', 'lateAreas', 'serviceRequest', 'events', 'canEdit', 'canSchedule', 'canReprogram', 'canApprove', 'notifications', 'requester', 'drivers', 'vehicles'));
    }

    public function latePickupIndex(Request $request, FleetAccess $access): View
    {
        $tenant = $access->tenant($request);
        $lateAreas = collect(['operations', 'commercial'])->filter(fn ($area): bool => app(LatePickupAuthorization::class)->canDecide($request->user(), $tenant, $area));
        $canReview = $access->allows($request->user(), $tenant, 'coordination.requests');
        abort_unless($canReview || $lateAreas->isNotEmpty(), 403);
        $rows = ServiceRequest::query()->where('tenant_id', $tenant->id)->whereNotNull('late_pickup_authorization')
            ->whereIn('status', ['requested', 'correction_requested', 'reprogramming_pending'])->latest('id')->paginate(50);

        $policyRevision = (int) app(LatePickupAuthorization::class)->settings($tenant->id)->pickup_authorization_revision;

        return view('fleet::coordination.late-pickups', compact('rows', 'lateAreas', 'canReview', 'policyRevision'));
    }

    public function requestLatePickup(Request $request, ServiceRequest $serviceRequest, FleetAccess $access, LatePickupAuthorization $late): RedirectResponse
    {
        $this->authorizeTenant($request, $access, $serviceRequest->tenant_id, 'coordination.requests', 2);
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000']]);
        $late->request($serviceRequest, $request->user(), $data['date'], $data['reason']);

        return back()->with('status', 'Excepción solicitada. Pendiente de las dos aprobaciones.');
    }

    public function decideLatePickup(Request $request, ServiceRequest $serviceRequest, FleetAccess $access, LatePickupAuthorization $late): RedirectResponse
    {
        abort_unless($serviceRequest->tenant_id === $access->tenant($request)->id, 404);
        $data = $request->validate(['area' => ['required', 'in:operations,commercial'], 'decision' => ['required', 'in:approved,rejected'], 'notes' => ['required', 'string', 'max:2000']]);
        $late->decide($serviceRequest, $request->user(), $access->tenant($request), $data['area'], $data['decision'], $data['notes']);

        return back()->with('status', 'Decisión de excepción registrada.');
    }

    public function schedule(Request $request, ServiceRequest $serviceRequest, FleetAccess $access, SchedulingService $scheduling): RedirectResponse
    {
        $this->authorizeTenant($request, $access, $serviceRequest->tenant_id, 'operations.scheduling', 2);
        abort_unless($access->allows($request->user(), $access->tenant($request), 'operations.vehicle_pool'), 403);
        $scheduling->schedule($serviceRequest, $request->user());

        return back()->with('status', 'RET agendado. Revisa el estado del correo de confirmación.');
    }

    public function retire(Request $request, ServiceRequest $serviceRequest, FleetAccess $access, CoordinationService $service): RedirectResponse
    {
        $this->authorizeTenant($request, $access, $serviceRequest->tenant_id, 'coordination.requests', 2);
        abort_unless(in_array($serviceRequest->status, ['scheduled', 'rescheduled'], true), 409);
        DB::transaction(function () use ($serviceRequest, $request, $service): void {
            $serviceRequest = ServiceRequest::lockForUpdate()->findOrFail($serviceRequest->id);
            abort_unless(in_array($serviceRequest->status, ['scheduled', 'rescheduled'], true), 409);
            $serviceRequest->update(['status' => 'retired', 'retired_at' => now(), 'driver_token_hash' => null, 'driver_token_expires_at' => null]);
            $service->event($serviceRequest, $request->user(), 'retired_manual');
        });

        return back()->with('status', 'Retiro registrado manualmente.');
    }

    public function reprogramRequest(Request $request, ServiceRequest $serviceRequest, FleetAccess $access, CoordinationService $service): RedirectResponse
    {
        $this->authorizeTenant($request, $access, $serviceRequest->tenant_id, 'coordination.reprogramming.request', 2);
        abort_unless(in_array($serviceRequest->status, ['scheduled', 'rescheduled'], true), 409);
        $data = $request->validate([
            'change_items' => ['sometimes', 'array', 'min:1'], 'change_items.*' => ['string', 'in:schedule,resources'],
            'reason' => ['required', 'string', 'max:2000'],
            'proposed_date' => ['required', 'date_format:Y-m-d'],
            'proposed_driver_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'proposed_vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'customer_response' => ['nullable', 'string', 'max:2000'],
            'proposed_window_start' => ['required', 'date_format:H:i'],
            'proposed_window_end' => ['required', 'date_format:H:i', 'after:proposed_window_start'],
        ]);
        DB::transaction(function () use ($serviceRequest, $request, $data, $service): void {
            $current = ServiceRequest::query()->lockForUpdate()->findOrFail($serviceRequest->id);
            if (! in_array($current->status, ['scheduled', 'rescheduled'], true)
                || $current->reprogrammings()->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['reason' => 'Ya existe una reprogramación pendiente o el RET cambió de estado.']);
            }
            if (isset($data['change_items']) && ! in_array('schedule', $data['change_items'], true)) {
                $data['proposed_date'] = $current->service_date->toDateString();
                $data['proposed_window_start'] = substr($current->window_start, 0, 5);
                $data['proposed_window_end'] = substr($current->window_end, 0, 5);
            }
            if (isset($data['change_items']) && ! in_array('resources', $data['change_items'], true)) {
                $data['proposed_driver_user_id'] = $current->driver_user_id;
                $data['proposed_vehicle_id'] = $current->vehicle_id;
            }
            ServiceRequestReprogramming::create([
                'service_request_id' => $serviceRequest->id, 'status' => 'pending',
                'reason' => $data['reason'], 'previous_date' => $current->service_date,
                'proposed_date' => $data['proposed_date'],
                'previous_window_start' => $current->window_start,
                'previous_window_end' => $current->window_end,
                'proposed_window_start' => $data['proposed_window_start'],
                'proposed_window_end' => $data['proposed_window_end'],
                'requested_by_user_id' => $request->user()->id,
                'previous_driver_user_id' => $current->driver_user_id, 'previous_vehicle_id' => $current->vehicle_id,
                'proposed_driver_user_id' => $data['proposed_driver_user_id'] ?? $current->driver_user_id, 'proposed_vehicle_id' => $data['proposed_vehicle_id'] ?? $current->vehicle_id,
                'customer_response' => $data['customer_response'] ?? null,
            ]);
            $current->update(['status' => 'reprogramming_pending']);
            $service->event($current, $request->user(), 'reprogramming_requested', $data);
        });

        return back()->with('status', 'Reprogramación pendiente de Postventa.');
    }

    public function reprogramIndex(Request $request, FleetAccess $access): View
    {
        $tenant = $access->tenant($request);
        $rows = ServiceRequestReprogramming::query()->with('serviceRequest.client')
            ->where('status', 'pending')->whereHas('serviceRequest', fn ($query) => $query->where('tenant_id', $tenant->id))
            ->orderBy('created_at')->get();

        return view('fleet::coordination.reprogrammings', compact('rows'));
    }

    public function reprogramDecide(Request $request, ServiceRequestReprogramming $reprogramming, FleetAccess $access, CoordinationService $service, SchedulingService $scheduling): RedirectResponse
    {
        $serviceRequest = $reprogramming->serviceRequest;
        $this->authorizeTenant($request, $access, $serviceRequest->tenant_id, 'coordination.reprogramming.approve', 3);
        abort_unless($reprogramming->status === 'pending' && $serviceRequest->status === 'reprogramming_pending', 409);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'customer_response' => ['required', 'string', 'max:2000'],
            'decision_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($serviceRequest, $reprogramming, $request, $data, $service, $scheduling): void {
            $serviceRequest = ServiceRequest::lockForUpdate()->findOrFail($serviceRequest->id);
            $reprogramming = ServiceRequestReprogramming::lockForUpdate()->findOrFail($reprogramming->id);
            abort_unless($reprogramming->status === 'pending' && $serviceRequest->status === 'reprogramming_pending', 409);
            if ($data['decision'] === 'approved') {
                $serviceRequest->driver_user_id = $reprogramming->proposed_driver_user_id ?? $serviceRequest->driver_user_id;
                $serviceRequest->vehicle_id = $reprogramming->proposed_vehicle_id ?? $serviceRequest->vehicle_id;
                if (! $serviceRequest->driver_user_id || ! $serviceRequest->vehicle_id) {
                    throw ValidationException::withMessages(['ret' => 'Faltan conductor o vehículo para aprobar la reprogramación.']);
                }
                $scheduling->validateResources($serviceRequest->driver_user_id, $serviceRequest->vehicle_id);
                $scheduling->assertCapacity($serviceRequest, $serviceRequest->driver_user_id, $serviceRequest->vehicle_id,
                    $reprogramming->proposed_date->toDateString(), $reprogramming->proposed_window_start, $reprogramming->proposed_window_end);
            }
            $reprogramming->update([
                'status' => $data['decision'], 'customer_response' => $data['customer_response'],
                'decision_notes' => $data['decision_notes'] ?? null,
                'decided_by_user_id' => $request->user()->id, 'decided_at' => now(),
            ]);
            if ($data['decision'] === 'approved') {
                $serviceRequest->update([
                    'service_date' => $reprogramming->proposed_date,
                    'window_start' => $reprogramming->proposed_window_start,
                    'window_end' => $reprogramming->proposed_window_end,
                    'shift' => (int) substr($reprogramming->proposed_window_start, 0, 2) < 12 ? 'AM' : 'PM',
                    'status' => 'rescheduled', 'email_status' => 'not_prepared',
                    'email_subject' => null, 'email_body' => null,
                ]);
                DB::table('service_request_overload_authorizations')->where('service_request_id', $serviceRequest->id)
                    ->where(function ($query) use ($reprogramming): void {
                        $query->where('service_date', '!=', $reprogramming->proposed_date->toDateString())
                            ->orWhere('window_start', '!=', substr($reprogramming->proposed_window_start, 0, 5).':00')
                            ->orWhere('window_end', '!=', substr($reprogramming->proposed_window_end, 0, 5).':00');
                    })->whereIn('status', ['pending', 'approved'])->update(['status' => 'expired', 'updated_at' => now()]);
                $scheduling->prepareEmail($serviceRequest);
            } else {
                $wasRescheduled = $serviceRequest->reprogrammings()->where('status', 'approved')->exists();
                $serviceRequest->update(['status' => $wasRescheduled ? 'rescheduled' : 'scheduled']);
            }
            $service->event($serviceRequest, $request->user(), 'reprogramming_'.$data['decision'], [
                'reason' => $reprogramming->reason,
                'previous_date' => $reprogramming->previous_date->toDateString(),
                'proposed_date' => $reprogramming->proposed_date->toDateString(),
                'previous_window' => [$reprogramming->previous_window_start, $reprogramming->previous_window_end],
                'proposed_window' => [$reprogramming->proposed_window_start, $reprogramming->proposed_window_end],
                'previous_driver_user_id' => $reprogramming->previous_driver_user_id,
                'proposed_driver_user_id' => $reprogramming->proposed_driver_user_id,
                'previous_vehicle_id' => $reprogramming->previous_vehicle_id,
                'proposed_vehicle_id' => $reprogramming->proposed_vehicle_id,
                'decision_notes' => $data['decision_notes'] ?? null,
                'customer_response' => $data['customer_response'],
            ]);
        });

        return redirect()->route('fleet.requests.show', $serviceRequest)->with('status', 'Decisión de Postventa registrada.');
    }

    private function authorizeTenant(Request $request, FleetAccess $access, int $tenantId, string $permission, int $level = 1): void
    {
        $tenant = $access->tenant($request);
        abort_unless($tenant !== null && $tenant->id === $tenantId, 404);
        abort_unless($access->allows($request->user(), $tenant, $permission, $level), 403);
    }

    public function clientServices(Request $request, int $client, FleetAccess $access): JsonResponse
    {
        $selected = Client::where('tenant_id', $access->tenant($request)->id)->where('is_active', true)->findOrFail($client);
        $ids = DB::table('client_service_type')->where('client_id', $selected->id)->where('is_active', true)->pluck('service_type_id');

        return response()->json(['legal_name' => $selected->legal_name, 'services' => ServiceType::where('is_active', true)->whereIn('id', $ids)->get(['id', 'name'])]);
    }

    public function fixedStore(Request $request, FleetAccess $access): RedirectResponse
    {
        $tenant = $access->tenant($request);
        $data = $request->validate([
            'client_id' => ['required', 'integer'], 'client_branch_id' => ['required', 'integer'], 'service_type_id' => ['required', 'integer'],
            'weekdays' => ['required', 'array', 'min:1'], 'weekdays.*' => ['integer', 'between:1,7'], 'shift' => ['required', 'in:AM,PM,AM/PM'],
            'window_start' => ['required', 'date_format:H:i'], 'window_end' => ['required', 'date_format:H:i', 'after:window_start'],
            'material' => ['required', 'string', 'max:160'], 'usual_packages' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'string', 'max:100'], 'instructions' => ['nullable', 'string', 'max:2000'],
            'usual_driver_user_id' => ['nullable', 'integer', 'exists:users,id'], 'usual_vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
        ]);
        $client = Client::where('tenant_id', $tenant->id)->where('is_active', true)->findOrFail($data['client_id']);
        $point = ClientBranch::where('client_id', $client->id)->where('is_active', true)->findOrFail($data['client_branch_id']);
        ServiceType::where('is_active', true)->findOrFail($data['service_type_id']);
        if (! DB::table('client_service_type')->where('client_id', $client->id)->where('service_type_id', $data['service_type_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['service_type_id' => 'Configura el servicio del cliente antes de crear la regla.']);
        }
        FixedPickup::create($data + ['tenant_id' => $tenant->id, 'source_client_name' => $client->commercial_name, 'source_point_name' => $point->name, 'association_status' => 'linked', 'operational_emails' => $point->operational_emails, 'operational_email_pending' => $point->operational_email_pending, 'is_active' => true, 'is_test' => app()->environment(['local', 'testing']) && config('fleet.test_tools_enabled') && (config('fleet.auto_test_mode') || $request->boolean('is_test'))]);

        return back()->with('status', 'Regla de retiro fijo creada.');
    }

    public function requestUpdate(Request $request, ServiceRequest $serviceRequest, FleetAccess $access, CoordinationService $service): RedirectResponse
    {
        $this->authorizeTenant($request, $access, $serviceRequest->tenant_id, 'coordination.requests', 2);
        $data = $request->validate([
            'change_items' => ['sometimes', 'array', 'min:1'], 'change_items.*' => ['string', 'in:schedule,packages,address,dimensions,notes'],
            'pickup_name' => ['required', 'string', 'max:255'], 'service_date' => ['required', 'date_format:Y-m-d'],
            'window_start' => ['required', 'date_format:H:i'], 'window_end' => ['required', 'date_format:H:i', 'after:window_start'],
            'shift' => ['required', 'in:AM,PM,AM/PM'], 'requested_volume_m3' => ['nullable', 'numeric', 'gt:0', 'max:100000'], 'requested_weight_kg' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'packages' => ['required', 'integer', 'min:1'], 'material' => ['nullable', 'string', 'max:160'],
            'effective_address' => ['required', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000'],
            'access_reference_snapshot' => ['nullable', 'string', 'max:2000'], 'contact_name_snapshot' => ['nullable', 'string', 'max:255'], 'contact_phone_snapshot' => ['nullable', 'string', 'max:50'],
            'reason' => ['required', 'string', 'max:2000'], 'route_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($serviceRequest, $request, $service, $data): void {
            $ret = ServiceRequest::lockForUpdate()->findOrFail($serviceRequest->id);
            abort_unless(in_array($ret->status, ['requested', 'correction_requested'], true), 409, 'Los datos operativos están protegidos desde el agendamiento.');
            if (isset($data['change_items'])) {
                $groups = ['schedule' => ['service_date', 'window_start', 'window_end', 'shift'], 'packages' => ['packages'], 'address' => ['effective_address', 'access_reference_snapshot'], 'dimensions' => ['requested_volume_m3', 'requested_weight_kg'], 'notes' => ['notes']];
                $allowed = collect($data['change_items'])->flatMap(fn ($item) => $groups[$item])->all();
                foreach (array_keys($data) as $field) {
                    if (! in_array($field, array_merge($allowed, ['reason', 'change_items']), true)) {
                        unset($data[$field]);
                    }
                }
            }
            $before = $ret->only(array_keys($data));
            $reason = $data['reason'];
            $changeItems = $data['change_items'] ?? [];
            unset($data['reason'], $data['change_items']);
            if (empty($data['material'])) {
                unset($data['material']);
            }
            $ret->update($data + ['status' => 'requested']);
            $service->event($ret, $request->user(), 'edited', ['previous' => $before, 'new' => $data, 'reason' => $reason, 'change_items' => $changeItems]);
        }, 3);

        return back()->with('status', 'Solicitud actualizada; fecha original conservada.');
    }

    public function cancel(Request $request, ServiceRequest $serviceRequest, FleetAccess $access, CoordinationService $service): RedirectResponse
    {
        $this->authorizeTenant($request, $access, $serviceRequest->tenant_id, 'coordination.requests', 2);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $serviceRequest, $service, $data): void {
            $ret = ServiceRequest::lockForUpdate()->findOrFail($serviceRequest->id);
            abort_if(in_array($ret->status, ['retired', 'cancelled']), 409);
            $before = $ret->status;
            DB::table('ret_coordination_tasks')->where('service_request_id', $ret->id)->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);
            $ret->update(['status' => 'cancelled', 'cancellation_reason' => $data['reason'], 'cancelled_at' => now(), 'driver_token_hash' => null, 'driver_token_expires_at' => null]);
            $ret->reprogrammings()->where('status', 'pending')->update(['status' => 'cancelled', 'decided_by_user_id' => $request->user()->id, 'decided_at' => now(), 'decision_notes' => $data['reason']]);
            DB::table('service_request_overload_authorizations')->where('service_request_id', $ret->id)->whereIn('status', ['pending', 'approved'])->update(['status' => 'cancelled', 'updated_at' => now()]);
            DB::table('ret_notifications')->where('service_request_id', $ret->id)->whereIn('status', ['prepared', 'error'])->update(['status' => 'superseded', 'updated_at' => now()]);
            $service->event($ret, $request->user(), 'cancelled', ['previous' => $before, 'reason' => $data['reason']]);
        }, 3);

        return back()->with('status', 'Retiro anulado. Historial conservado.');
    }

    public function decision(Request $request, ServiceRequest $serviceRequest, FleetAccess $access, SchedulingService $scheduling, CoordinationService $service): RedirectResponse
    {
        $this->authorizeTenant($request, $access, $serviceRequest->tenant_id, 'operations.scheduling', 2);
        $data = $request->validate([
            'decision' => ['required', 'in:scheduled,correction_requested,cancelled'],
            'operations_notes' => ['required_unless:decision,scheduled', 'nullable', 'string', 'max:2000'],
            'customer_date_confirmed' => ['nullable', 'boolean'],
            'weight_warning_acknowledged' => ['nullable', 'boolean'],
            'service_date' => ['required_if:decision,scheduled', 'nullable', 'date_format:Y-m-d'],
            'shift' => ['required_if:decision,scheduled', 'nullable', 'in:AM,PM,AM/PM'],
            'window_start' => ['required_if:decision,scheduled', 'nullable', 'date_format:H:i'],
            'window_end' => ['required_if:decision,scheduled', 'nullable', 'date_format:H:i', 'after:window_start'],
            'driver_user_id' => ['required_if:decision,scheduled', 'nullable', 'integer'],
            'vehicle_id' => ['required_if:decision,scheduled', 'nullable', 'integer'],
        ]);
        DB::transaction(function () use ($request, $serviceRequest, $access, $data, $scheduling, $service): void {
            $ret = ServiceRequest::lockForUpdate()->findOrFail($serviceRequest->id);
            abort_unless(in_array($ret->status, ['requested', 'correction_requested'], true), 409, 'La agenda ya está protegida. Utiliza la solicitud de reprogramación.');
            $before = $ret->only(['status', 'service_date', 'window_start', 'window_end', 'shift', 'driver_user_id', 'vehicle_id']);
            if ($data['decision'] === 'scheduled') {
                abort_unless($access->allows($request->user(), $access->tenant($request), 'operations.vehicle_pool'), 403);
                abort_if($ret->status === 'correction_requested', 409, 'El área solicitante debe corregir y reenviar la solicitud.');
                $authorization = $ret->late_pickup_authorization;
                $wasRejected = ($authorization['operations']['status'] ?? null) === 'rejected' || ($authorization['commercial']['status'] ?? null) === 'rejected';
                if ($wasRejected && $data['service_date'] !== $authorization['date'] && ! ($data['customer_date_confirmed'] ?? false)) {
                    throw ValidationException::withMessages(['customer_date_confirmed' => 'Confirma con el cliente la nueva fecha antes de agendar después de un rechazo.']);
                }
                if ($wasRejected && $data['service_date'] !== $authorization['date']) {
                    $authorization['customer_date_confirmation'] = ['date' => $data['service_date'], 'actor_id' => $request->user()->id, 'confirmed_at' => now()->toIso8601String()];
                    $ret->update(['late_pickup_authorization' => $authorization]);
                }
                $ret->update(collect($data)->only(['service_date', 'window_start', 'window_end', 'shift', 'operations_notes'])->all());
                $scheduling->validateResources($data['driver_user_id'], $data['vehicle_id']);
                app(LatePickupAuthorization::class)->assertAllowed($ret, $data['service_date']);
                $vehicle = Vehicle::findOrFail($data['vehicle_id']);
                $warning = app(RetVehicleEligibility::class)->weightWarning($ret, $vehicle);
                $acknowledged = (bool) ($data['weight_warning_acknowledged'] ?? false);
                if ($warning !== null && ! $acknowledged) {
                    throw ValidationException::withMessages(['weight_warning_acknowledged' => $warning.' Confirma si deseas continuar o modifica el transporte.']);
                }
                $scheduling->assign($ret, $request->user(), $data['driver_user_id'], $data['vehicle_id'], null, $acknowledged);
                $scheduling->schedule($ret, $request->user(), $acknowledged);
                if ($warning !== null) {
                    $service->event($ret, $request->user(), 'weight_warning_confirmed', [
                        'warning' => $warning, 'vehicle_id' => $vehicle->id, 'plate' => $vehicle->plate,
                        'requested_weight_kg' => $ret->requested_weight_kg, 'payload_capacity_kg' => $vehicle->payload_capacity_kg,
                    ]);
                }
            } else {
                $ret->update(['status' => $data['decision'], 'operations_notes' => $data['operations_notes'],
                    'cancellation_reason' => $data['decision'] === 'cancelled' ? $data['operations_notes'] : null,
                    'cancelled_at' => $data['decision'] === 'cancelled' ? now() : null]);
                if ($data['decision'] === 'cancelled') {
                    DB::table('service_request_overload_authorizations')->where('service_request_id', $ret->id)->whereIn('status', ['pending', 'approved'])->update(['status' => 'cancelled', 'updated_at' => now()]);
                }
            }
            $service->event($ret, $request->user(), 'operations_decision', ['previous' => $before, 'decision' => $data['decision'], 'reason' => $data['operations_notes'] ?? null, 'customer_date_confirmed' => (bool) ($data['customer_date_confirmed'] ?? false)]);
        }, 3);

        return back()->with('status', 'Decisión de Operaciones guardada.');
    }

    private function requestingArea(Request $request, int $tenantId): string
    {
        if (filled($request->user()->area)) {
            return trim($request->user()->area);
        }

        $role = DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $request->user()->id)->value('role_code');

        return match ($role) {
            'commercial', 'comercial' => 'Comercial',
            'after_sales', 'postventa' => 'Postventa',
            'operations', 'operaciones' => 'Operaciones',
            'management', 'gerente', 'gerencia' => 'Gerencia',
            'finance', 'finanzas' => 'Finanzas',
            'administrator', 'administrador' => 'Administración',
            'app_conductor', 'conductor', 'conductor_a5' => 'Operaciones',
            default => 'Sin área registrada',
        };
    }
}
