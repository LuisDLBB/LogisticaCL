<?php

namespace Tests\Feature;

use App\Fleet\FuelImport;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FleetFuelImportTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $user = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => 'administrator', 'is_active' => true]);
        $this->actingAs($user)->withSession(['fleet_tenant_id' => $tenant->id]);

        return [$tenant, $user];
    }

    private function row(): array
    {
        return array_combine(FuelImport::HEADERS, ['4N', 'TEST31', 'Diésel', 'T123', '05-10-2026', '12:00:00', 'G123', '997', '32,25', '32.153', '380000']);
    }

    private function paste(array $rows): string
    {
        return implode("\t", FuelImport::HEADERS)."\n".implode("\n", array_map(fn ($row) => implode("\t", $row), $rows));
    }

    public function test_repeat_upload_skips_baseline_and_new_consumption_is_a_trial(): void
    {
        [$tenant, $user] = $this->context();
        $row = $this->row();
        app(FuelImport::class)->import([$row], $user->id, [$tenant->id], 'Base', false);
        config()->set('fleet.auto_test_mode', true);
        $new = array_replace($row, ['Guía de Despacho' => 'G124', 'Hora Transacción' => '13:00:00']);

        $this->post(route('fleet.fuel.store'), ['pasted' => $this->paste([$row, $new, $new])])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('VEH_fuel_transactions', 2);
        $this->assertDatabaseHas('VEH_fuel_transactions', ['guide' => 'G123', 'is_test' => false, 'volume' => 32.25, 'amount' => 32153]);
        $this->assertDatabaseHas('VEH_fuel_transactions', ['guide' => 'G124', 'is_test' => true]);
        $this->get(route('fleet.page.operations-fuel'))->assertOk()->assertSee('TEST31')->assertSee('Base inicial')->assertSee('Prueba');
    }

    public function test_conflicting_repeat_rolls_back_the_entire_upload(): void
    {
        [$tenant, $user] = $this->context();
        $row = $this->row();
        app(FuelImport::class)->import([$row], $user->id, [$tenant->id], 'Base', false);
        $new = array_replace($row, ['Guía de Despacho' => 'G124']);
        $conflict = array_replace($row, ['Monto' => '99.999']);

        $this->post(route('fleet.fuel.store'), ['pasted' => $this->paste([$new, $conflict])])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('VEH_fuel_transactions', 1);
        $this->assertDatabaseCount('VEH_fuel_imports', 1);
        $this->post(route('fleet.fuel.store'), ['pasted' => $this->paste([array_replace($row, ['Empresa' => 'UNKNOWN'])])])->assertSessionHasErrors('file');
    }

    public function test_excel_numeric_date_time_and_decimal_volume_are_preserved(): void
    {
        [$tenant, $user] = $this->context();
        $path = tempnam(sys_get_temp_dir(), 'fuel-test-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">';
        foreach (FuelImport::HEADERS as $index => $header) {
            $xml .= '<c r="'.chr(65 + $index).'1" t="inlineStr"><is><t>'.htmlspecialchars($header, ENT_XML1).'</t></is></c>';
        }
        $xml .= '</row><row r="2">';
        $values = ['4N', 'TEST31', 'Diésel', 'T123', 46300, 0.5, 'G123', 997, 1.234, 1230, 380000];
        foreach ($values as $index => $value) {
            $xml .= is_numeric($value) ? '<c r="'.chr(65 + $index).'2"><v>'.$value.'</v></c>' : '<c r="'.chr(65 + $index).'2" t="inlineStr"><is><t>'.$value.'</t></is></c>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml.'</row></sheetData></worksheet>');
        $zip->close();
        try {
            $importer = app(FuelImport::class);
            $result = $importer->import($importer->read($path, 'xlsx'), $user->id, [$tenant->id], 'Excel', true);
            $this->assertSame(['inserted' => 1, 'duplicates' => 0], $result);
            $this->assertDatabaseHas('VEH_fuel_transactions', ['transaction_date' => '2026-10-05', 'transaction_time' => '12:00:00', 'volume' => 1.234]);
        } finally {
            unlink($path);
        }
    }

    public function test_maintenance_creations_and_changes_are_marked_as_trials(): void
    {
        [$tenant, $user] = $this->context();
        $id = DB::table('vehicles')->insertGetId(['tenant_id' => $tenant->id, 'plate' => 'TEST31', 'internal_code' => 'TEST31', 'vehicle_type' => 'Camión', 'operational_status' => 'Activa', 'ownership_type' => 'Propio', 'is_active' => true]);
        config()->set('fleet.auto_test_mode', true);
        $job = VehicleMaintenance::create(['vehicle_id' => $id, 'tenant_id' => $tenant->id, 'maintenance_type' => 'Preventiva', 'execution_type' => 'internal', 'status' => 'scheduled', 'created_by_user_id' => $user->id]);
        $this->assertTrue($job->is_test);
        $baseline = VehicleMaintenance::create(['vehicle_id' => $id, 'tenant_id' => $tenant->id, 'maintenance_type' => 'Historia', 'execution_type' => 'external', 'status' => 'closed']);
        $this->assertFalse($baseline->fresh()->is_test);
        $baseline->update(['notes' => 'Cambio de prueba']);
        $this->assertTrue($baseline->fresh()->is_test);
    }
}
