<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Models\VehicleMaintenanceImport;
use Database\Seeders\FleetMaintenanceHistory2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FleetMaintenanceHistoryImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_matches_plate_preserves_credit_notes_and_is_idempotent_without_changing_vehicle(): void
    {
        $records = json_decode(file_get_contents(database_path('data/fleet_maintenance_history_2026.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach (collect($records)->unique('plate') as $record) {
            $owner = Tenant::where('code', $record['company'])->firstOrFail();
            DB::table('MBA_vehicles')->insert(['tenant_id' => $owner->id, 'rut_empresa' => $owner->tax_id, 'company_source' => $owner->code, 'plate' => $record['plate'], 'internal_code' => $record['plate'], 'vehicle_type' => 'Camión', 'ownership_type' => 'Propio', 'operational_status' => 'Vendido', 'odometer_km' => 999999, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->seed(FleetMaintenanceHistory2026Seeder::class);
        $this->seed(FleetMaintenanceHistory2026Seeder::class);
        $this->assertDatabaseCount('vehicle_maintenances', 222);
        $this->assertDatabaseCount('VEH_maintenance_imports', 222);
        $this->assertDatabaseCount('vehicle_maintenance_events', 222);
        $this->assertSame(214, VehicleMaintenance::where('status', 'closed')->count());
        $this->assertSame(8, VehicleMaintenance::where('status', 'pending')->count());
        $this->assertEquals(106123416, VehicleMaintenance::sum('actual_cost'));
        $this->assertSame(3, VehicleMaintenance::where('actual_cost', '<', 0)->count());
        $this->assertSame(0, VehicleMaintenance::whereNotNull('closed_at')->count());
        $this->assertSame(0, VehicleMaintenance::whereNotNull('closed_odometer_km')->count());
        $this->assertSame(23, Vehicle::where('odometer_km', 999999)->where('operational_status', 'Vendido')->count());
        $vehicle = Vehicle::where('plate', 'TFYF67')->firstOrFail();
        $job = VehicleMaintenance::where('vehicle_id', $vehicle->id)->where('document_number', '256')->firstOrFail();
        $this->assertSame($vehicle->tenant_id, $job->tenant_id);
        $this->assertSame('4N', $job->historicalImport->billed_company);
        $this->assertSame('2026-01-01', $job->historicalImport->requested_at->toDateString());
        $this->assertNotEmpty(VehicleMaintenanceImport::firstOrFail()->source_data);
    }
}
