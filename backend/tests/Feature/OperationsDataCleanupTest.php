<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Operations\Services\OperationDataCleaner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationsDataCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function supervisor(): array
    {
        Storage::fake('local');
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => 'Administrador']);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($user);

        return [$tenant, $user];
    }

    private function load(int $tenant, int $user, string $type, string $path): int
    {
        Storage::disk('local')->put($path, 'archivo de prueba');
        $id = DB::table('Ope_Cargas')->insertGetId([
            'tenant_id' => $tenant, 'user_id' => $user, 'source_type' => $type,
            'filename' => basename($path), 'path' => $path, 'sha256' => hash('sha256', $path),
            'sheet' => 'Datos', 'mapping' => '{}', 'status' => 'completed',
            'row_count' => 1, 'invalid_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('Ope_FilasFuente')->insert([
            'load_id' => $id, 'line' => 1, 'tracking' => '4N-TEST-'.$id,
            'raw' => '{}', 'data' => '{}', 'errors' => '[]',
        ]);

        return $id;
    }

    private function process(int $tenant, int $user, int $master, int $reception): int
    {
        $id = DB::table('Ope_Lotes')->insertGetId([
            'tenant_id' => $tenant, 'user_id' => $user, 'master_load_id' => $master,
            'operation_date' => '2026-10-07', 'name' => 'Proceso de prueba',
            'status' => 'review', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('Ope_LoteFuentes')->insert(['lot_id' => $id, 'load_id' => $reception]);

        return $id;
    }

    public function test_supervisor_can_clean_system_reception_by_day_after_previewing_its_process(): void
    {
        [$tenant, $user] = $this->supervisor();
        $master = $this->load($tenant->id, $user->id, 'master', 'operations/'.$tenant->id.'/master.xlsx');
        $systemLoad = $this->load($tenant->id, $user->id, 'reception', 'operations/'.$tenant->id.'/system.xlsx');
        $process = $this->process($tenant->id, $user->id, $master, $systemLoad);
        $client = DB::table('MBA_clients')->where('tenant_id', $tenant->id)->firstOrFail();
        $photo = 'operations/'.$tenant->id.'/system-receptions/1/photo.jpg';
        Storage::disk('local')->put($photo, 'foto');
        $reception = DB::table('Ope_RecepcionesSistema')->insertGetId([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'client_id' => $client->id,
            'document_type' => 'guia', 'document_number' => 'G-1', 'photo_path' => $photo,
            'status' => 'completed', 'load_id' => $systemLoad,
            'created_at' => '2026-10-07 12:00:00', 'updated_at' => '2026-10-07 12:00:00',
        ]);
        DB::table('Ope_RecepcionSistemaBultos')->insert([
            'reception_id' => $reception, 'user_id' => $user->id, 'sequence' => 1,
            'tracking' => '4N-TEST-1', 'raw_code' => '4N-TEST-1', 'scan_source' => 'reader',
            'scanned_on' => '2026-10-07', 'weight' => 2, 'height_cm' => 1,
            'length_cm' => 1, 'width_cm' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->get('/operaciones')->assertOk()->assertSee('href="'.route('operations.cleanup.index').'"', false);
        $this->get('/operaciones/limpiar-datos?source=system&selector=process&value='.$process)
            ->assertOk()->assertSee('Vista previa: Recepción Sistema');
        $plan = app(OperationDataCleaner::class)->plan($tenant->id, 'system', 'date', '2026-10-07');
        $this->assertSame(1, $plan['counts']['system']);
        $this->assertSame(1, $plan['counts']['processes']);
        $this->post('/operaciones/limpiar-datos', [
            'source' => 'system', 'selector' => 'date', 'value' => '2026-10-07',
            'fingerprint' => $plan['fingerprint'], 'confirm' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('Ope_RecepcionesSistema', 0);
        $this->assertDatabaseCount('Ope_RecepcionSistemaBultos', 0);
        $this->assertDatabaseMissing('Ope_Cargas', ['id' => $systemLoad]);
        $this->assertDatabaseMissing('Ope_Lotes', ['id' => $process]);
        $this->assertDatabaseHas('Ope_Cargas', ['id' => $master]);
        Storage::disk('local')->assertMissing($photo);
        Storage::disk('local')->assertExists('operations/'.$tenant->id.'/master.xlsx');
    }

    public function test_excel_cleanup_removes_selected_process_but_keeps_master_and_other_excel(): void
    {
        [$tenant, $user] = $this->supervisor();
        $master = $this->load($tenant->id, $user->id, 'master', 'operations/'.$tenant->id.'/master.xlsx');
        $first = $this->load($tenant->id, $user->id, 'reception', 'operations/'.$tenant->id.'/first.xlsx');
        $second = $this->load($tenant->id, $user->id, 'reception', 'operations/'.$tenant->id.'/second.xlsx');
        $process = $this->process($tenant->id, $user->id, $master, $first);
        $plan = app(OperationDataCleaner::class)->plan($tenant->id, 'excel', 'load', (string) $first);
        $this->assertSame(1, $plan['counts']['loads']);
        $this->assertSame(1, $plan['counts']['processes']);
        $this->get('/operaciones/limpiar-datos?source=excel&selector=load&value='.$first)
            ->assertOk()->assertSee('Vista previa: Recepción Excel');
        $this->post('/operaciones/limpiar-datos', [
            'source' => 'excel', 'selector' => 'load', 'value' => (string) $first,
            'fingerprint' => $plan['fingerprint'], 'confirm' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('Ope_Cargas', ['id' => $first]);
        $this->assertDatabaseMissing('Ope_Lotes', ['id' => $process]);
        $this->assertDatabaseHas('Ope_Cargas', ['id' => $second]);
        $this->assertDatabaseHas('Ope_Cargas', ['id' => $master]);
        $this->assertDatabaseHas('Ope_Auditoria', ['entity' => 'limpieza', 'action' => 'Limpiar datos']);
        Storage::disk('local')->assertMissing('operations/'.$tenant->id.'/first.xlsx');
        Storage::disk('local')->assertExists('operations/'.$tenant->id.'/second.xlsx');
    }

    public function test_master_cleanup_keeps_reception_file_and_requires_fresh_preview(): void
    {
        [$tenant, $user] = $this->supervisor();
        $master = $this->load($tenant->id, $user->id, 'master', 'operations/'.$tenant->id.'/master.xlsx');
        $excel = $this->load($tenant->id, $user->id, 'reception', 'operations/'.$tenant->id.'/excel.xlsx');
        $process = $this->process($tenant->id, $user->id, $master, $excel);
        $plan = app(OperationDataCleaner::class)->plan($tenant->id, 'master', 'all', 'all');
        $this->get('/operaciones/limpiar-datos?source=master&selector=all&value=all')
            ->assertOk()->assertSee('Vista previa: Maestro Geolize');
        $this->post('/operaciones/limpiar-datos', [
            'source' => 'master', 'selector' => 'all', 'value' => 'all',
            'fingerprint' => str_repeat('0', 64), 'confirm' => '1',
        ])->assertSessionHasErrors('selection');
        $this->assertDatabaseHas('Ope_Cargas', ['id' => $master]);
        $this->post('/operaciones/limpiar-datos', [
            'source' => 'master', 'selector' => 'all', 'value' => 'all',
            'fingerprint' => $plan['fingerprint'], 'confirm' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('Ope_Cargas', ['id' => $master]);
        $this->assertDatabaseMissing('Ope_Lotes', ['id' => $process]);
        $this->assertDatabaseHas('Ope_Cargas', ['id' => $excel]);
        Storage::disk('local')->assertMissing('operations/'.$tenant->id.'/master.xlsx');
        Storage::disk('local')->assertExists('operations/'.$tenant->id.'/excel.xlsx');
    }

    public function test_operator_cannot_open_cleanup_or_delete_data(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => 'Operario']);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($user);

        $this->get('/operaciones/limpiar-datos')->assertForbidden();
        $this->post('/operaciones/limpiar-datos', [
            'source' => 'master', 'selector' => 'all', 'value' => 'all',
            'fingerprint' => str_repeat('0', 64), 'confirm' => '1',
        ])->assertForbidden();
    }

    public function test_cleanup_keeps_processes_that_hold_reserved_cargo(): void
    {
        [$tenant, $user] = $this->supervisor();
        $master = $this->load($tenant->id, $user->id, 'master', 'operations/'.$tenant->id.'/master.xlsx');
        $reception = $this->load($tenant->id, $user->id, 'reception', 'operations/'.$tenant->id.'/reception.xlsx');
        $process = $this->process($tenant->id, $user->id, $master, $reception);
        $package = DB::table('Ope_Bultos')->insertGetId([
            'lot_id' => $process, 'tracking' => 'PKG-RESERVADO', 'weight' => 2,
            'snapshot' => '{}', 'excluded' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('Ope_Reservas')->insert([
            'tenant_id' => $tenant->id, 'source_lot_id' => $process, 'source_package_id' => $package,
            'batch_id' => '3fcb070c-0f78-436b-851d-8257049fdfc5', 'route_label' => 'Troncal · prueba',
            'role' => 'troncal', 'status' => 'pending', 'reserved_by' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $plan = app(OperationDataCleaner::class)->plan($tenant->id, 'excel', 'load', (string) $reception);
        $this->assertSame(1, $plan['counts']['reservations']);
        $this->post('/operaciones/limpiar-datos', [
            'source' => 'excel', 'selector' => 'load', 'value' => (string) $reception,
            'fingerprint' => $plan['fingerprint'], 'confirm' => '1',
        ])->assertSessionHasErrors('selection');
        $this->assertDatabaseHas('Ope_Bultos', ['id' => $package, 'excluded' => true]);
        $this->assertDatabaseHas('Ope_Reservas', ['source_package_id' => $package, 'status' => 'pending']);
    }
}
