<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\CoordinationService;
use App\Fleet\FleetAccess;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientBranch;
use App\Models\FixedPickup;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestReprogramming;
use App\Models\ServiceType;
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
        $selectedDate = Carbon::parse($request->validate(['fecha' => ['nullable', 'date_format:Y-m-d']])['fecha'] ?? now()->toDateString());
        $rows = FixedPickup::query()->with(['client', 'branch'])->where('tenant_id', $tenant->id)->orderBy('client_id')->orderBy('id')->get();
        $created = ServiceRequest::query()->where('tenant_id', $tenant->id)->whereNotNull('fixed_occurrence_key')
            ->where('fixed_occurrence_key', 'like', '%|'.$selectedDate->toDateString().'|%')
            ->pluck('fixed_occurrence_key')->flip();
        $statuses = $rows->mapWithKeys(function (FixedPickup $item) use ($selectedDate, $created): array {
            $key = $item->id.'|'.$selectedDate->toDateString().'|'.$item->window_start.'|'.$item->window_end;
            $status = match (true) {
                ! $item->is_active => 'Inactivo',
                $item->association_status !== 'linked' => 'Punto pendiente',
                $item->branch?->address_status !== 'ready' => 'Dirección pendiente',
                isset($created[$key]) => 'En solicitudes',
                ! in_array($selectedDate->dayOfWeekIso, $item->weekdays ?? [], true) => 'Otro día',
                default => 'Habilitado',
            };

            return [$item->id => $status];
        });

        return view('fleet::coordination.fixed', compact('rows', 'statuses', 'selectedDate'));
    }

    public function fixedUpdate(Request $request, FixedPickup $fixedPickup, FleetAccess $access): RedirectResponse
    {
        $this->authorizeTenant($request, $access, $fixedPickup->tenant_id, 'coordination.fixed-pickups', 2);
        $data = $request->validate([
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'shift' => ['required', 'in:AM,PM'],
            'window_start' => ['required', 'date_format:H:i'],
            'window_end' => ['required', 'date_format:H:i', 'after:window_start'],
            'material' => ['required', 'string', 'max:160'],
            'usual_packages' => ['nullable', 'integer', 'min:1'],
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
                && ! ServiceRequest::query()->where('fixed_occurrence_key', $item->id.'|'.$date->toDateString().'|'.$item->window_start.'|'.$item->window_end)->exists())->modelKeys()
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
                    'packages' => (int) $packages, 'material' => $item->material,
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
            'ret' => ['nullable', 'string', 'max:32'], 'fecha' => ['nullable', 'date_format:Y-m-d'],
            'client_id' => ['nullable', 'integer'], 'branch_id' => ['nullable', 'integer'],
            'estado' => ['nullable', 'in:requested,scheduled,reprogramming_pending,rescheduled,retired'],
        ]);
        $rows = ServiceRequest::query()->with(['client', 'branch'])->where('tenant_id', $tenant->id)
            ->when(filled($filters['ret'] ?? null), fn ($query) => $query->where('ret_code', 'like', '%'.$filters['ret'].'%'))
            ->when(filled($filters['fecha'] ?? null), fn ($query) => $query->whereDate('service_date', $filters['fecha']))
            ->when(filled($filters['client_id'] ?? null), fn ($query) => $query->where('client_id', $filters['client_id']))
            ->when(filled($filters['branch_id'] ?? null), fn ($query) => $query->where('client_branch_id', $filters['branch_id']))
            ->when(filled($filters['estado'] ?? null), fn ($query) => $query->where('status', $filters['estado']))
            ->orderByDesc('service_date')->orderByDesc('id')->paginate(50)->withQueryString();
        $clients = Client::query()->where('tenant_id', $tenant->id)->orderBy('commercial_name')->get();
        $branches = ClientBranch::query()->whereIn('client_id', $clients->modelKeys())->orderBy('name')->get();

        return view('fleet::coordination.requests', compact('rows', 'filters', 'clients', 'branches'));
    }

    public function requestCreate(Request $request, FleetAccess $access): View
    {
        $tenant = $access->tenant($request);
        $clients = Client::query()->where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('commercial_name')->get();
        $serviceTypes = ServiceType::query()->where('is_active', true)->orderBy('service_code')->get();

        return view('fleet::coordination.request-form', compact('clients', 'serviceTypes'));
    }

    public function clientPoints(Request $request, int $client, FleetAccess $access): JsonResponse
    {
        $tenant = $access->tenant($request);
        $selected = Client::query()->where('tenant_id', $tenant->id)->findOrFail($client);
        $points = ClientBranch::query()->with(['contacts' => fn ($query) => $query->where('is_active', true)])
            ->where('client_id', $selected->id)->where('is_active', true)->orderBy('name')->get()
            ->map(fn (ClientBranch $point): array => [
                'id' => $point->id, 'name' => $point->name, 'address' => $point->address,
                'address_status' => $point->address_status, 'operational_emails' => $point->operational_emails,
                'contacts' => $point->contacts->map(fn ($contact): array => [
                    'id' => $contact->id, 'name' => $contact->name, 'email' => $contact->email,
                ])->all(),
            ]);

        return response()->json($points);
    }

    public function requestStore(Request $request, FleetAccess $access, CoordinationService $service): RedirectResponse
    {
        $tenant = $access->tenant($request);
        $data = $request->validate([
            'client_id' => ['required', 'integer'], 'client_branch_id' => ['required', 'integer'],
            'service_type_id' => ['required', 'integer', 'exists:service_types,id'],
            'service_date' => ['required', 'date_format:Y-m-d'], 'shift' => ['required', 'in:AM,PM'],
            'window_start' => ['required', 'date_format:H:i'],
            'window_end' => ['required', 'date_format:H:i', 'after:window_start'],
            'packages' => ['required', 'integer', 'min:1'], 'material' => ['required', 'string', 'max:160'],
            'effective_address' => ['nullable', 'string', 'max:255'],
            'address_override_reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $client = Client::query()->where('tenant_id', $tenant->id)->where('is_active', true)->findOrFail($data['client_id']);
        $branch = ClientBranch::query()->where('client_id', $client->id)->findOrFail($data['client_branch_id']);
        $type = ServiceType::query()->where('is_active', true)->findOrFail($data['service_type_id']);
        $configuredTypes = DB::table('client_service_type')->where('client_id', $client->id)->where('is_active', true)->pluck('service_type_id');
        if ($configuredTypes->isNotEmpty() && ! $configuredTypes->contains($type->id)) {
            throw ValidationException::withMessages(['service_type_id' => 'El servicio no está habilitado para este cliente.']);
        }
        $created = $service->createRequest($request->user(), $client, $branch, $type->id, $data);

        return redirect()->route('fleet.requests.show', $created)->with('status', $created->ret_code.' creado.');
    }

    public function requestShow(Request $request, ServiceRequest $serviceRequest, FleetAccess $access): View
    {
        $this->authorizeTenant($request, $access, $serviceRequest->tenant_id, 'coordination.requests');
        $serviceRequest->load(['client', 'branch', 'fixedPickup', 'reprogrammings']);
        $events = DB::table('service_request_events')->where('service_request_id', $serviceRequest->id)->orderBy('created_at')->orderBy('id')->get();
        $canEdit = $access->allows($request->user(), $access->tenant($request), 'coordination.requests', 2);
        $canReprogram = $access->allows($request->user(), $access->tenant($request), 'coordination.reprogramming.request', 2);
        $canApprove = $access->allows($request->user(), $access->tenant($request), 'coordination.reprogramming.approve', 3);

        return view('fleet::coordination.request-show', compact('serviceRequest', 'events', 'canEdit', 'canReprogram', 'canApprove'));
    }

    public function schedule(Request $request, ServiceRequest $serviceRequest, FleetAccess $access, CoordinationService $service): RedirectResponse
    {
        $this->authorizeTenant($request, $access, $serviceRequest->tenant_id, 'coordination.requests', 2);
        abort_unless($serviceRequest->status === 'requested', 409);
        DB::transaction(function () use ($serviceRequest, $request, $service): void {
            $serviceRequest->update(['status' => 'scheduled', 'scheduled_at' => now()]);
            $service->event($serviceRequest, $request->user(), 'scheduled');
        });

        return back()->with('status', 'RET agendado. La confirmación por correo sigue pendiente de conductor y patente.');
    }

    public function retire(Request $request, ServiceRequest $serviceRequest, FleetAccess $access, CoordinationService $service): RedirectResponse
    {
        $this->authorizeTenant($request, $access, $serviceRequest->tenant_id, 'coordination.requests', 2);
        abort_unless(in_array($serviceRequest->status, ['scheduled', 'rescheduled'], true), 409);
        DB::transaction(function () use ($serviceRequest, $request, $service): void {
            $serviceRequest->update(['status' => 'retired', 'retired_at' => now()]);
            $service->event($serviceRequest, $request->user(), 'retired_manual');
        });

        return back()->with('status', 'Retiro registrado manualmente.');
    }

    public function reprogramRequest(Request $request, ServiceRequest $serviceRequest, FleetAccess $access, CoordinationService $service): RedirectResponse
    {
        $this->authorizeTenant($request, $access, $serviceRequest->tenant_id, 'coordination.reprogramming.request', 2);
        abort_unless(in_array($serviceRequest->status, ['scheduled', 'rescheduled'], true), 409);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'proposed_date' => ['required', 'date_format:Y-m-d'],
            'proposed_window_start' => ['required', 'date_format:H:i'],
            'proposed_window_end' => ['required', 'date_format:H:i', 'after:proposed_window_start'],
        ]);
        DB::transaction(function () use ($serviceRequest, $request, $data, $service): void {
            $current = ServiceRequest::query()->lockForUpdate()->findOrFail($serviceRequest->id);
            if (! in_array($current->status, ['scheduled', 'rescheduled'], true)
                || $current->reprogrammings()->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['reason' => 'Ya existe una reprogramación pendiente o el RET cambió de estado.']);
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

    public function reprogramDecide(Request $request, ServiceRequestReprogramming $reprogramming, FleetAccess $access, CoordinationService $service): RedirectResponse
    {
        $serviceRequest = $reprogramming->serviceRequest;
        $this->authorizeTenant($request, $access, $serviceRequest->tenant_id, 'coordination.reprogramming.approve', 3);
        abort_unless($reprogramming->status === 'pending' && $serviceRequest->status === 'reprogramming_pending', 409);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'customer_response' => ['required', 'string', 'max:2000'],
            'decision_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($serviceRequest, $reprogramming, $request, $data, $service): void {
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
                    'status' => 'rescheduled', 'email_status' => 'not_prepared',
                    'email_subject' => null, 'email_body' => null,
                ]);
            } else {
                $wasRescheduled = $serviceRequest->reprogrammings()->where('status', 'approved')->exists();
                $serviceRequest->update(['status' => $wasRescheduled ? 'rescheduled' : 'scheduled']);
            }
            $service->event($serviceRequest, $request->user(), 'reprogramming_'.$data['decision'], [
                'reason' => $reprogramming->reason,
                'previous_date' => $reprogramming->previous_date->toDateString(),
                'proposed_date' => $reprogramming->proposed_date->toDateString(),
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
}
