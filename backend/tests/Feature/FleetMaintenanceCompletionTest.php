<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetMaintenanceCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'America/Santiago'));
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $user = User::factory()->create();
        FleetTestMembership::insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => 'administrator', 'is_active' => true]);
        $this->actingAs($user)->withSession(['fleet_tenant_id' => $tenant->id]);
        $vehicle = Vehicle::create(['tenant_id' => $tenant->id, 'rut_empresa' => $tenant->tax_id, 'plate' => 'CLOSE1', 'internal_code' => 'CLOSE1', 'vehicle_type' => 'Camioneta', 'ownership_type' => 'Propio', 'operational_status' => 'En mantención', 'is_active' => true, 'odometer_km' => 50000]);

        return [$tenant, $user, $vehicle];
    }

    private function payload(Vehicle $vehicle, ?VehicleMaintenance $job = null): array
    {
        return ['target_state' => 'available', 'reason' => 'Trabajo finalizado y revisado.', 'expected_status' => $vehicle->operational_status, 'expected_revision' => $vehicle->updated_at->toISOString(), 'close_maintenance' => 1, 'closing' => ['maintenance_id' => $job?->id, 'execution_type' => 'internal', 'quotation_number' => 'COT-123', 'estimated_cost' => 5000, 'actual_cost' => 12000, 'document_number' => 'FAC-900', 'closed_odometer_km' => 50100]];
    }

    public function test_date_only_plan_saves_editable_km_and_quotation_independently_from_invoice(): void
    {
        [$tenant, $user, $vehicle] = $this->context();
        $this->post(route('fleet.maintenance.store'), ['vehicle_id' => $vehicle->id, 'maintenance_type' => 'Mantención', 'execution_type' => 'internal', 'scheduled_at' => '2026-10-16', 'reported_odometer_km' => 50555, 'status' => 'scheduled', 'quotation_number' => 'P-200', 'estimated_cost' => 10000])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('vehicle_maintenances', ['vehicle_id' => $vehicle->id, 'reported_odometer_km' => 50555, 'quotation_number' => 'P-200', 'document_number' => null, 'scheduled_at' => '2026-10-16 00:00:00']);
        $this->get(route('fleet.maintenance.create', ['vehicle_id' => $vehicle->id]))->assertOk()->assertSee('type="date" name="scheduled_at"', false)->assertDontSee('type="datetime-local" name="scheduled_at"', false);
    }

    public function test_close_from_board_keeps_estimate_links_invoice_and_releases_vehicle_atomically(): void
    {
        [$tenant, $user, $vehicle] = $this->context();
        $job = VehicleMaintenance::create(['tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'maintenance_type' => 'Mantención', 'execution_type' => 'internal', 'status' => 'in_progress', 'quotation_number' => 'COT-123', 'estimated_cost' => 10000, 'reported_odometer_km' => 50000, 'created_by_user_id' => $user->id]);
        $this->get(route('fleet.page.operations-fleet'))->assertOk()->assertSee('Cierre de mantención')->assertSee('Número de factura');
        $this->patch(route('fleet.vehicles.status', $vehicle), $this->payload($vehicle, $job))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('vehicle_maintenances', ['id' => $job->id, 'status' => 'closed', 'quotation_number' => 'COT-123', 'estimated_cost' => 10000, 'actual_cost' => 12000, 'document_type' => 'invoice', 'document_number' => 'FAC-900', 'closed_odometer_km' => 50100]);
        $this->assertSame('Activa', $vehicle->fresh()->operational_status);
        $this->assertSame(50100, $vehicle->fresh()->odometer_km);
        $this->assertDatabaseHas('vehicle_maintenance_events', ['vehicle_maintenance_id' => $job->id, 'event_type' => 'closed', 'actor_user_id' => $user->id]);
    }

    public function test_manual_workshop_cannot_skip_closure_and_missing_cost_changes_nothing(): void
    {
        [$tenant, $user, $vehicle] = $this->context();
        $data = $this->payload($vehicle);
        $this->patch(route('fleet.vehicles.status', $vehicle), [...$data, 'close_maintenance' => 0])->assertSessionHasErrors('vehicle_state');
        unset($data['closing']['actual_cost']);
        $this->patch(route('fleet.vehicles.status', $vehicle), $data)->assertSessionHasErrors('actual_cost');
        $this->assertSame('En mantención', $vehicle->fresh()->operational_status);
        $this->assertDatabaseCount('vehicle_maintenances', 0);
        $this->patch(route('fleet.vehicles.status', $vehicle), $this->payload($vehicle))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('vehicle_maintenances', ['vehicle_id' => $vehicle->id, 'status' => 'closed', 'estimated_cost' => 5000, 'quotation_number' => 'COT-123', 'document_number' => 'FAC-900']);
    }

    public function test_wrong_vehicle_job_and_external_without_provider_are_rejected(): void
    {
        [$tenant, $user, $vehicle] = $this->context();
        $other = Vehicle::create(['tenant_id' => $tenant->id, 'plate' => 'OTHER1', 'internal_code' => 'OTHER1', 'vehicle_type' => 'Camioneta', 'ownership_type' => 'Propio', 'operational_status' => 'Activa', 'is_active' => true]);
        $job = VehicleMaintenance::create(['tenant_id' => $tenant->id, 'vehicle_id' => $other->id, 'maintenance_type' => 'Mantención', 'execution_type' => 'internal', 'status' => 'in_progress', 'created_by_user_id' => $user->id]);
        $this->patch(route('fleet.vehicles.status', $vehicle), $this->payload($vehicle, $job))->assertSessionHasErrors('closing.maintenance_id');
        $payload = $this->payload($vehicle);
        $payload['closing']['execution_type'] = 'external';
        $this->patch(route('fleet.vehicles.status', $vehicle), $payload)->assertSessionHasErrors('provider_id');
        $this->assertSame('in_progress', $job->fresh()->status);
        $this->assertSame('En mantención', $vehicle->fresh()->operational_status);
    }

    public function test_closure_rolls_back_when_another_due_job_or_expired_papers_block_release(): void
    {
        [$tenant, $user, $vehicle] = $this->context();
        $job = VehicleMaintenance::create(['tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'maintenance_type' => 'Mantención', 'execution_type' => 'internal', 'status' => 'in_progress', 'created_by_user_id' => $user->id]);
        $pending = VehicleMaintenance::create(['tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'maintenance_type' => 'Reparación', 'execution_type' => 'internal', 'status' => 'pending', 'created_by_user_id' => $user->id]);
        $this->patch(route('fleet.vehicles.status', $vehicle), $this->payload($vehicle, $job))->assertSessionHasErrors('vehicle_state');
        $this->assertSame('in_progress', $job->fresh()->status);
        $pending->update(['status' => 'cancelled']);
        $vehicle->update(['circulation_permit_expires_at' => '2026-10-09']);
        $this->patch(route('fleet.vehicles.status', $vehicle), $this->payload($vehicle->fresh(), $job))->assertSessionHasErrors('vehicle_state');
        $this->assertSame('in_progress', $job->fresh()->status);
        $this->assertDatabaseMissing('vehicle_maintenance_events', ['vehicle_maintenance_id' => $job->id, 'event_type' => 'closed']);
    }
}
