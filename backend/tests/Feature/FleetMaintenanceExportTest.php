<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

class FleetMaintenanceExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_preserves_complete_history_children_and_literal_text_with_tenant_filters(): void
    {
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $other = Tenant::where('code', 'PMCB')->firstOrFail();
        $user = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => 'administrator', 'is_active' => true]);
        $job = $this->job($tenant, 'EXPORT11', 'closed');
        $this->job($tenant, 'EXPORT22', 'pending');
        $this->job($other, 'SECRET99', 'closed');
        DB::table('vehicle_maintenance_events')->insert(['vehicle_maintenance_id' => $job->id, 'tenant_id' => $tenant->id, 'actor_user_id' => $user->id, 'event_type' => 'closed', 'new_status' => 'closed', 'odometer_km' => 12345, 'details' => '{"motivo":"Trabajo completo"}', 'created_at' => now()]);
        DB::table('VEH_maintenance_imports')->insert(['vehicle_maintenance_id' => $job->id, 'source_key' => 'export-example', 'source_line' => 1, 'requested_at' => '2026-09-01', 'source_data' => '{"Repuesto":"Kit original","Folio":"00017"}']);
        DB::table('fleet_documents')->insert(['tenant_id' => $tenant->id, 'vehicle_id' => $job->vehicle_id, 'documentable_type' => 'maintenance', 'documentable_id' => $job->id, 'kind' => 'invoice', 'disk' => 'local', 'path' => 'private/test.pdf', 'original_name' => 'factura.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 123, 'uploaded_by_user_id' => $user->id, 'created_at' => now()]);
        $this->actingAs($user)->withSession(['fleet_tenant_id' => $tenant->id]);
        $response = $this->get(route('fleet.maintenance.export'));
        $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $zip = new ZipArchive;
        $path = $response->baseResponse->getFile()->getPathname();
        $this->assertTrue($zip->open($path));
        $history = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('EXPORT11', $history);
        $this->assertStringContainsString('EXPORT22', $history);
        $this->assertStringNotContainsString('SECRET99', $history);
        $this->assertStringContainsString('COT-001', $history);
        $this->assertStringContainsString('FAC-002', $history);
        $this->assertStringContainsString('<v>45000.25</v>', $history);
        $this->assertStringContainsString('=1+1', $history);
        $this->assertStringNotContainsString('<f>', $history);
        $this->assertStringContainsString('Trabajo completo', $zip->getFromName('xl/worksheets/sheet2.xml'));
        $this->assertStringContainsString('factura.pdf', $zip->getFromName('xl/worksheets/sheet3.xml'));
        $this->assertStringContainsString('Kit original', $zip->getFromName('xl/worksheets/sheet4.xml'));
        foreach (range(1, 4) as $index) {
            $this->assertNotFalse(simplexml_load_string($zip->getFromName('xl/worksheets/sheet'.$index.'.xml')));
        }
        $zip->close();
        @unlink($path);
        $filtered = $this->get(route('fleet.maintenance.export', ['estado' => 'closed', 'patente' => 'EXPORT11']))->assertOk();
        $path = $filtered->baseResponse->getFile()->getPathname();
        $zip->open($path);
        $this->assertStringNotContainsString('EXPORT22', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        @unlink($path);
        $this->get(route('fleet.maintenance.export', ['empresa' => 'PMCB']))->assertForbidden();
    }

    public function test_export_requires_maintenance_permission(): void
    {
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $user = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => 'driver', 'is_active' => true]);
        $this->actingAs($user)->withSession(['fleet_tenant_id' => $tenant->id])->get(route('fleet.maintenance.export'))->assertForbidden();
    }

    private function job(Tenant $tenant, string $plate, string $status): VehicleMaintenance
    {
        $vehicle = DB::table('vehicles')->insertGetId(['tenant_id' => $tenant->id, 'rut_empresa' => $tenant->tax_id, 'company_source' => $tenant->code, 'internal_code' => $plate, 'plate' => $plate, 'vehicle_type' => 'Camión', 'ownership_type' => 'Propio', 'operational_status' => 'Activa', 'is_active' => true]);

        return VehicleMaintenance::create(['vehicle_id' => $vehicle, 'tenant_id' => $tenant->id, 'maintenance_type' => 'Reparación', 'execution_type' => 'internal', 'status' => $status, 'quotation_number' => 'COT-001', 'document_number' => 'FAC-002', 'actual_cost' => 45000.25, 'notes' => '=1+1']);
    }
}
