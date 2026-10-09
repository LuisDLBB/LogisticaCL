<?php

namespace Tests\Feature;

use App\Models\Coverage;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Operations\Services\OperationAccess;
use App\Modules\Operations\Services\OperationWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperationsSystemReceptionTest extends TestCase
{
    use RefreshDatabase;

    private function member(): array
    {
        Storage::fake('local');
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => 'Operario']);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($user);

        return [$tenant, $user, DB::table('MBA_clients')->where(['tenant_id' => $tenant->id, 'is_active' => true])->firstOrFail()];
    }

    public function test_system_reception_scans_qr_with_document_photo_and_feeds_operations_without_excel(): void
    {
        [$tenant, $user, $client] = $this->member();
        $this->get('/operaciones/recepcion-sistema')->assertOk()->assertSee('Recepción Sistema')->assertSee($user->name);
        $this->post('/operaciones/recepcion-sistema', [
            'client_id' => $client->id, 'document_type' => 'guia', 'document_number' => 'G-123', 'observations' => 'Dos bultos',
        ])->assertSessionHasNoErrors();
        $reception = DB::table('Ope_RecepcionesSistema')->firstOrFail();
        $this->assertSame($user->id, $reception->user_id);
        $this->postJson("/operaciones/recepcion-sistema/{$reception->id}/bultos", [
            'raw_code' => '{"o":"4N202610076109","p":"119","t":1}',
            'weight' => '4.125', 'height_cm' => '12', 'length_cm' => '20', 'width_cm' => '30',
        ])->assertUnprocessable();

        $this->post("/operaciones/recepcion-sistema/{$reception->id}/respaldo", [
            'photo' => UploadedFile::fake()->image('guia.jpg'),
        ])->assertSessionHasNoErrors();
        $photoPath = DB::table('Ope_RecepcionesSistema')->where('id', $reception->id)->value('photo_path');
        Storage::disk('local')->assertExists($photoPath);
        $this->get("/operaciones/recepcion-sistema/{$reception->id}")->assertOk()->assertSee('system-scan-dialog')->assertSee('Escaneados')->assertSee('Tomar foto del QR');
        $this->get("/operaciones/recepcion-sistema/{$reception->id}/respaldo")->assertOk();

        $raw = '{"o":"4N202610076109","p":"119","t":1}';
        $this->postJson("/operaciones/recepcion-sistema/{$reception->id}/verificar", ['raw_code' => $raw])
            ->assertOk()->assertJsonPath('tracking', '4N202610076109-119')->assertJsonPath('next_number', 1);
        $this->postJson("/operaciones/recepcion-sistema/{$reception->id}/bultos", [
            'raw_code' => $raw, 'weight' => '4.125', 'height_cm' => '12', 'length_cm' => '20', 'width_cm' => '30',
        ])->assertCreated()->assertJsonPath('sequence', 1);
        $this->postJson("/operaciones/recepcion-sistema/{$reception->id}/verificar", ['raw_code' => $raw])
            ->assertStatus(409)->assertJsonPath('duplicate_sequence', 1);
        $this->postJson("/operaciones/recepcion-sistema/{$reception->id}/bultos", [
            'raw_code' => $raw, 'weight' => '4.125', 'height_cm' => '12', 'length_cm' => '20', 'width_cm' => '30',
        ])->assertStatus(409)->assertJsonPath('duplicate_sequence', 1);
        $this->postJson("/operaciones/recepcion-sistema/{$reception->id}/bultos", [
            'raw_code' => '{"o":"4N202610076109","p":"120","t":1}',
            'weight' => '5', 'height_cm' => '13', 'length_cm' => '21', 'width_cm' => '31',
        ])->assertCreated()->assertJsonPath('sequence', 2);
        $this->assertDatabaseCount('Ope_RecepcionSistemaBultos', 2);

        $this->post("/operaciones/recepcion-sistema/{$reception->id}/cerrar")->assertSessionHasNoErrors();
        $closed = DB::table('Ope_RecepcionesSistema')->where('id', $reception->id)->firstOrFail();
        $this->assertSame('completed', $closed->status);
        $this->assertDatabaseHas('Ope_Cargas', ['id' => $closed->load_id, 'source_type' => 'reception', 'status' => 'completed', 'row_count' => 2]);
        $source = DB::table('Ope_FilasFuente')->where(['load_id' => $closed->load_id, 'line' => 1])->firstOrFail();
        $this->assertSame('4N202610076109-119', $source->tracking);
        $this->assertEquals(4.125, json_decode($source->data, true)['weight']);
        $this->assertSame('G-123', json_decode($source->data, true)['customer_guide']);
        $this->get('/operaciones/procesos?load='.$closed->load_id)->assertOk()
            ->assertSee('Recepción Sistema #'.$reception->id)
            ->assertSee($client->commercial_name)
            ->assertSee($user->name)
            ->assertSee('Total de la recepción');

        $coverage = Coverage::factory()->create(['tenant_id' => $tenant->id, 'provider_id' => null, 'commune_name' => 'Chillán', 'effective_from' => '2026-01-01', 'effective_to' => null]);
        $master = DB::table('Ope_Cargas')->insertGetId([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'source_type' => 'master', 'filename' => 'Maestro',
            'path' => 'test-master', 'sha256' => hash('sha256', 'test-master'), 'sheet' => 'Datos', 'mapping' => '{}',
            'status' => 'completed', 'row_count' => 1, 'invalid_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('Ope_FilasFuente')->insert([
            'load_id' => $master, 'line' => 1, 'tracking' => $source->tracking,
            'raw' => '{}', 'data' => OperationAccess::json(['tracking' => $source->tracking, 'commune' => 'Chillán', 'merchant' => $client->commercial_name, 'service' => 'Normal', 'geolize_weight' => '9.000']),
            'errors' => '[]',
        ]);
        $lot = app(OperationWorkflow::class)->createLot($tenant->id, $user->id, [
            'name' => 'Sin Excel de recepción', 'operation_date' => now('America/Santiago')->toDateString(),
            'master_load_id' => $master, 'reception_load_ids' => [$closed->load_id],
        ]);
        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $lot, 'tracking' => $source->tracking, 'weight' => 4.125, 'coverage_id' => $coverage->id]);
    }

    public function test_qr_fallback_can_extract_a_fixed_segment_before_first_scan(): void
    {
        [, , $client] = $this->member();
        $this->post('/operaciones/recepcion-sistema', ['client_id' => $client->id, 'document_type' => 'factura', 'document_number' => 'F-9'])->assertSessionHasNoErrors();
        $reception = DB::table('Ope_RecepcionesSistema')->firstOrFail();
        $this->put("/operaciones/recepcion-sistema/{$reception->id}/formato-qr", [
            'qr_start_position' => 4, 'qr_length' => 7,
        ])->assertSessionHasNoErrors();
        $this->post("/operaciones/recepcion-sistema/{$reception->id}/respaldo", ['photo' => UploadedFile::fake()->image('factura.jpg')])->assertSessionHasNoErrors();
        $this->postJson("/operaciones/recepcion-sistema/{$reception->id}/verificar", ['raw_code' => 'xxxPKG-001zzz', 'scan_source' => 'camera'])
            ->assertOk()->assertJsonPath('tracking', 'PKG-001');
        $this->postJson("/operaciones/recepcion-sistema/{$reception->id}/verificar", ['raw_code' => 'PKG-001', 'scan_source' => 'reader'])
            ->assertOk()->assertJsonPath('tracking', 'PKG-001');
        $this->postJson("/operaciones/recepcion-sistema/{$reception->id}/bultos", [
            'raw_code' => 'xxxPKG-001zzz', 'scan_source' => 'camera', 'weight' => '2', 'height_cm' => '1', 'length_cm' => '2', 'width_cm' => '3',
        ])->assertCreated();
        $this->postJson("/operaciones/recepcion-sistema/{$reception->id}/bultos", [
            'raw_code' => 'DIRECT-002', 'scan_source' => 'reader', 'weight' => '3', 'height_cm' => '4', 'length_cm' => '5', 'width_cm' => '6',
        ])->assertCreated()->assertJsonPath('tracking', 'DIRECT-002');
        $this->assertDatabaseHas('Ope_RecepcionSistemaBultos', ['reception_id' => $reception->id, 'tracking' => 'DIRECT-002', 'raw_code' => 'DIRECT-002', 'scan_source' => 'reader']);
        $this->put("/operaciones/recepcion-sistema/{$reception->id}/formato-qr", [
            'qr_start_position' => 1, 'qr_length' => 3,
        ])->assertSessionHasErrors('qr_start_position');
        $this->post("/operaciones/recepcion-sistema/{$reception->id}/cerrar")->assertSessionHasNoErrors();
        $source = DB::table('Ope_FilasFuente')->firstOrFail();
        $this->assertNull(json_decode($source->data, true)['customer_guide']);
        $this->assertSame('Factura F-9', json_decode($source->data, true)['reference']);
    }

    public function test_other_tenant_cannot_read_another_reception_or_photo(): void
    {
        [$tenant, , $client] = $this->member();
        $this->post('/operaciones/recepcion-sistema', ['client_id' => $client->id, 'document_type' => 'guia', 'document_number' => 'G-1'])->assertSessionHasNoErrors();
        $reception = DB::table('Ope_RecepcionesSistema')->firstOrFail();
        $other = Tenant::factory()->create();
        $otherUser = User::factory()->create(['profile_name' => 'Operario']);
        $otherUser->tenants()->attach($other->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($otherUser)->get("/operaciones/recepcion-sistema/{$reception->id}")->assertForbidden();
        $this->get("/operaciones/recepcion-sistema/{$reception->id}/respaldo")->assertForbidden();
        $this->post('/operaciones/recepcion-sistema', ['client_id' => $client->id, 'document_type' => 'guia', 'document_number' => 'G-2'])->assertForbidden();
        $this->assertDatabaseCount('Ope_RecepcionesSistema', 1);
        $this->assertNotSame($tenant->id, $other->id);
    }
}
