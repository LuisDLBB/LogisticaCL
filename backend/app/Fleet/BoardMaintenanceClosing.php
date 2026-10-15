<?php

namespace App\Fleet;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BoardMaintenanceClosing
{
    public function __construct(private MaintenanceService $service) {}

    public function finish(Vehicle $vehicle, Tenant $owner, User $actor, Collection $jobs, array $input, string $notes): void
    {
        $data = Validator::make($input, [
            'maintenance_id' => ['nullable', 'integer'],
            'execution_type' => ['required', 'in:internal,external'],
            'provider_id' => ['required_if:execution_type,external', 'nullable', Rule::exists('MBA_providers', 'id')->whereIn('tenant_id', [$owner->id, Tenant::where('code', '4N')->value('id')])->where('is_active', 1)],
            'quotation_number' => ['nullable', 'string', 'max:100'],
            'estimated_cost' => ['nullable', 'numeric', 'between:0,999999999999.99'],
            'actual_cost' => ['required', 'numeric', 'between:0,999999999999.99'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'closed_odometer_km' => ['required', 'integer', 'between:0,999999999'],
        ])->validate();
        $job = $jobs->firstWhere('id', $data['maintenance_id'] ?? null);
        if ((! empty($data['maintenance_id']) && ! $job) || ($jobs->isNotEmpty() && ! $job)) {
            throw ValidationException::withMessages(['closing.maintenance_id' => 'Selecciona la mantención vigente de esta patente.']);
        }
        if ($job && $job->tenant_id !== $owner->id) {
            throw ValidationException::withMessages(['closing.maintenance_id' => 'La mantención pertenece a otra empresa.']);
        }
        if (! $job && ! in_array(mb_strtolower(trim($vehicle->operational_status)), ['maintenance', 'in_maintenance', 'mantencion', 'mantención', 'en mantención', 'en mantencion', 'repair', 'in_repair', 'reparacion', 'reparación', 'en reparación', 'en reparacion', 'taller'], true)) {
            throw ValidationException::withMessages(['vehicle_state' => 'El vehículo no tiene una mantención para cerrar.']);
        }
        if (! $job) {
            $job = VehicleMaintenance::create([
                'vehicle_id' => $vehicle->id, 'tenant_id' => $owner->id,
                'maintenance_type' => str_contains(mb_strtolower($vehicle->operational_status), 'repar') ? 'Reparación' : 'Mantención',
                'execution_type' => $data['execution_type'], 'status' => 'in_progress',
                'reported_odometer_km' => $vehicle->odometer_km,
                'estimated_cost' => $data['estimated_cost'] ?? null,
                'responsible_user_id' => $actor->id, 'created_by_user_id' => $actor->id,
            ]);
            $this->service->event($job, $actor, 'created', ['source' => 'Cierre desde tablero de flota'], after: 'in_progress');
        }
        $previous = $job->status;
        $job->update([
            'execution_type' => $data['execution_type'],
            'provider_id' => $data['execution_type'] === 'external' ? $data['provider_id'] : null,
            'quotation_number' => $data['quotation_number'] ?? $job->quotation_number,
            'actual_cost' => $data['actual_cost'],
            'document_type' => filled($data['document_number'] ?? null) ? 'invoice' : null,
            'document_number' => $data['document_number'] ?? null,
            'closed_odometer_km' => $data['closed_odometer_km'],
            'closed_at' => now(), 'closing_notes' => $notes, 'status' => 'closed',
        ]);
        $this->service->event($job, $actor, 'closed', ['fields' => $job->only(['provider_id', 'quotation_number', 'estimated_cost', 'actual_cost', 'document_type', 'document_number', 'closing_notes']), 'source' => 'Tablero de flota'], $previous, 'closed');
        $this->service->recordReading($job, $actor, $data['closed_odometer_km'], now());
        $this->service->syncNextDate($vehicle->id);
        $vehicle->refresh();
    }
}
