<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Carbon\Carbon;
use Database\Seeders\FleetMaintenanceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FleetMaintenancePlanningFormTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $role = 'administrator'): array
    {
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'America/Santiago'));
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['name' => 'Operador de prueba']);
        FleetTestMembership::insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $role, 'is_active' => true]);
        $this->actingAs($user)->withSession(['fleet_tenant_id' => $tenant->id]);
        $vehicle = Vehicle::create(['tenant_id' => $tenant->id, 'rut_empresa' => $tenant->tax_id, 'plate' => 'FORM31', 'internal_code' => 'FORM31', 'vehicle_type' => 'Camioneta', 'ownership_type' => 'Propio', 'operational_status' => 'Activa', 'is_active' => true, 'odometer_km' => 50000]);

        return [$tenant, $user, $vehicle];
    }

    private function payload(Vehicle $vehicle): array
    {
        return ['vehicle_id' => $vehicle->id, 'maintenance_type' => '__other__', 'new_maintenance_type' => 'Cambio de aceite', 'execution_type' => 'internal', 'status' => 'scheduled', 'schedule_on_save' => 1, 'scheduled_at' => '2026-10-16T09:00', 'estimated_cost' => 30000, 'notes' => 'Revisar niveles.'];
    }

    public function test_form_lists_history_in_select_with_other_and_compact_header_without_future_due_inputs(): void
    {
        [$tenant,$user,$vehicle] = $this->context();
        VehicleMaintenance::create(['tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'maintenance_type' => 'NEUMATICOS', 'execution_type' => 'internal', 'status' => 'closed', 'created_by_user_id' => $user->id]);
        $this->get(route('fleet.maintenance.create', ['vehicle_id' => $vehicle->id]))->assertOk()->assertSee('<select name="maintenance_type"', false)->assertSee('NEUMATICOS')->assertSee('Otros · agregar tipo')->assertSee('Agendada al guardar')->assertSee('Operador de prueba')->assertSee('data-new-maintenance-type', false)->assertDontSee('name="responsible_user_id"', false)->assertDontSee('name="next_due_at"', false)->assertDontSee('name="next_due_km"', false);
    }

    public function test_new_type_is_persisted_atomically_and_appears_next_time_without_duplicate_case_or_accents(): void
    {
        [$tenant,$user,$vehicle] = $this->context();
        $payload = $this->payload($vehicle);
        $this->post(route('fleet.maintenance.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('VEH_maintenance_types', ['tenant_id' => $tenant->id, 'name' => 'Cambio de aceite', 'actor_user_id' => $user->id]);
        $this->assertDatabaseHas('vehicle_maintenances', ['vehicle_id' => $vehicle->id, 'status' => 'scheduled', 'maintenance_type' => 'Cambio de aceite', 'created_by_user_id' => $user->id, 'responsible_user_id' => $user->id, 'reported_odometer_km' => 50000, 'next_due_at' => null, 'next_due_km' => null]);
        $this->get(route('fleet.maintenance.create', ['vehicle_id' => $vehicle->id]))->assertOk()->assertSee('Cambio de aceite');
        $this->post(route('fleet.maintenance.store'), [...$payload, 'new_maintenance_type' => ' CAMBIO  DE ACEITE '])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('VEH_maintenance_types', 1);
    }

    public function test_common_types_from_imported_history_are_available_for_both_companies_without_duplicates(): void
    {
        [$tenant, $user, $vehicle] = $this->context();
        $this->seed(FleetMaintenanceTypesSeeder::class);
        $this->seed(FleetMaintenanceTypesSeeder::class);
        $this->assertDatabaseCount('VEH_maintenance_types', 18);
        $this->get(route('fleet.maintenance.create', ['vehicle_id' => $vehicle->id]))->assertOk()->assertSee('Neumáticos')->assertSee('Eléctrico')->assertSee('Garantía')->assertViewHas('maintenanceTypes', fn ($types) => $types->count() === 9);
    }

    public function test_responsible_is_creator_even_if_posted_id_is_forged_and_edit_preserves_creator(): void
    {
        [$tenant,$user,$vehicle] = $this->context();
        $other = User::factory()->create();
        $this->post(route('fleet.maintenance.store'), [...$this->payload($vehicle), 'responsible_user_id' => $other->id])->assertSessionHasNoErrors();
        $job = VehicleMaintenance::where('vehicle_id', $vehicle->id)->firstOrFail();
        $this->assertSame($user->id, $job->responsible_user_id);
        FleetTestMembership::insert(['tenant_id' => $tenant->id, 'user_id' => $other->id, 'role_code' => 'administrator', 'is_active' => true]);
        $this->actingAs($other)->put(route('fleet.maintenance.update', $job), [...$this->payload($vehicle), 'maintenance_type' => 'Cambio de aceite', 'responsible_user_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame($user->id, $job->fresh()->responsible_user_id);
        $this->assertSame($user->id, $job->fresh()->created_by_user_id);
    }

    public function test_invalid_or_unscheduled_other_is_not_saved_as_a_type_and_read_only_cannot_create(): void
    {
        [$tenant,$user,$vehicle] = $this->context();
        $payload = $this->payload($vehicle);
        $this->post(route('fleet.maintenance.store'), [...$payload, 'new_maintenance_type' => ''])->assertSessionHasErrors('new_maintenance_type');
        $this->post(route('fleet.maintenance.store'), [...$payload, 'scheduled_at' => null])->assertSessionHasErrors('scheduled_at');
        $this->assertDatabaseCount('VEH_maintenance_types', 0);
        $this->assertDatabaseCount('vehicle_maintenances', 0);
        $membershipId = DB::table('MBA_tenant_users')->where('user_id', $user->id)->value('id');
        DB::table('fleet_membership_profiles')->where('tenant_user_id', $membershipId)->update([
            'fleet_profile_id' => DB::table('fleet_profiles')->where('tenant_id', $tenant->id)->where('code', 'read_only')->value('id'),
        ]);
        $this->post(route('fleet.maintenance.store'), $payload)->assertForbidden();
        $this->assertDatabaseCount('VEH_maintenance_types', 0);
    }

    public function test_edit_preserves_hidden_due_references_and_other_tenant_type_is_not_exposed(): void
    {
        [$tenant,$user,$vehicle] = $this->context();
        $job = VehicleMaintenance::create(['tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'maintenance_type' => 'Preventiva', 'execution_type' => 'internal', 'status' => 'pending', 'created_by_user_id' => $user->id, 'next_due_at' => '2027-01-10', 'next_due_km' => 60000]);
        DB::table('VEH_maintenance_types')->insert(['tenant_id' => Tenant::where('code', 'PMCB')->value('id'), 'name' => 'Tipo privado de otra empresa', 'normalized_name' => 'TIPO PRIVADO DE OTRA EMPRESA', 'created_at' => now(), 'updated_at' => now()]);
        $this->get(route('fleet.maintenance.create', ['vehicle_id' => $vehicle->id]))->assertOk()->assertDontSee('Tipo privado de otra empresa');
        $this->put(route('fleet.maintenance.update', $job), [...$this->payload($vehicle), 'maintenance_type' => 'Preventiva', 'status' => 'scheduled', 'next_due_km' => 1, 'next_due_at' => '2099-01-01'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('vehicle_maintenances', ['id' => $job->id, 'next_due_km' => 60000, 'next_due_at' => '2027-01-10 00:00:00']);
        $this->get(route('fleet.maintenance.show', $job))->assertOk()->assertSee('<select name="maintenance_type"', false);
    }
}
