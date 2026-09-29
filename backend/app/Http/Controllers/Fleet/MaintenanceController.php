<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\FleetAccess;
use App\Fleet\MaintenanceService;
use App\Fleet\VehicleCatalog;
use App\Http\Controllers\Controller;
use App\Models\FleetDocument;
use App\Models\Provider;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaintenanceController extends Controller
{
    public function index(Request $request, FleetAccess $access, MaintenanceService $service): View
    {
        $serviceTenant = $access->tenant($request);
        $shared = $this->shared($request, $access);
        $visibleTenants = Tenant::query()->get()->filter(fn (Tenant $tenant): bool => $tenant->id === $serviceTenant->id || ($shared && $access->allows($request->user(), $tenant, 'operations.maintenance')));
        $companyCodes = $visibleTenants->pluck('code')->all();
        $filters = $request->validate([
            'empresa' => ['nullable', 'in:4N,PMCB'],
            'estado' => ['nullable', Rule::in(array_keys(MaintenanceService::STATUSES))],
            'patente' => ['nullable', 'string', 'max:30'],
        ]);
        if (isset($filters['empresa']) && ! in_array($filters['empresa'], $companyCodes, true)) {
            abort(403);
        }

        $rows = VehicleMaintenance::query()->with(['vehicle', 'tenant', 'provider', 'responsible'])
            ->whereIn('tenant_id', $visibleTenants->pluck('id'))
            ->when(isset($filters['empresa']), fn ($query) => $query->whereHas('tenant', fn ($tenant) => $tenant->where('code', $filters['empresa'])))
            ->when(isset($filters['estado']), fn ($query) => $query->where('status', $filters['estado']))
            ->when(filled($filters['patente'] ?? null), fn ($query) => $query->whereHas('vehicle', fn ($vehicle) => $vehicle->where('plate', 'like', '%'.trim($filters['patente']).'%')))
            ->orderByDesc('scheduled_at')->orderByDesc('id')->get();
        $latestClosedIds = VehicleMaintenance::query()->where('status', 'closed')
            ->orderByDesc('closed_at')->orderByDesc('id')->get()
            ->unique(fn ($maintenance) => $maintenance->vehicle_id.'|'.$maintenance->maintenance_type)
            ->modelKeys();
        $settings = DB::table('fleet_maintenance_settings')->get()->keyBy('tenant_id');

        return view('fleet::maintenance.index', compact('rows', 'filters', 'shared', 'serviceTenant', 'companyCodes', 'latestClosedIds', 'settings', 'service'));
    }

    public function create(Request $request, FleetAccess $access, VehicleCatalog $catalog): View
    {
        $vehicleId = $request->validate(['vehicle_id' => ['required', 'integer', 'exists:vehicles,id']])['vehicle_id'];
        $vehicle = Vehicle::findOrFail($vehicleId);
        $owner = $catalog->owner($vehicle, Tenant::all());
        abort_unless($owner && $this->canViewTenant($request, $access, $owner), 404);
        abort_unless($access->allows($request->user(), $owner, 'operations.maintenance', 2), 403);

        return view('fleet::maintenance.form', [
            'vehicle' => $vehicle,
            'owner' => $owner,
            'maintenance' => null,
            'providers' => $this->providers($owner),
            'responsibles' => $this->responsibles($owner),
        ]);
    }

    public function store(Request $request, FleetAccess $access, VehicleCatalog $catalog, MaintenanceService $service): RedirectResponse
    {
        $vehicleId = $request->validate(['vehicle_id' => ['required', 'integer', 'exists:vehicles,id']])['vehicle_id'];
        $vehicle = Vehicle::findOrFail($vehicleId);
        $owner = $catalog->owner($vehicle, Tenant::all());
        abort_unless($owner && $this->canViewTenant($request, $access, $owner), 404);
        abort_unless($access->allows($request->user(), $owner, 'operations.maintenance', 2), 403);
        $data = $this->planningData($request, $owner);
        $status = $request->validate(['status' => ['required', 'in:pending,scheduled']])['status'];
        if ($status === 'scheduled' && empty($data['scheduled_at'])) {
            throw ValidationException::withMessages(['scheduled_at' => 'La fecha prevista es obligatoria al agendar.']);
        }

        $maintenance = DB::transaction(function () use ($vehicle, $owner, $data, $status, $request, $service): VehicleMaintenance {
            $maintenance = VehicleMaintenance::create([
                ...$data, 'vehicle_id' => $vehicle->id, 'tenant_id' => $owner->id,
                'status' => $status, 'created_by_user_id' => $request->user()->id,
            ]);
            $service->event($maintenance, $request->user(), 'created', ['fields' => $data], after: $status);
            if ($data['reported_odometer_km'] !== null) {
                $service->event($maintenance, $request->user(), 'reported_odometer', odometer: $data['reported_odometer_km'], recordedAt: now());
            }

            return $maintenance;
        });

        return redirect()->route('fleet.maintenance.show', $maintenance)->with('status', 'Mantención registrada.');
    }

    public function show(Request $request, VehicleMaintenance $maintenance, FleetAccess $access, MaintenanceService $service): View
    {
        $this->authorizeView($request, $maintenance, $access);
        $maintenance->load(['vehicle', 'tenant', 'provider', 'responsible', 'events.actor', 'documents']);
        $settings = DB::table('fleet_maintenance_settings')->where('tenant_id', $maintenance->tenant_id)->first();

        return view('fleet::maintenance.show', [
            'maintenance' => $maintenance,
            'providers' => $this->providers($maintenance->tenant),
            'responsibles' => $this->responsibles($maintenance->tenant),
            'canEdit' => $access->allows($request->user(), $maintenance->tenant, 'operations.maintenance', 2),
            'canClose' => $access->allows($request->user(), $maintenance->tenant, 'operations.maintenance.close', 2),
            'canCorrect' => $access->allows($request->user(), $maintenance->tenant, 'operations.maintenance.correct', 3),
            'alert' => $service->alert($maintenance, $maintenance->vehicle->odometer_km, $settings?->warning_days ?? 30, $settings?->warning_km ?? 1000),
        ]);
    }

    public function update(Request $request, VehicleMaintenance $maintenance, FleetAccess $access, MaintenanceService $service): RedirectResponse
    {
        $this->authorizeView($request, $maintenance, $access);
        abort_unless($access->allows($request->user(), $maintenance->tenant, 'operations.maintenance', 2), 403);
        abort_if(in_array($maintenance->status, ['closed', 'cancelled'], true), 409);
        $data = $this->planningData($request, $maintenance->tenant);
        $state = $request->validate([
            'status' => ['required', Rule::in(array_keys(MaintenanceService::STATUSES))],
            'change_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_unless(in_array($state['status'], array_merge([$maintenance->status], MaintenanceService::TRANSITIONS[$maintenance->status]), true), 422);
        abort_if($state['status'] === 'closed', 422);
        if ($state['status'] === 'scheduled' && empty($data['scheduled_at'])) {
            throw ValidationException::withMessages(['scheduled_at' => 'La fecha prevista es obligatoria al agendar.']);
        }
        if ($state['status'] === 'cancelled' && blank($state['change_reason'] ?? null)) {
            throw ValidationException::withMessages(['change_reason' => 'Indica el motivo de cancelación.']);
        }

        DB::transaction(function () use ($maintenance, $request, $data, $state, $service): void {
            $before = $maintenance->only([...array_keys($data), 'status']);
            $oldStatus = $maintenance->status;
            $maintenance->fill([...$data, 'status' => $state['status']]);
            if ($oldStatus !== 'in_progress' && $state['status'] === 'in_progress') {
                $maintenance->started_at = now();
            }
            $changes = $maintenance->getDirty();
            $maintenance->save();
            if ($changes || filled($state['change_reason'] ?? null)) {
                $service->event($maintenance, $request->user(), $state['status'] === 'cancelled' ? 'cancelled' : 'updated', [
                    'before' => $before, 'after' => $maintenance->only(array_keys($data)),
                    'reason' => $state['change_reason'] ?? null,
                ], $oldStatus, $maintenance->status);
            }
            if (($changes['reported_odometer_km'] ?? null) !== null) {
                $service->event($maintenance, $request->user(), 'reported_odometer', odometer: $maintenance->reported_odometer_km, recordedAt: now());
            }
        });

        return back()->with('status', 'Mantención actualizada.');
    }

    public function close(Request $request, VehicleMaintenance $maintenance, FleetAccess $access, MaintenanceService $service): RedirectResponse
    {
        $this->authorizeView($request, $maintenance, $access);
        abort_unless($access->allows($request->user(), $maintenance->tenant, 'operations.maintenance.close', 2), 403);
        abort_unless($maintenance->status === 'in_progress', 409);
        $data = $this->closingData($request, $maintenance);
        DB::transaction(function () use ($maintenance, $request, $data, $service): void {
            $maintenance->update([...$data, 'status' => 'closed']);
            $service->event($maintenance, $request->user(), 'closed', ['fields' => $data], 'in_progress', 'closed');
            $service->recordReading($maintenance, $request->user(), $data['closed_odometer_km'], Carbon::parse($data['closed_at']));
            $service->syncNextDate($maintenance->vehicle_id);
        });

        return back()->with('status', 'Mantención cerrada; kilometraje y próxima fecha revisados.');
    }

    public function correct(Request $request, VehicleMaintenance $maintenance, FleetAccess $access, MaintenanceService $service): RedirectResponse
    {
        $this->authorizeView($request, $maintenance, $access);
        abort_unless($access->allows($request->user(), $maintenance->tenant, 'operations.maintenance.correct', 3), 403);
        abort_unless($maintenance->status === 'closed', 409);
        $reason = $request->validate(['correction_reason' => ['required', 'string', 'max:2000']])['correction_reason'];
        $data = $this->closingData($request, $maintenance);
        DB::transaction(function () use ($maintenance, $request, $data, $reason, $service): void {
            $before = $maintenance->only(array_keys($data));
            $maintenance->update($data);
            $service->event($maintenance, $request->user(), 'corrected', [
                'reason' => $reason, 'before' => $before, 'after' => $maintenance->only(array_keys($data)),
            ], 'closed', 'closed');
            if ($before['closed_odometer_km'] != $data['closed_odometer_km'] || $before['closed_at'] != $data['closed_at']) {
                $service->recordReading($maintenance, $request->user(), $data['closed_odometer_km'], Carbon::parse($data['closed_at']));
            }
            $service->syncNextDate($maintenance->vehicle_id);
        });

        return back()->with('status', 'Corrección auditada.');
    }

    public function upload(Request $request, VehicleMaintenance $maintenance, FleetAccess $access, MaintenanceService $service): RedirectResponse
    {
        $this->authorizeView($request, $maintenance, $access);
        $level = $maintenance->status === 'closed' ? 'operations.maintenance.correct' : 'operations.maintenance';
        abort_unless($access->allows($request->user(), $maintenance->tenant, $level, $maintenance->status === 'closed' ? 3 : 2), 403);
        $data = $request->validate([
            'kind' => ['required', 'in:invoice,receipt,work_order,report,photo,other'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);
        $file = $data['document'];
        $path = $file->store('fleet/maintenance/'.$maintenance->id, 'local');
        try {
            DB::transaction(function () use ($maintenance, $request, $file, $path, $data, $service): void {
                $document = FleetDocument::create([
                    'tenant_id' => $maintenance->tenant_id,
                    'vehicle_id' => $maintenance->vehicle_id,
                    'documentable_type' => 'maintenance',
                    'documentable_id' => $maintenance->id,
                    'kind' => $data['kind'],
                    'disk' => 'local', 'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size_bytes' => $file->getSize(),
                    'uploaded_by_user_id' => $request->user()->id,
                    'created_at' => now(),
                ]);
                $service->event($maintenance, $request->user(), 'document_uploaded', ['document_id' => $document->id, 'kind' => $data['kind']]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return back()->with('status', 'Respaldo adjuntado.');
    }

    public function download(Request $request, VehicleMaintenance $maintenance, FleetDocument $document, FleetAccess $access): StreamedResponse
    {
        $this->authorizeView($request, $maintenance, $access);
        abort_unless($document->documentable_type === 'maintenance' && $document->documentable_id === $maintenance->id
            && $document->tenant_id === $maintenance->tenant_id && $document->vehicle_id === $maintenance->vehicle_id, 404);
        abort_unless($document->disk === 'local' && Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->original_name);
    }

    public function settings(Request $request, FleetAccess $access): View
    {
        $tenant = $access->tenant($request);

        return view('fleet::maintenance.settings', [
            'tenant' => $tenant,
            'settings' => DB::table('fleet_maintenance_settings')->where('tenant_id', $tenant->id)->first(),
        ]);
    }

    public function updateSettings(Request $request, FleetAccess $access): RedirectResponse
    {
        $tenant = $access->tenant($request);
        $data = $request->validate([
            'warning_days' => ['required', 'integer', 'between:0,365'],
            'warning_km' => ['required', 'integer', 'between:0,100000'],
        ]);
        $before = DB::table('fleet_maintenance_settings')->where('tenant_id', $tenant->id)->first();
        DB::transaction(function () use ($access, $request, $tenant, $data, $before): void {
            DB::table('fleet_maintenance_settings')->updateOrInsert(['tenant_id' => $tenant->id], [
                ...$data, 'created_at' => $before?->created_at ?? now(), 'updated_at' => now(),
            ]);
            $access->audit($request->user(), $tenant, null, 'maintenance_alert_settings_updated',
                $before ? ['warning_days' => $before->warning_days, 'warning_km' => $before->warning_km] : null, $data);
        });

        return back()->with('status', 'Umbrales de alerta actualizados para '.$tenant->name.'.');
    }

    private function planningData(Request $request, Tenant $owner): array
    {
        $data = $request->validate([
            'maintenance_type' => ['required', 'string', 'max:100'],
            'execution_type' => ['required', 'in:internal,external'],
            'scheduled_at' => ['nullable', 'date'],
            'reported_odometer_km' => ['nullable', 'integer', 'between:0,999999999'],
            'provider_id' => ['nullable', Rule::exists('providers', 'id')->where('tenant_id', $owner->id)->where('is_active', 1)],
            'estimated_cost' => ['nullable', 'numeric', 'between:0,999999999999.99'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'next_due_at' => ['nullable', 'date'],
            'next_due_km' => ['nullable', 'integer', 'between:0,999999999'],
            'responsible_user_id' => ['nullable', Rule::exists('tenant_users', 'user_id')->where('tenant_id', $owner->id)->where('is_active', 1)],
        ]);
        if ($data['execution_type'] === 'internal') {
            $data['provider_id'] = null;
        }

        return array_replace(array_fill_keys([
            'scheduled_at', 'reported_odometer_km', 'provider_id', 'estimated_cost',
            'notes', 'next_due_at', 'next_due_km', 'responsible_user_id',
        ], null), $data);
    }

    private function closingData(Request $request, VehicleMaintenance $maintenance): array
    {
        $data = $request->validate([
            'execution_type' => ['required', 'in:internal,external'],
            'closed_at' => ['required', 'date', 'before_or_equal:now'],
            'closed_odometer_km' => ['required', 'integer', 'between:0,999999999'],
            'provider_id' => ['nullable', Rule::exists('providers', 'id')->where('tenant_id', $maintenance->tenant_id)->where('is_active', 1)],
            'actual_cost' => ['required', 'numeric', 'between:0,999999999999.99'],
            'document_type' => ['nullable', 'in:invoice,receipt,work_order,other'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'closing_notes' => ['nullable', 'string', 'max:10000'],
            'next_due_at' => ['nullable', 'date'],
            'next_due_km' => ['nullable', 'integer', 'between:0,999999999'],
        ]);
        if ($data['execution_type'] === 'external' && empty($data['provider_id'])) {
            throw ValidationException::withMessages(['provider_id' => 'Selecciona un proveedor existente para una mantención externa.']);
        }
        if (filled($data['document_type'] ?? null) && blank($data['document_number'] ?? null)) {
            throw ValidationException::withMessages(['document_number' => 'Indica el número del documento seleccionado.']);
        }
        if ($data['execution_type'] === 'external' && blank($data['document_number'] ?? null) && blank($data['closing_notes'] ?? null)) {
            throw ValidationException::withMessages(['closing_notes' => 'Si no hay comprobante, deja una observación de cierre.']);
        }
        if (isset($data['next_due_km']) && $data['next_due_km'] <= $data['closed_odometer_km']) {
            throw ValidationException::withMessages(['next_due_km' => 'El próximo kilometraje debe ser mayor al registrado en el cierre.']);
        }
        if (isset($data['next_due_at']) && $data['next_due_at'] < substr($data['closed_at'], 0, 10)) {
            throw ValidationException::withMessages(['next_due_at' => 'La próxima fecha no puede ser anterior al cierre.']);
        }
        if ($data['execution_type'] === 'internal') {
            $data['provider_id'] = null;
        }

        return array_replace(array_fill_keys(['provider_id', 'document_type', 'document_number', 'closing_notes', 'next_due_at', 'next_due_km'], null), $data);
    }

    private function providers(Tenant $owner)
    {
        return Provider::query()->where('tenant_id', $owner->id)->where('is_active', true)->orderBy('legal_name')->get();
    }

    private function responsibles(Tenant $owner)
    {
        return User::query()->join('tenant_users', 'tenant_users.user_id', '=', 'users.id')
            ->where('tenant_users.tenant_id', $owner->id)->where('tenant_users.is_active', true)
            ->select('users.*')->orderBy('users.name')->get();
    }

    private function shared(Request $request, FleetAccess $access): bool
    {
        $tenant = $access->tenant($request);

        return $access->allows($request->user(), $tenant, 'operations.maintenance')
            && $access->allows($request->user(), $tenant, 'operations.vehicle_pool');
    }

    private function canViewTenant(Request $request, FleetAccess $access, Tenant $owner): bool
    {
        return $owner->id === $access->tenant($request)->id
            || ($this->shared($request, $access) && $access->allows($request->user(), $owner, 'operations.maintenance'));
    }

    private function authorizeView(Request $request, VehicleMaintenance $maintenance, FleetAccess $access): void
    {
        abort_unless($this->canViewTenant($request, $access, $maintenance->tenant), 404);
    }
}
