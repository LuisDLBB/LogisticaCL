<?php

namespace Tests\Feature;

use App\Fleet\MaintenanceService;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FleetMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_maintenance_closes_without_provider_or_document_and_preserves_history(): void
    {
        [$admin, $fourN, $vehicle] = $this->setupVehicle('administrator');
        $this->actingAs($admin)->withSession(['fleet_tenant_id' => $fourN->id]);

        $this->post('/control-flota/operaciones/mantenciones', $this->planning($vehicle, 'pending'))
            ->assertRedirect();
        $id = DB::table('vehicle_maintenances')->value('id');
        $this->put('/control-flota/operaciones/mantenciones/'.$id, $this->planning($vehicle, 'scheduled'))
            ->assertRedirect();
        $this->put('/control-flota/operaciones/mantenciones/'.$id, $this->planning($vehicle, 'in_progress'))
            ->assertRedirect();
        $this->get('/control-flota/operaciones/flota?pool=1')->assertDontSee('MAINT11');
        $this->post('/control-flota/operaciones/mantenciones/'.$id.'/cerrar', $this->closing())
            ->assertRedirect();

        $maintenance = DB::table('vehicle_maintenances')->find($id);
        $this->assertSame('closed', $maintenance->status);
        $this->assertNull($maintenance->provider_id);
        $this->assertNull($maintenance->document_number);
        $this->assertSame(12000, DB::table('vehicles')->find($vehicle->id)->odometer_km);
        $this->assertSame(now()->addMonths(2)->toDateString(), DB::table('vehicles')->find($vehicle->id)->next_maintenance_at);
        $this->assertGreaterThanOrEqual(5, DB::table('vehicle_maintenance_events')->where('vehicle_maintenance_id', $id)->count());
        $this->get('/control-flota/operaciones/flota/'.$vehicle->id)->assertOk()->assertSee('Historial de mantenciones')->assertSee('Preventiva');
        $this->get('/control-flota/operaciones/mantenciones/'.$id)->assertOk()->assertSee('Cerrada')->assertSee('Lectura de kilometraje');
    }

    public function test_external_close_requires_existing_tenant_provider_and_document_number_only_when_type_selected(): void
    {
        [$admin, $fourN, $vehicle] = $this->setupVehicle('administrator');
        $this->actingAs($admin)->withSession(['fleet_tenant_id' => $fourN->id]);
        $this->post('/control-flota/operaciones/mantenciones', [
            ...$this->planning($vehicle, 'pending'), 'execution_type' => 'external',
        ])->assertRedirect();
        $id = DB::table('vehicle_maintenances')->value('id');
        $this->put('/control-flota/operaciones/mantenciones/'.$id, [
            ...$this->planning($vehicle, 'scheduled'), 'execution_type' => 'external',
        ])->assertRedirect();
        $this->put('/control-flota/operaciones/mantenciones/'.$id, [
            ...$this->planning($vehicle, 'in_progress'), 'execution_type' => 'external',
        ])->assertRedirect();
        $this->post('/control-flota/operaciones/mantenciones/'.$id.'/cerrar', [
            ...$this->closing(), 'execution_type' => 'external', 'closing_notes' => 'Sin documento emitido',
        ])->assertSessionHasErrors('provider_id');
        $providerId = DB::table('providers')->insertGetId([
            'tenant_id' => $fourN->id, 'tax_id' => '12345678-9', 'tax_id_number' => '12345678',
            'tax_id_check_digit' => '9', 'legal_name' => 'Taller de prueba', 'operator_type' => 'taller',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->post('/control-flota/operaciones/mantenciones/'.$id.'/cerrar', [
            ...$this->closing(), 'execution_type' => 'external', 'provider_id' => $providerId,
            'document_type' => 'invoice', 'closing_notes' => 'Factura pendiente de número',
        ])->assertSessionHasErrors('document_number');
        $this->post('/control-flota/operaciones/mantenciones/'.$id.'/cerrar', [
            ...$this->closing(), 'execution_type' => 'external', 'provider_id' => $providerId,
            'closing_notes' => 'Taller no emitió documento',
        ])->assertRedirect();
        $this->assertSame($providerId, DB::table('vehicle_maintenances')->find($id)->provider_id);
    }

    public function test_lower_correction_keeps_current_odometer_and_requires_administrator(): void
    {
        [$operator, $fourN, $vehicle] = $this->setupVehicle('operations');
        $admin = User::factory()->create();
        $this->associate($admin, $fourN, 'administrator');
        $this->actingAs($operator)->withSession(['fleet_tenant_id' => $fourN->id]);
        $this->post('/control-flota/operaciones/mantenciones', $this->planning($vehicle, 'pending'))->assertRedirect();
        $id = DB::table('vehicle_maintenances')->value('id');
        $this->put('/control-flota/operaciones/mantenciones/'.$id, $this->planning($vehicle, 'scheduled'))->assertRedirect();
        $this->put('/control-flota/operaciones/mantenciones/'.$id, $this->planning($vehicle, 'in_progress'))->assertRedirect();
        $this->post('/control-flota/operaciones/mantenciones/'.$id.'/cerrar', $this->closing())->assertRedirect();
        $this->post('/control-flota/operaciones/mantenciones/'.$id.'/corregir', [
            ...$this->closing(), 'closed_odometer_km' => 11000, 'correction_reason' => 'Lectura corregida',
        ])->assertForbidden();
        $this->actingAs($admin)->withSession(['fleet_tenant_id' => $fourN->id])->post('/control-flota/operaciones/mantenciones/'.$id.'/corregir', [
            ...$this->closing(), 'closed_odometer_km' => 11000, 'correction_reason' => 'Lectura corregida',
        ])->assertRedirect();
        $this->assertSame(12000, DB::table('vehicles')->find($vehicle->id)->odometer_km);
        $this->assertSame(2, DB::table('vehicle_maintenance_events')->where('vehicle_maintenance_id', $id)->where('event_type', 'odometer')->count());
        $this->assertSame(11000, DB::table('vehicle_maintenances')->find($id)->closed_odometer_km);
    }

    public function test_read_only_and_cross_tenant_writes_are_blocked_but_shared_pool_can_read(): void
    {
        [$operator, $fourN, $vehicle] = $this->setupVehicle('operations', 'PMCB');
        $reader = User::factory()->create();
        $this->associate($reader, $fourN, 'read_only');
        $this->actingAs($operator)->withSession(['fleet_tenant_id' => $fourN->id]);
        $this->get('/control-flota/operaciones/mantenciones')->assertOk();
        $this->get('/control-flota/operaciones/mantenciones/nueva?vehicle_id='.$vehicle->id)->assertNotFound();
        $this->post('/control-flota/operaciones/mantenciones', $this->planning($vehicle, 'pending'))->assertNotFound();
        $pmcb = Tenant::where('code', 'PMCB')->firstOrFail();
        $foreignId = DB::table('vehicle_maintenances')->insertGetId([
            'vehicle_id' => $vehicle->id, 'tenant_id' => $pmcb->id, 'maintenance_type' => 'Reservada',
            'execution_type' => 'internal', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->get('/control-flota/operaciones/mantenciones')->assertOk()->assertDontSee('Reservada');
        $this->get('/control-flota/operaciones/mantenciones/'.$foreignId)->assertNotFound();
        $this->get('/control-flota/operaciones/flota/'.$vehicle->id)->assertOk()->assertDontSee('Reservada');
        $this->associate($operator, $pmcb, 'operations');
        $this->get('/control-flota/operaciones/mantenciones')->assertOk()->assertSee('Reservada');
        $this->get('/control-flota/operaciones/mantenciones/'.$foreignId)->assertOk();
        $this->get('/control-flota/operaciones/mantenciones/nueva?vehicle_id='.$vehicle->id)->assertOk();
        $this->post('/control-flota/operaciones/mantenciones', $this->planning($vehicle, 'pending'))->assertRedirect();
        $this->assertSame($pmcb->id, DB::table('vehicle_maintenances')->orderByDesc('id')->value('tenant_id'));
        $this->assertSame($fourN->id, session('fleet_tenant_id'));
        $this->actingAs($reader)->withSession(['fleet_tenant_id' => $fourN->id])
            ->get('/control-flota/operaciones/mantenciones')->assertOk();
        $this->post('/control-flota/operaciones/mantenciones', $this->planning($vehicle, 'pending'))->assertForbidden();
    }

    public function test_alert_settings_are_tenant_scoped_and_documents_are_private(): void
    {
        [$admin, $fourN, $vehicle] = $this->setupVehicle('administrator');
        Storage::fake('local');
        $this->actingAs($admin)->withSession(['fleet_tenant_id' => $fourN->id]);
        $this->get('/control-flota/administrador/parametros-mantenciones')->assertOk()->assertSee('Alertas de mantenciones');
        $this->put('/control-flota/administrador/parametros-mantenciones', ['warning_days' => 45, 'warning_km' => 1500])->assertRedirect();
        $this->assertSame(45, DB::table('fleet_maintenance_settings')->where('tenant_id', $fourN->id)->value('warning_days'));
        $this->post('/control-flota/operaciones/mantenciones', $this->planning($vehicle, 'pending'))->assertRedirect();
        $id = DB::table('vehicle_maintenances')->value('id');
        $this->post('/control-flota/operaciones/mantenciones/'.$id.'/documentos', [
            'kind' => 'report', 'document' => UploadedFile::fake()->create('informe.pdf', 30, 'application/pdf'),
        ])->assertRedirect();
        $document = DB::table('fleet_documents')->first();
        $this->assertSame('maintenance', $document->documentable_type);
        Storage::disk('local')->assertExists($document->path);
        $this->get('/control-flota/operaciones/mantenciones/'.$id.'/documentos/'.$document->id)->assertOk();
        $this->assertSame('upcoming', app(MaintenanceService::class)->alert(
            VehicleMaintenance::findOrFail($id), null, 45, 1500
        )['level']);
    }

    public function test_alerts_distinguish_upcoming_overdue_and_missing_odometer(): void
    {
        [$admin, $fourN, $vehicle] = $this->setupVehicle('administrator');
        $this->actingAs($admin)->withSession(['fleet_tenant_id' => $fourN->id]);
        $this->post('/control-flota/operaciones/mantenciones', $this->planning($vehicle, 'pending'))->assertRedirect();
        $id = DB::table('vehicle_maintenances')->value('id');
        $service = app(MaintenanceService::class);

        DB::table('vehicle_maintenances')->where('id', $id)->update(['scheduled_at' => now()->addDays(10)]);
        $this->assertSame('upcoming', $service->alert(VehicleMaintenance::findOrFail($id), null, 30, 1000)['level']);
        DB::table('vehicle_maintenances')->where('id', $id)->update(['scheduled_at' => now()->subDay()]);
        $this->assertSame('overdue', $service->alert(VehicleMaintenance::findOrFail($id), null, 30, 1000)['level']);
        DB::table('vehicle_maintenances')->where('id', $id)->update(['scheduled_at' => null, 'next_due_km' => 13000]);
        $this->assertSame('Sin lectura', $service->alert(VehicleMaintenance::findOrFail($id), null, 30, 1000)['label']);
        $this->assertSame('upcoming', $service->alert(VehicleMaintenance::findOrFail($id), 12500, 30, 1000)['level']);
        $this->assertSame('overdue', $service->alert(VehicleMaintenance::findOrFail($id), 13000, 30, 1000)['level']);
    }

    private function setupVehicle(string $profile, string $ownerCode = '4N'): array
    {
        $fourN = Tenant::where('code', '4N')->firstOrFail();
        $owner = Tenant::where('code', $ownerCode)->firstOrFail();
        $user = User::factory()->create();
        $this->associate($user, $fourN, $profile);
        $id = DB::table('vehicles')->insertGetId([
            'tenant_id' => $fourN->id, 'rut_empresa' => $owner->tax_id, 'company_source' => $owner->code,
            'internal_code' => 'MAINT11', 'plate' => 'MAINT11', 'vehicle_type' => 'Camioneta',
            'ownership_type' => 'Propio', 'operational_status' => 'Activa', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $fourN, Vehicle::findOrFail($id)];
    }

    private function associate(User $user, Tenant $tenant, string $code): void
    {
        DB::table('tenant_users')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $code,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function planning(Vehicle $vehicle, string $status): array
    {
        return [
            'vehicle_id' => $vehicle->id, 'maintenance_type' => 'Preventiva',
            'execution_type' => 'internal', 'status' => $status,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'reported_odometer_km' => 11900, 'estimated_cost' => 50000,
        ];
    }

    private function closing(): array
    {
        return [
            'execution_type' => 'internal', 'closed_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'closed_odometer_km' => 12000, 'actual_cost' => 45000,
            'next_due_at' => now()->addMonths(2)->toDateString(), 'next_due_km' => 20000,
        ];
    }
}
