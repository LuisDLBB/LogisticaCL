<?php

namespace Tests\Feature;

use App\Fleet\VehicleMaintenanceReference;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Carbon\Carbon;
use Database\Seeders\FleetWeeklyBaseline2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FleetMaintenanceReferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_fields_are_not_editable_or_accepted_from_request_or_excel(): void
    {
        [$tenant] = $this->workspace();
        $vehicle = $this->vehicle($tenant, 'LOCKKM');
        $this->references($vehicle, 100000, 10000);
        $this->get(route('fleet.kilometers.index'))->assertOk()->assertDontSee('name="readings['.$vehicle->id.'][last_maintenance_km]"', false)->assertDontSee('name="readings['.$vehicle->id.'][interval_km]"', false)->assertSee('100.000')->assertSee('10.000');
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'readings' => [['vehicle_id' => $vehicle->id, 'odometer_km' => 105000, 'last_maintenance_km' => 1, 'interval_km' => 1]]])->assertSessionHasErrors(['readings.0.last_maintenance_km', 'readings.0.interval_km']);
        $this->assertDatabaseCount('VEH_weekly_readings', 0);
        $this->post(route('fleet.kilometers.preview'), ['semana' => '2026-10-05', 'pegado' => "Patente\tKM Actual\tKM Ultimo cambio\tKm Recomendado\nLOCKKM\t105000\t999999\t1"])->assertSessionHasNoErrors();
        $preview = session('_old_input.readings')[$vehicle->id];
        $this->assertArrayNotHasKey('interval_km', $preview);
        $this->assertArrayNotHasKey('last_maintenance_km', $preview);
    }

    public function test_real_closed_maintenance_is_authoritative_and_missing_history_is_not_invented(): void
    {
        [$tenant] = $this->workspace();
        $vehicle = $this->vehicle($tenant, 'CLOSEDKM');
        $this->references($vehicle, 100000, 10000);
        VehicleMaintenance::create(['vehicle_id' => $vehicle->id, 'tenant_id' => $tenant->id, 'maintenance_type' => 'Preventiva', 'execution_type' => 'internal', 'status' => 'closed', 'closed_at' => '2026-10-03 12:00:00', 'closed_odometer_km' => 110000]);
        $this->post(route('fleet.kilometers.store'), ['semana' => '2026-10-05', 'readings' => [['vehicle_id' => $vehicle->id, 'odometer_km' => 111000]]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('VEH_weekly_readings', ['vehicle_id' => $vehicle->id, 'last_maintenance_km' => 110000, 'interval_km' => 10000]);
        $row = $this->get(route('fleet.kilometers.index'))->viewData('rows')->first();
        $this->assertSame(120000, $row['due']);
        $missing = $this->vehicle($tenant, 'MISSINGKM');
        $this->get(route('fleet.kilometers.index'))->assertOk()->assertSee('Sin registro')->assertSee('Sin definir')->assertViewHas('rows', fn ($rows) => $rows->firstWhere('vehicle.id', $missing->id)['baseline'] === null);
        $this->assertDatabaseCount('vehicle_maintenances', 1);
    }

    public function test_only_parameters_screen_can_version_interval_with_permissions_and_stale_guard(): void
    {
        [$tenant, $user] = $this->workspace();
        $vehicle = $this->vehicle($tenant, 'PARAMKM');
        $this->references($vehicle, 100000, 10000);
        $current = DB::table('VEH_maintenance_parameters')->where('vehicle_id', $vehicle->id)->first();
        $this->get(route('fleet.maintenance.settings'))->assertOk()->assertSee('KM recomendado por patente')->assertSee('PARAMKM');
        $url = route('fleet.maintenance.interval.update', $vehicle);
        $this->put($url, ['interval_km' => 15000, 'reason' => 'Recomendación del fabricante', 'expected_id' => $current->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('VEH_maintenance_parameters', ['vehicle_id' => $vehicle->id, 'actor_user_id' => $user->id, 'interval_km' => 15000, 'reason' => 'Recomendación del fabricante']);
        $this->assertSame(2, DB::table('VEH_maintenance_parameters')->where('vehicle_id', $vehicle->id)->count());
        $this->put($url, ['interval_km' => 20000, 'reason' => 'Cambio obsoleto', 'expected_id' => $current->id])->assertSessionHasErrors('interval_km');
        $this->assertSame(2, DB::table('VEH_maintenance_parameters')->where('vehicle_id', $vehicle->id)->count());
        $refs = app(VehicleMaintenanceReference::class)->forVehicles(collect([$vehicle->id]), Carbon::parse('2026-09-28'));
        $this->assertSame(10000, $refs[$vehicle->id]['interval']);
        $foreign = $this->vehicle(Tenant::where('code', 'PMCB')->firstOrFail(), 'FOREIGNPARAM');
        $this->put(route('fleet.maintenance.interval.update', $foreign), ['interval_km' => 1, 'reason' => 'No autorizado'])->assertForbidden();
        $this->workspace('commercial');
        $this->put($url, ['interval_km' => 1, 'reason' => 'No autorizado'])->assertForbidden();
    }

    public function test_supplied_six_date_history_is_complete_idempotent_and_preserves_changed_interval(): void
    {
        [$tenant] = $this->workspace();
        foreach (['KLLX31', 'SGVB16', 'SGSS86', 'SPBC15', 'STWJ98', 'TFYF67', 'TJHJ76', 'TJGR16', 'RVSK21', 'VDSD30', 'RPVT38', 'TRZJ37', 'PLRL48', 'TFLJ42', 'VBPD72', 'PXGX13', 'PWZJ48', 'RKTL35', 'SVRB44', 'KLLX32', 'TRWD80', 'PYWJ20', 'TDTJ76', 'TVHY33'] as $plate) {
            $this->vehicle($tenant, $plate);
        }
        $this->seed(FleetWeeklyBaseline2026Seeder::class);
        $this->seed(FleetWeeklyBaseline2026Seeder::class);
        $this->assertDatabaseCount('VEH_weekly_readings', 144);
        $this->assertDatabaseCount('VEH_maintenance_parameters', 144);
        $this->assertDatabaseCount('VEH_maintenance_baselines', 144);
        $this->assertDatabaseCount('vehicle_maintenances', 0);
        $plrl = Vehicle::where('plate', 'PLRL48')->firstOrFail();
        $this->assertDatabaseHas('VEH_weekly_readings', ['vehicle_id' => $plrl->id, 'week_start' => '2026-09-28', 'odometer_km' => 246014, 'last_maintenance_km' => 236537, 'interval_km' => 15000]);
        $this->assertDatabaseHas('VEH_weekly_readings', ['vehicle_id' => $plrl->id, 'week_start' => '2026-10-05', 'odometer_km' => 249063, 'last_maintenance_km' => 248738, 'interval_km' => 10000]);
        $oct = $this->get(route('fleet.kilometers.index', ['semana' => '2026-10-05']));
        $oct->assertOk()->assertSee('Recorrió 997 km esta semana.')->assertDontSee('KLLX32')->assertSee('Programar esta semana');
        $this->assertCount(19, $oct->viewData('rows'));
        $octRows = $oct->viewData('rows')->keyBy('vehicle.plate');
        $this->assertSame(9003, $octRows['KLLX31']['remaining']);
        $this->assertSame(9675, $octRows['PLRL48']['remaining']);
        $this->assertSame('red', $octRows['KLLX31']['level']);
        $this->assertNull($octRows['TJGR16']['remaining']);
        $septRows = $this->get(route('fleet.kilometers.index', ['semana' => '2026-09-28']))->viewData('rows')->keyBy('vehicle.plate');
        $this->assertSame(15000, $septRows['PLRL48']['interval']);
        $this->assertNull($septRows['SPBC15']['baseline']);
        $this->assertNull($octRows['PXGX13']['interval']);
        $this->assertNull($octRows['PWZJ48']['interval']);
        $this->assertSame(249063, $plrl->fresh()->odometer_km);
        $sept = $this->get(route('fleet.kilometers.index', ['semana' => '2026-08-31']));
        $sept->assertOk()->assertSee('Fecha de planilla: 01/09/2026')->assertSeeInOrder(['Vehículos y alertas KM', 'Programación de vehículos', 'Histórico de mantenciones y reparaciones']);
        $this->assertDatabaseHas('VEH_weekly_readings', ['vehicle_id' => $plrl->id, 'week_start' => '2026-08-31', 'odometer_km' => 237456]);
        $sold = Vehicle::where('plate', 'KLLX32')->firstOrFail();
        $this->assertSame(6, DB::table('VEH_weekly_readings')->where('vehicle_id', $sold->id)->count());
        $this->get(route('fleet.vehicles.show', $sold))->assertOk()->assertSee('Historial de kilometrajes')->assertSee('01/09/2026');
        $this->assertSame(6, DB::table('VEH_weekly_readings')->where('vehicle_id', $plrl->id)->count());
        $spbc = Vehicle::where('plate', 'SPBC15')->firstOrFail();
        $this->assertDatabaseHas('VEH_weekly_readings', ['vehicle_id' => $spbc->id, 'week_start' => '2026-09-21', 'odometer_km' => null, 'accepted' => false]);

    }

    private function workspace(string $role = 'administrator'): array
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'America/Santiago'));
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['name' => $role === 'administrator' ? 'Hans de la Barra' : 'Usuario de prueba']);
        DB::table('tenant_users')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $role, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->withSession(['fleet_tenant_id' => $tenant->id]);

        return [$tenant, $user];
    }

    private function vehicle(Tenant $tenant, string $plate): Vehicle
    {
        $id = DB::table('vehicles')->insertGetId(['tenant_id' => $tenant->id, 'rut_empresa' => $tenant->tax_id, 'company_source' => $tenant->code, 'internal_code' => $plate, 'plate' => $plate, 'vehicle_type' => 'Camioneta', 'ownership_type' => 'Propio', 'operational_status' => 'Activa', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return Vehicle::findOrFail($id);
    }

    private function references(Vehicle $vehicle, int $baseline, int $interval): void
    {
        DB::table('VEH_maintenance_parameters')->insert(['vehicle_id' => $vehicle->id, 'tenant_id' => $vehicle->tenant_id, 'effective_from' => '2026-09-28', 'interval_km' => $interval, 'reason' => 'Referencia de prueba', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('VEH_maintenance_baselines')->insert(['vehicle_id' => $vehicle->id, 'tenant_id' => $vehicle->tenant_id, 'observed_week' => '2026-09-28', 'last_maintenance_km' => $baseline, 'source_key' => 'test-'.$vehicle->id, 'source' => 'Antecedente histórico de prueba', 'created_at' => now(), 'updated_at' => now()]);
    }
}
