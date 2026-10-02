<?php

namespace Tests\Feature;

use App\Models\Coverage;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Operations\Jobs\ImportOperationLoad;
use App\Modules\Operations\Services\OperationImporter;
use App\Modules\Operations\Services\OperationWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class OperationsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $profile = 'Administrador'): array
    {
        Storage::fake('local');
        config(['operations.import_connection' => 'sync']);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => $profile]);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($user);

        return [$tenant->id, $user->id];
    }

    private function excel(string $sheet, array $rows, bool $second = false): UploadedFile
    {
        $book = new Spreadsheet;
        if ($second) {
            $book->getActiveSheet()->setTitle('Hoja2')->setCellValue('A1', 'Resumen antiguo');
            $worksheet = $book->createSheet();
        } else {
            $worksheet = $book->getActiveSheet();
        }
        $worksheet->setTitle($sheet);
        $worksheet->fromArray($rows, null, 'A1', true);
        $file = Storage::disk('local')->path('fixture-'.bin2hex(random_bytes(8)).'.xlsx');
        (new Xlsx($book))->save($file);
        $book->disconnectWorksheets();

        return new UploadedFile($file, 'operaciones.xlsx', null, null, true);
    }

    private function source(int $tenant, int $user, string $type, array $rows): int
    {
        $headers = $type === 'master' ? ['Seguimiento paquete', 'Peso', 'Comuna de destino', 'Comerciante', 'Servicio', 'Dirección'] : ['Fecha', 'CodigoPaquete', 'PesoVolumetricoKg', 'UsuarioOperario', 'NumeroGuiaCliente'];
        $file = $this->excel('Datos', [$headers, ...$rows]);
        $mapping = $type === 'master' ? [] : ['profile' => 'custom', 'date' => 'A', 'tracking' => 'B', 'weight' => 'C', 'operator' => 'D', 'customer_guide' => 'E'];

        return app(OperationImporter::class)->import($file->getPathname(), 'fixture', 'fixture.xlsx', $tenant, $user, $type, 'Datos', $mapping);
    }

    private function lot(int $tenant, int $user, array $readings = [], string $commune = 'Chillán'): array
    {
        $coverage = Coverage::factory()->create(['tenant_id' => $tenant, 'provider_id' => null, 'commune_name' => $commune, 'effective_from' => '2026-01-01', 'effective_to' => null]);
        $master = $this->source($tenant, $user, 'master', [['PKG-1', 999, $commune, 'Cliente Uno', 'Normal', 'Calle del destinatario'], ['PKG-2', 100, $commune, 'Cliente Uno', 'Normal', 'Otra dirección']]);
        $reception = $this->source($tenant, $user, 'reception', $readings ?: [['2026-09-28', 'PKG-1', 4.125, 'Operario Uno', '00123']]);
        $id = app(OperationWorkflow::class)->createLot($tenant, $user, ['name' => 'Primera milla', 'operation_date' => '2026-09-28', 'master_load_id' => $master, 'reception_load_ids' => [$reception]]);

        return [$id, $coverage->id, $master, $reception];
    }

    private function configuration(int $tenant, int $coverage, string $role = 'troncal', ?int $origin = null): int
    {
        $origin ??= DB::table('Ope_Ubicaciones')->insertGetId(['tenant_id' => $tenant, 'name' => 'Centro Santiago', 'address' => 'Dirección origen', 'commune' => 'Santiago', 'created_at' => now(), 'updated_at' => now()]);
        $destination = DB::table('Ope_Ubicaciones')->insertGetId(['tenant_id' => $tenant, 'name' => 'Agencia destino', 'address' => 'Dirección destino', 'commune' => 'Chillán', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('Ope_GuiaConfiguraciones')->insertGetId(['tenant_id' => $tenant, 'coverage_id' => $coverage, 'sequence' => ['troncal' => 1, 'posta1' => 2, 'posta2' => 3][$role], 'role' => $role, 'name' => 'Agencia Chillán', 'origin_id' => $origin, 'destination_id' => $destination, 'template' => '{cliente}: {bultos} bultos / {peso} kg', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function departure(int $tenant, int $user, int $lot, int $configuration): int
    {
        return app(OperationWorkflow::class)->createDeparture($tenant, $user, $lot, ['name' => 'Salida Sur', 'departure_date' => '2026-09-28', 'configuration_ids' => [$configuration]]);
    }

    private function transport(): array
    {
        return ['plate' => 'AB-CD-12', 'driver_name' => 'Chofer Uno', 'driver_rut' => '12.345.678-5'];
    }

    public function test_guests_and_inactive_members_cannot_access_operations(): void
    {
        $this->get('/operaciones')->assertRedirect(route('login'));
        $user = User::factory()->create();
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user->tenants()->attach($tenant->id, ['is_active' => false]);

        $this->actingAs($user)->get('/operaciones')->assertForbidden();
    }

    public function test_member_sees_operations_navigation_and_all_starting_screens(): void
    {
        $this->member('Operario');

        $this->get('/operaciones')->assertSee('Preparar proceso');
        $this->get('/operaciones/cargas/master')->assertSee('Carga Maestro Geolize');
        $this->get('/operaciones/cargas/reception')->assertSee('Carga Recepción');
        $this->get('/operaciones/configuracion')->assertSee('Agencias y configuración');
        $this->get('/modulos/operaciones')->assertRedirect(route('operations.dashboard'));
        $this->assertDatabaseHas('user_activities', ['module' => 'Operaciones']);
    }

    public function test_receiving_legacy_file_uses_hoja1_and_preserves_operator_and_esd_as_reference(): void
    {
        $this->member();
        $file = $this->excel('Hoja1', [['Fecha', 'Cod_Barra', 'Notas (Nuevo Peso)', 'Cliente', 'Codigo_S+Bulto', 'Notas', 'Cod_seguimiento', 'Notas', 'ESD', 'PesoLanas', 'Alto', 'Largo', 'Ancho', 'KILOS'], ['2026-09-28', 'BAR', 6, 'Cliente', '4N202609286768-600', 6, '4N202609286768', 'Operario H', 'MANIFIESTO 4N', '', '', '', '', 6]], true);

        $this->post('/operaciones/cargas/reception', ['file' => $file, 'sheet' => 'Hoja1', 'profile' => 'legacy'])->assertSessionHasNoErrors();

        $row = DB::table('Ope_FilasFuente')->first();
        $data = json_decode($row->data, true);
        $this->assertSame('4N202609286768-600', $data['tracking']);
        $this->assertSame('6.000', $data['weight']);
        $this->assertSame('Operario H', $data['operator']);
        $this->assertNull($data['customer_guide']);
        $this->assertSame('MANIFIESTO 4N', $data['reference']);
        $this->get('/operaciones/carga/'.$row->load_id)->assertSee('MANIFIESTO 4N');
    }

    public function test_identical_file_is_not_loaded_twice_and_does_not_leave_an_extra_upload(): void
    {
        $this->member();
        $file = $this->excel('Sheet1', [['Seguimiento paquete', 'Comuna de destino', 'Comerciante', 'Servicio'], ['PKG-1', 'Chillán', 'Uno', 'Normal']]);
        $bytes = file_get_contents($file->getPathname());
        $this->post('/operaciones/cargas/master', ['file' => $file, 'sheet' => 'Sheet1'])->assertSessionHasNoErrors();
        $second = UploadedFile::fake()->createWithContent('master.xlsx', $bytes);

        $this->post('/operaciones/cargas/master', ['file' => $second, 'sheet' => 'Sheet1'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('Ope_Cargas', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('operations'));
    }

    public function test_lot_universe_and_weight_come_only_from_reception_and_decimal_weights_are_preserved(): void
    {
        [$tenant,$user] = $this->member();
        [$lot] = $this->lot($tenant, $user);

        $this->get('/operaciones/procesos/'.$lot)->assertSee('PKG-1')->assertDontSee('PKG-2');

        $this->assertDatabaseCount('Ope_Bultos', 1);
        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $lot, 'tracking' => 'PKG-1', 'weight' => 4.125, 'customer_guide' => '00123']);
        $this->assertDatabaseCount('Ope_Incidencias', 0);
        $this->assertDatabaseCount('PPR_movimientos_courier', 0);
    }

    public function test_conflicting_readings_block_departures_until_supervisor_selects_an_original_reading(): void
    {
        [$tenant,$user] = $this->member();
        [$lot,$coverage] = $this->lot($tenant, $user, [['2026-09-28', 'PKG-1', 14, 'Uno', '123'], ['2026-09-28', 'PKG-1', 6, 'Uno', '123']]);
        $configuration = $this->configuration($tenant, $coverage);
        $payload = ['name' => 'Sur', 'departure_date' => '2026-09-28', 'configuration_ids' => [$configuration]];

        $this->post('/operaciones/procesos/'.$lot.'/salidas', $payload)->assertSessionHasErrors('lot');

        $this->assertDatabaseCount('Ope_ProgramacionSalidas', 0);
        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $lot, 'weight' => null]);
        $issue = DB::table('Ope_Incidencias')->where('code', 'reading_conflict')->first();
        $context = json_decode($issue->context, true);
        $this->post('/operaciones/procesos/'.$lot.'/incidencias/'.$issue->id, ['action' => 'reading', 'reading_id' => $context['row_ids'][1], 'reason' => 'Se validó la segunda lectura con el operario.'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $lot, 'weight' => 6]);
        $this->assertDatabaseHas('Ope_Auditoria', ['action' => 'Resolver incidencia']);
        $this->post('/operaciones/procesos/'.$lot.'/salidas', $payload)->assertSessionHasNoErrors();
    }

    public function test_exact_duplicate_readings_count_once_and_normalized_duplicate_coverages_are_blocked(): void
    {
        [$tenant,$user] = $this->member();
        [$lot,$coverage,$master,$reception] = $this->lot($tenant, $user, [['2026-09-28', 'PKG-1', 6, 'Uno', '123'], ['2026-09-28', 'PKG-1', 6, 'Uno', '123']]);
        Coverage::factory()->create(['tenant_id' => $tenant, 'provider_id' => null, 'commune_name' => ' CHILLAN ', 'is_active' => true]);

        $this->post('/operaciones/procesos', ['name' => 'Ambigua', 'operation_date' => '2026-09-28', 'master_load_id' => $master, 'reception_load_ids' => [$reception]])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $lot, 'weight' => 6]);
        $this->assertDatabaseHas('Ope_Incidencias', ['code' => 'coverage_conflict']);
        $this->assertSame(1, DB::table('Ope_Bultos')->where('lot_id', $lot)->count());
    }

    public function test_invalid_source_and_missing_master_do_not_get_a_fallback_weight_or_disappear_silently(): void
    {
        [$tenant,$user] = $this->member();
        [$lot] = $this->lot($tenant, $user, [['2026-09-28', '#VALUE!', 10, 'Uno', '123'], ['2026-09-28', 'PKG-1', 0, 'Uno', '123'], ['2026-09-28', 'ABSENT', 4, 'Uno', '123']]);

        $this->get('/operaciones/procesos/'.$lot)->assertSee('Código completo')->assertSee('Peso volumétrico')->assertSee('ausente');

        $this->assertDatabaseHas('Ope_Incidencias', ['lot_id' => $lot, 'code' => 'invalid_source']);
        $this->assertDatabaseHas('Ope_Incidencias', ['lot_id' => $lot, 'code' => 'master_missing']);
        $this->assertDatabaseCount('Ope_Bultos', 1);
    }

    public function test_operator_cannot_edit_configuration_or_resolve_or_approve(): void
    {
        [$tenant,$user] = $this->member('Operario');
        [$lot,$coverage] = $this->lot($tenant, $user, [['2026-09-28', 'PKG-1', 14, 'Uno', '123'], ['2026-09-28', 'PKG-1', 6, 'Uno', '123']]);
        $issue = DB::table('Ope_Incidencias')->first();

        $this->post('/operaciones/ubicaciones', ['name' => 'Prohibido'])->assertForbidden();
        $this->post('/operaciones/procesos/'.$lot.'/incidencias/'.$issue->id, ['action' => 'exclude', 'reason' => 'Motivo de exclusión suficiente'])->assertForbidden();

        $this->assertDatabaseCount('Ope_Ubicaciones', 0);
        $this->assertDatabaseHas('Ope_Incidencias', ['id' => $issue->id, 'resolved_at' => null]);
    }

    public function test_cross_tenant_load_lot_departure_and_configuration_are_not_accessible(): void
    {
        [$tenant,$user] = $this->member();
        $other = Tenant::factory()->create();
        [$lot,$coverage,$master] = $this->lot($other->id, $user);
        $configuration = $this->configuration($other->id, $coverage);
        $departure = $this->departure($other->id, $user, $lot, $configuration);

        $this->get('/operaciones/carga/'.$master)->assertNotFound();
        $this->get('/operaciones/procesos/'.$lot)->assertNotFound();
        $this->get('/operaciones/salidas/'.$departure)->assertNotFound();
        $this->post('/operaciones/configuraciones', ['coverage_id' => $coverage, 'role' => 'troncal', 'name' => 'Invalid', 'origin_id' => 1, 'destination_id' => 2, 'template' => 'Test'])->assertSessionHasErrors('coverage_id');

        $this->assertDatabaseCount('Ope_Guias', 0);
    }

    public function test_supervisor_approval_is_idempotent_and_frozen_guide_survives_address_and_transport_changes(): void
    {
        [$tenant,$user] = $this->member();
        [$lot,$coverage] = $this->lot($tenant, $user);
        $configuration = $this->configuration($tenant, $coverage);
        $departure = $this->departure($tenant, $user, $lot, $configuration);
        $this->put('/operaciones/salidas/'.$departure.'/transporte', $this->transport())->assertSessionHasNoErrors();

        $this->post('/operaciones/salidas/'.$departure.'/aprobar', ['confirmed' => 1])->assertSessionHasNoErrors();

        $guide = DB::table('Ope_Guias')->first();
        $this->get('/operaciones/guias/'.$guide->id)->assertSee('Dirección destino')->assertSee('ABCD12')->assertSee('4.125');
        $this->get('/operaciones/guias/'.$guide->id.'/resumen.csv')->assertDownload('Guia_interna_'.$guide->id.'_v1.csv');
        $this->post('/operaciones/salidas/'.$departure.'/aprobar', ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('Ope_Guias', 1);
        $before = $guide->sha256;
        DB::table('Ope_Ubicaciones')->update(['address' => 'Dirección cambiada']);
        $this->put('/operaciones/salidas/'.$departure.'/transporte', [...$this->transport(), 'plate' => 'ZZZZ99'])->assertSessionHasErrors('departure');
        $this->post('/operaciones/salidas/'.$departure.'/reabrir', ['reason' => 'Corrección de transporte confirmada.'])->assertSessionHasNoErrors();
        $this->put('/operaciones/salidas/'.$departure.'/transporte', [...$this->transport(), 'plate' => 'ZZZZ99'])->assertSessionHasNoErrors();
        $this->post('/operaciones/salidas/'.$departure.'/aprobar', ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('Ope_Guias', 2);
        $this->assertDatabaseHas('Ope_Guias', ['id' => $guide->id, 'sha256' => $before]);
        $this->get('/operaciones/guias/'.$guide->id)->assertSee('Versión histórica')->assertSee('Dirección destino')->assertSee('ABCD12');
        $this->assertDatabaseHas('Ope_Auditoria', ['action' => 'Reabrir salida']);
    }

    public function test_invalid_rut_missing_transport_and_duplicate_departure_are_rejected(): void
    {
        [$tenant,$user] = $this->member();
        [$lot,$coverage] = $this->lot($tenant, $user);
        $configuration = $this->configuration($tenant, $coverage);
        $departure = $this->departure($tenant, $user, $lot, $configuration);

        $this->put('/operaciones/salidas/'.$departure.'/transporte', [...$this->transport(), 'driver_rut' => '12345678-9'])->assertSessionHasErrors('driver_rut');
        $this->put('/operaciones/salidas/'.$departure.'/transporte', [...$this->transport(), 'driver_name' => 'Pendiente'])->assertSessionHasErrors('driver_name');
        $this->post('/operaciones/salidas/'.$departure.'/aprobar', ['confirmed' => 1])->assertSessionHasErrors('departure');
        $this->post('/operaciones/procesos/'.$lot.'/salidas', ['name' => 'Duplicada', 'departure_date' => '2026-09-28', 'configuration_ids' => [$configuration]])->assertSessionHasErrors('configuration_ids');

        $this->assertDatabaseCount('Ope_ProgramacionSalidas', 1);
        $this->assertDatabaseCount('Ope_Guias', 0);
    }

    public function test_posta_requires_prior_leg_approval_and_origin_matches_prior_destination(): void
    {
        [$tenant,$user] = $this->member();
        [$lot,$coverage] = $this->lot($tenant, $user);
        $trunk = $this->configuration($tenant, $coverage);
        $origin = DB::table('Ope_GuiaConfiguraciones')->where('id', $trunk)->value('destination_id');
        $posta = $this->configuration($tenant, $coverage, 'posta1', $origin);
        $payload = ['name' => 'Posta Uno', 'departure_date' => '2026-09-28', 'configuration_ids' => [$posta]];
        $this->post('/operaciones/procesos/'.$lot.'/salidas', $payload)->assertSessionHasErrors('configuration_ids');
        $trunkDeparture = $this->departure($tenant, $user, $lot, $trunk);
        $postaDeparture = $this->departure($tenant, $user, $lot, $posta);
        $this->put('/operaciones/salidas/'.$postaDeparture.'/transporte', $this->transport())->assertSessionHasNoErrors();

        $this->post('/operaciones/salidas/'.$postaDeparture.'/aprobar', ['confirmed' => 1])->assertSessionHasErrors('departure');

        $this->put('/operaciones/salidas/'.$trunkDeparture.'/transporte', $this->transport())->assertSessionHasNoErrors();
        $this->post('/operaciones/salidas/'.$trunkDeparture.'/aprobar', ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->post('/operaciones/salidas/'.$postaDeparture.'/aprobar', ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('Ope_Guias', 2);
        $this->post('/operaciones/salidas/'.$trunkDeparture.'/reabrir', ['reason' => 'Corregir salida anterior aprobada'])->assertSessionHasErrors('departure');
        $this->get('/operaciones/procesos/'.$lot.'/salidas')->assertSee('Programada');
        $this->get('/operaciones/salidas/'.$postaDeparture)->assertSee('Dirección destino');
    }

    public function test_locations_and_configurations_use_shared_coverage_and_require_customer_guide_when_configured(): void
    {
        [$tenant,$user] = $this->member();
        [$lot,$coverage] = $this->lot($tenant, $user, [['2026-09-28', 'PKG-1', 4, 'Uno', null]]);
        $this->post('/operaciones/ubicaciones', ['name' => 'Origen', 'address' => 'Calle Origen', 'commune' => 'Santiago'])->assertSessionHasNoErrors();
        $origin = DB::table('Ope_Ubicaciones')->first()->id;
        $this->post('/operaciones/ubicaciones', ['name' => 'Destino', 'address' => 'Calle Agencia', 'commune' => 'Chillán'])->assertSessionHasNoErrors();
        $destination = DB::table('Ope_Ubicaciones')->orderByDesc('id')->first()->id;

        $this->post('/operaciones/configuraciones', ['coverage_id' => $coverage, 'role' => 'troncal', 'name' => 'Agencia', 'origin_id' => $origin, 'destination_id' => $destination, 'template' => '{bultos} / {peso}', 'requires_customer_guide' => 1])->assertSessionHasNoErrors();

        $configuration = DB::table('Ope_GuiaConfiguraciones')->first();
        $this->assertDatabaseHas('Ope_GuiaConfiguraciones', ['coverage_id' => $coverage, 'origin_id' => $origin, 'destination_id' => $destination, 'requires_customer_guide' => true]);
        $this->post('/operaciones/procesos/'.$lot.'/salidas', ['name' => 'Sur', 'departure_date' => '2026-09-28', 'configuration_ids' => [$configuration->id]])->assertSessionHasErrors('configuration_ids');
        $this->assertDatabaseCount('Ope_ProgramacionSalidas', 0);
    }

    public function test_html_escapes_location_names_and_custom_upload_rejects_missing_mapping(): void
    {
        $this->member();
        $danger = '<script>alert(1)</script>';
        $this->post('/operaciones/ubicaciones', ['name' => $danger, 'address' => 'Calle', 'commune' => 'Santiago'])->assertSessionHasNoErrors();

        $this->get('/operaciones/configuracion')->assertSee($danger)->assertDontSee($danger, false);
        $file = $this->excel('Datos', [['Fecha', 'Codigo', 'Peso', 'Usuario'], ['2026-09-28', 'PKG-1', 4, 'Uno']]);
        $this->post('/operaciones/cargas/reception', ['file' => $file, 'sheet' => 'Datos', 'profile' => 'custom'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('Ope_Cargas', ['status' => 'failed', 'row_count' => 0]);
        $this->assertDatabaseCount('Ope_FilasFuente', 0);
    }

    public function test_prepare_link_opens_form_with_current_load_selected_and_explains_missing_reception(): void
    {
        [$tenant, $user] = $this->member();
        $master = $this->source($tenant, $user, 'master', [['PKG-1', 999, 'Chillán', 'Cliente Uno', 'Normal', 'Calle destino']]);

        $this->get('/operaciones/carga/'.$master)->assertOk()->assertSee('/operaciones?load='.$master.'#preparar-proceso', false);
        $this->get('/operaciones?load='.$master)->assertOk()->assertSee('value="'.$master.'" selected', false)->assertSeeText('Falta cargar Recepción de bultos para preparar el proceso.')->assertSee('<button disabled>Preparar y cruzar datos</button>', false);
        $reception = $this->source($tenant, $user, 'reception', [['2026-09-28', 'PKG-1', 4.125, 'Operario Uno', '00123']]);

        $this->get('/operaciones?load='.$reception)->assertOk()->assertSee('value="'.$reception.'" checked', false)->assertDontSeeText('Falta cargar Recepción de bultos para preparar el proceso.')->assertDontSee('<button disabled>Preparar y cruzar datos</button>', false);
    }

    public function test_load_results_render_in_every_import_state(): void
    {
        [$tenant, $user] = $this->member();
        $load = $this->source($tenant, $user, 'reception', [['2026-09-28', 'PKG-1', 4.125, 'Operario Uno', '00123']]);

        foreach (['queued' => 'Archivo en espera', 'processing' => 'Procesando Excel', 'completed' => 'Validada', 'failed' => 'Error de importación de prueba'] as $status => $message) {
            DB::table('Ope_Cargas')->where('id', $load)->update(['status' => $status, 'error' => $status === 'failed' ? $message : null]);

            $response = $this->get('/operaciones/carga/'.$load);

            $response->assertOk()->assertSeeText($message)->assertSeeText('PKG-1');
            if (in_array($status, ['queued', 'processing'], true)) {
                $response->assertSee('setTimeout', false);
            } else {
                $response->assertDontSee('setTimeout', false);
            }
        }
    }

    public function test_departure_screen_renders_before_and_after_guide_approval(): void
    {
        [$tenant, $user] = $this->member();
        [$lot, $coverage] = $this->lot($tenant, $user);
        $configuration = $this->configuration($tenant, $coverage);
        $departure = $this->departure($tenant, $user, $lot, $configuration);

        $this->get('/operaciones/salidas/'.$departure)->assertOk()->assertSeeText('La guía se guarda al aprobar la salida.');
        $this->put('/operaciones/salidas/'.$departure.'/transporte', $this->transport())->assertSessionHasNoErrors();
        $this->post('/operaciones/salidas/'.$departure.'/aprobar', ['confirmed' => 1])->assertSessionHasNoErrors();

        $this->get('/operaciones/salidas/'.$departure)->assertOk()->assertSeeText('Guía interna #')->assertDontSeeText('La guía se guarda al aprobar la salida.');
    }

    public function test_queued_import_returns_immediately_and_job_publishes_completed_source_rows(): void
    {
        $this->member();
        Queue::fake([ImportOperationLoad::class]);
        config(['operations.import_connection' => 'operations']);
        $file = $this->excel('Sheet1', [['Seguimiento paquete', 'Comuna de destino', 'Comerciante', 'Servicio'], ['PKG-1', 'Chillán', 'Uno', 'Normal']]);

        $this->post('/operaciones/cargas/master', ['file' => $file, 'sheet' => 'Sheet1'])->assertSessionHasNoErrors();

        $load = DB::table('Ope_Cargas')->first();
        $this->assertSame('queued', $load->status);
        $this->assertDatabaseCount('Ope_FilasFuente', 0);
        Queue::assertPushed(ImportOperationLoad::class, fn ($job) => $job->loadId === $load->id);
        $this->get('/operaciones/carga/'.$load->id)->assertSee('Archivo en espera');
        (new ImportOperationLoad($load->id))->handle(app(OperationImporter::class));
        $this->assertDatabaseHas('Ope_Cargas', ['id' => $load->id, 'status' => 'completed', 'row_count' => 1]);
        $this->assertDatabaseCount('Ope_FilasFuente', 1);
    }

    public function test_cancelling_draft_departure_restores_packages_to_reserve_with_audit(): void
    {
        [$tenant,$user] = $this->member();
        [$lot,$coverage] = $this->lot($tenant, $user);
        $configuration = $this->configuration($tenant, $coverage);
        $departure = $this->departure($tenant, $user, $lot, $configuration);

        $this->post('/operaciones/salidas/'.$departure.'/cancelar', ['reason' => 'La agencia no saldrá en este viaje.'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('Ope_ProgramacionSalidas', ['id' => $departure, 'status' => 'cancelled']);
        $this->assertDatabaseCount('Ope_BultoTramos', 0);
        $this->assertDatabaseHas('Ope_Auditoria', ['action' => 'Cancelar salida']);
        $this->post('/operaciones/salidas/'.$departure.'/aprobar', ['confirmed' => 1])->assertSessionHasErrors('departure');
        $this->get('/operaciones/procesos/'.$lot.'/salidas')->assertSee('Reserva');
        $this->post('/operaciones/procesos/'.$lot.'/salidas', ['name' => 'Nueva', 'departure_date' => '2026-09-28', 'configuration_ids' => [$configuration]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('Ope_BultoTramos', 1);
    }

    public function test_failed_upload_can_retry_with_correct_sheet_without_changing_completed_sources(): void
    {
        $this->member();
        $file = $this->excel('Sheet1', [['Seguimiento paquete', 'Comuna de destino', 'Comerciante', 'Servicio'], ['PKG-1', 'Chillán', 'Uno', 'Normal']]);
        $bytes = file_get_contents($file->getPathname());
        $this->post('/operaciones/cargas/master', ['file' => $file, 'sheet' => 'Incorrecta'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('Ope_Cargas', ['status' => 'failed']);

        $this->post('/operaciones/cargas/master', ['file' => UploadedFile::fake()->createWithContent('master.xlsx', $bytes), 'sheet' => 'Sheet1'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('Ope_Cargas', 1);
        $this->assertDatabaseHas('Ope_Cargas', ['status' => 'completed', 'sheet' => 'Sheet1', 'row_count' => 1]);
        $this->post('/operaciones/cargas/master', ['file' => UploadedFile::fake()->createWithContent('master.xlsx', $bytes), 'sheet' => 'Otra'])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('Ope_FilasFuente', 1);
    }
}
