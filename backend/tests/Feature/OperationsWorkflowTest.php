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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

    private function lot(int $tenant, int $user, array $readings = [], string $commune = 'Chillán', ?int $agencyCode = null): array
    {
        $coverage = Coverage::factory()->create(['tenant_id' => $tenant, 'provider_id' => null, 'commune_name' => $commune, 'ID_ComunaMatrizAgencia' => $agencyCode, 'effective_from' => '2026-01-01', 'effective_to' => null]);
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
        $this->get('/operaciones/configuracion')->assertSee('Agencias y rutas de guías');
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

    public function test_operations_weight_takes_priority_and_missing_weight_uses_only_whole_geolize_kilos(): void
    {
        [$tenant, $user] = $this->member();
        $coverage = Coverage::factory()->create(['tenant_id' => $tenant, 'provider_id' => null, 'commune_name' => 'Chillán', 'effective_from' => '2026-01-01', 'effective_to' => null]);
        $master = $this->source($tenant, $user, 'master', [
            ['PKG-1', 1.999, 'Chillán', 'Cliente Uno', 'Normal', 'Destino uno'],
            ['PKG-2', 8.999, 'Chillán', 'Cliente Dos', 'Normal', 'Destino dos'],
        ]);
        $reception = $this->source($tenant, $user, 'reception', [
            ['2026-09-28', 'PKG-1', 4.125, 'Operario Uno', 'G1'],
            ['2026-09-28', 'PKG-2', '', 'Operario Dos', 'G2'],
        ]);
        $lot = app(OperationWorkflow::class)->createLot($tenant, $user, ['name' => 'Pesos', 'operation_date' => '2026-09-28', 'master_load_id' => $master, 'reception_load_ids' => [$reception]]);

        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $lot, 'tracking' => 'PKG-1', 'weight' => 4.125]);
        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $lot, 'tracking' => 'PKG-2', 'weight' => 8]);
        $this->assertDatabaseCount('Ope_Incidencias', 0);
        $fallbackSnapshot = json_decode(DB::table('Ope_Bultos')->where(['lot_id' => $lot, 'tracking' => 'PKG-2'])->value('snapshot'), true);
        $this->assertSame('geolize', $fallbackSnapshot['weight_source']);
        $configuration = $this->configuration($tenant, $coverage->id);
        $this->departure($tenant, $user, $lot, $configuration);

        $rows = app(OperationWorkflow::class)->departureSpreadsheetRows($tenant, $lot);
        $this->assertSame([4, 8], array_column($rows, 'weight'));
        $this->get('/operaciones/procesos/'.$lot.'/salidas')->assertOk()->assertSee('8 kg');
    }

    public function test_missing_weight_in_both_sources_stays_pending_instead_of_becoming_zero(): void
    {
        [$tenant, $user] = $this->member();
        Coverage::factory()->create(['tenant_id' => $tenant, 'provider_id' => null, 'commune_name' => 'Chillán', 'effective_from' => '2026-01-01', 'effective_to' => null]);
        $master = $this->source($tenant, $user, 'master', [['PKG-1', '', 'Chillán', 'Cliente Uno', 'Normal', 'Destino uno']]);
        $reception = $this->source($tenant, $user, 'reception', [['2026-09-28', 'PKG-1', '', 'Operario Uno', 'G1']]);
        $lot = app(OperationWorkflow::class)->createLot($tenant, $user, ['name' => 'Sin peso', 'operation_date' => '2026-09-28', 'master_load_id' => $master, 'reception_load_ids' => [$reception]]);

        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $lot, 'tracking' => 'PKG-1', 'weight' => null]);
        $this->assertDatabaseHas('Ope_Incidencias', ['lot_id' => $lot, 'code' => 'weight_missing', 'resolved_at' => null]);
        $this->get('/operaciones/procesos/'.$lot)->assertOk()->assertSee('No hay peso de Operaciones');
    }

    public function test_old_master_load_can_supply_whole_weight_from_its_original_peso_column(): void
    {
        [$tenant, $user] = $this->member();
        Coverage::factory()->create(['tenant_id' => $tenant, 'provider_id' => null, 'commune_name' => 'Chillán', 'effective_from' => '2026-01-01', 'effective_to' => null]);
        $file = $this->excel('Datos', [
            ['Seguimiento paquete', 'Comuna de destino', 'Comerciante', 'Servicio', 'Dirección', 'Peso'],
            ['PKG-1', 'Chillán', 'Cliente Uno', 'Normal', 'Destino uno', 9.875],
        ]);
        $master = app(OperationImporter::class)->import($file->getPathname(), basename($file->getPathname()), 'maestro-antiguo.xlsx', $tenant, $user, 'master', 'Datos', []);
        $masterRow = DB::table('Ope_FilasFuente')->where('load_id', $master)->firstOrFail();
        $data = json_decode($masterRow->data, true);
        unset($data['geolize_weight']);
        DB::table('Ope_FilasFuente')->where('id', $masterRow->id)->update(['data' => json_encode($data)]);
        $reception = $this->source($tenant, $user, 'reception', [['2026-09-28', 'PKG-1', '', 'Operario Uno', 'G1']]);

        $lot = app(OperationWorkflow::class)->createLot($tenant, $user, ['name' => 'Peso antiguo', 'operation_date' => '2026-09-28', 'master_load_id' => $master, 'reception_load_ids' => [$reception]]);

        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $lot, 'tracking' => 'PKG-1', 'weight' => 9]);
        $this->assertDatabaseCount('Ope_Incidencias', 0);
    }

    public function test_duplicate_readings_keep_the_highest_weight_without_blocking_departures(): void
    {
        [$tenant,$user] = $this->member();
        [$lot,$coverage,,$reception] = $this->lot($tenant, $user, [
            ['2026-09-28', 'PKG-1', 14, 'Uno', '123'],
            ['2026-09-28', 'PKG-1', 6, 'Uno', '123'],
            ['2026-09-28', 'PKG-2', 2, 'Dos', '456'],
            ['2026-09-28', 'PKG-2', 5, 'Dos', '456'],
        ]);
        $configuration = $this->configuration($tenant, $coverage);
        $payload = ['name' => 'Sur', 'departure_date' => '2026-09-28', 'configuration_ids' => [$configuration]];

        $this->post('/operaciones/procesos/'.$lot.'/salidas', $payload)->assertSessionHasNoErrors();

        $selectedReading = DB::table('Ope_FilasFuente')->where('load_id', $reception)->where('tracking', 'PKG-1')->orderBy('id')->value('id');
        $secondPackageReading = DB::table('Ope_FilasFuente')->where('load_id', $reception)->where('tracking', 'PKG-2')->orderByDesc('id')->value('id');
        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $lot, 'weight' => 14, 'reading_id' => $selectedReading]);
        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $lot, 'weight' => 5, 'reading_id' => $secondPackageReading]);
        $this->assertDatabaseMissing('Ope_Incidencias', ['lot_id' => $lot, 'code' => 'reading_conflict']);
        $this->assertSame(2, DB::table('Ope_Bultos')->where('lot_id', $lot)->count());
        $this->assertDatabaseCount('Ope_ProgramacionSalidas', 1);
    }

    public function test_supervisor_can_save_multiple_resolutions_together(): void
    {
        [$tenant, $user] = $this->member();
        [$lot,,,$reception] = $this->lot($tenant, $user, [
            ['2026-09-28', 'PKG-1', 4, 'Uno', '123'],
            ['2026-09-28', 'PKG-1', 6, 'Uno', '123'],
            ['2026-09-28', '#VALUE!', 2, 'Uno', '123'],
        ]);
        $readingIds = DB::table('Ope_FilasFuente')->where('load_id', $reception)->where('tracking', 'PKG-1')->orderBy('id')->pluck('id')->all();
        $readingIssue = DB::table('Ope_Incidencias')->insertGetId([
            'lot_id' => $lot, 'package_id' => DB::table('Ope_Bultos')->where('lot_id', $lot)->value('id'),
            'code' => 'reading_conflict', 'message' => 'Lecturas duplicadas anteriores.',
            'context' => json_encode(['row_ids' => $readingIds, 'readings' => [['weight' => '4.000'], ['weight' => '6.000']]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $invalidIssue = DB::table('Ope_Incidencias')->where(['lot_id' => $lot, 'code' => 'invalid_source'])->firstOrFail();

        $this->postJson('/operaciones/procesos/'.$lot.'/incidencias', ['resolutions' => [
            ['issue_id' => $readingIssue, 'action' => 'reading', 'reading_id' => $readingIds[1]],
            ['issue_id' => $invalidIssue->id, 'action' => 'exclude', 'reason' => 'La fila carece del código completo del paquete.'],
        ]])->assertOk()->assertJson(['saved' => 2]);

        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $lot, 'weight' => 6]);
        $this->assertNotNull(DB::table('Ope_Incidencias')->where('id', $readingIssue)->value('resolved_at'));
        $this->assertSame('Lectura duplicada: se conservó la lectura de mayor peso.', DB::table('Ope_Incidencias')->where('id', $readingIssue)->value('resolution'));
        $this->assertNotNull(DB::table('Ope_Incidencias')->where('id', $invalidIssue->id)->value('resolved_at'));
        $this->assertSame(2, DB::table('Ope_Auditoria')->where('action', 'Resolver incidencia')->count());
    }

    public function test_older_duplicate_issue_needs_a_reason_when_choosing_a_lower_weight(): void
    {
        [$tenant, $user] = $this->member();
        [$lot,,,$reception] = $this->lot($tenant, $user, [
            ['2026-09-28', 'PKG-1', 4, 'Uno', '123'],
            ['2026-09-28', 'PKG-1', 6, 'Uno', '123'],
        ]);
        $readingIds = DB::table('Ope_FilasFuente')->where('load_id', $reception)->where('tracking', 'PKG-1')->orderBy('id')->pluck('id')->all();
        $issue = DB::table('Ope_Incidencias')->insertGetId([
            'lot_id' => $lot, 'package_id' => DB::table('Ope_Bultos')->where('lot_id', $lot)->value('id'),
            'code' => 'reading_conflict', 'message' => 'Lecturas duplicadas anteriores.',
            'context' => json_encode(['row_ids' => $readingIds, 'readings' => [['weight' => '4.000'], ['weight' => '6.000']]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->post('/operaciones/procesos/'.$lot.'/incidencias/'.$issue, ['action' => 'reading', 'reading_id' => $readingIds[0]])->assertSessionHasErrors('reason');
        $this->assertNull(DB::table('Ope_Incidencias')->where('id', $issue)->value('resolved_at'));
        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $lot, 'weight' => 6]);

        $this->post('/operaciones/procesos/'.$lot.'/incidencias/'.$issue, [
            'action' => 'reading', 'reading_id' => $readingIds[0], 'reason' => 'El operario confirmó que 4 kg es el peso correcto.',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $lot, 'weight' => 4, 'reading_id' => $readingIds[0]]);
    }

    public function test_bulk_resolution_rolls_back_when_one_issue_is_invalid(): void
    {
        [$tenant, $user] = $this->member();
        [$lot,,,$reception] = $this->lot($tenant, $user, [['2026-09-28', 'PKG-1', 4, 'Uno', '123'], ['2026-09-28', 'PKG-1', 6, 'Uno', '123']]);
        $readingIds = DB::table('Ope_FilasFuente')->where('load_id', $reception)->where('tracking', 'PKG-1')->orderBy('id')->pluck('id')->all();
        $issue = DB::table('Ope_Incidencias')->insertGetId([
            'lot_id' => $lot, 'package_id' => DB::table('Ope_Bultos')->where('lot_id', $lot)->value('id'),
            'code' => 'reading_conflict', 'message' => 'Lecturas duplicadas anteriores.',
            'context' => json_encode(['row_ids' => $readingIds, 'readings' => [['weight' => '4.000'], ['weight' => '6.000']]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/operaciones/procesos/'.$lot.'/incidencias', ['resolutions' => [
            ['issue_id' => $issue, 'action' => 'reading', 'reading_id' => $readingIds[1]],
            ['issue_id' => 999999, 'action' => 'exclude', 'reason' => 'La segunda incidencia no existe en este proceso.'],
        ]])->assertNotFound();

        $this->assertNull(DB::table('Ope_Incidencias')->where('id', $issue)->value('resolved_at'));
        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $lot, 'weight' => 6]);
        $this->assertSame(0, DB::table('Ope_Auditoria')->where('action', 'Resolver incidencia')->count());
    }

    public function test_exact_duplicate_readings_count_once_and_duplicate_coverages_are_blocked(): void
    {
        [$tenant,$user] = $this->member();
        [$lot,$coverage,$master,$reception] = $this->lot($tenant, $user, [['2026-09-28', 'PKG-1', 6, 'Uno', '123'], ['2026-09-28', 'PKG-1', 6, 'Uno', '123']]);
        $duplicate = Coverage::factory()->create(['tenant_id' => $tenant, 'provider_id' => null, 'commune_name' => 'Chillán', 'is_active' => true]);

        $this->post('/operaciones/procesos', ['name' => 'Ambigua', 'operation_date' => '2026-09-28', 'master_load_id' => $master, 'reception_load_ids' => [$reception]])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $lot, 'weight' => 6]);
        $this->assertDatabaseHas('Ope_Incidencias', ['code' => 'coverage_conflict']);
        $this->assertSame(1, DB::table('Ope_Bultos')->where('lot_id', $lot)->count());
        $conflictLot = DB::table('Ope_Incidencias')->where('code', 'coverage_conflict')->value('lot_id');
        $this->get('/operaciones/procesos/'.$conflictLot)
            ->assertOk()
            ->assertSee('Datos de Maestro Geolize para identificar la cobertura')
            ->assertSee('Cliente Uno')
            ->assertSee('Calle del destinatario')
            ->assertSee('Ver todos los valores originales de la fila Geolize')
            ->assertSee('999');

        $duplicate->update(['commune_name' => ' CHILLAN ']);
        $this->get('/operaciones/procesos/'.$conflictLot)
            ->assertOk()
            ->assertSee('Coincidencia exacta con la comuna de Geolize')
            ->assertSee('cobertura #'.$coverage);
    }

    public function test_accented_commune_chooses_its_exact_coverage_when_an_unaccented_variant_exists(): void
    {
        [$tenant, $user] = $this->member();
        [, $accentedCoverage, $master, $reception] = $this->lot($tenant, $user, [], 'Cañete');
        $interpretedMaster = $this->source($tenant, $user, 'master', [['PKG-1', 999, 'CAÑETE', 'Cliente Uno', 'Normal', 'Calle del destinatario']]);
        $interpretedLot = app(OperationWorkflow::class)->createLot($tenant, $user, ['name' => 'Interpretada', 'operation_date' => '2026-09-28', 'master_load_id' => $interpretedMaster, 'reception_load_ids' => [$reception]]);

        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $interpretedLot, 'tracking' => 'PKG-1', 'coverage_id' => $accentedCoverage]);
        $this->assertDatabaseMissing('Ope_Incidencias', ['lot_id' => $interpretedLot, 'code' => 'coverage_conflict']);

        $unaccentedMaster = $this->source($tenant, $user, 'master', [['PKG-1', 999, 'Canete', 'Cliente Uno', 'Normal', 'Calle del destinatario']]);
        $fallbackLot = app(OperationWorkflow::class)->createLot($tenant, $user, ['name' => 'Sin tilde', 'operation_date' => '2026-09-28', 'master_load_id' => $unaccentedMaster, 'reception_load_ids' => [$reception]]);

        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $fallbackLot, 'tracking' => 'PKG-1', 'coverage_id' => $accentedCoverage]);
        $this->assertDatabaseMissing('Ope_Incidencias', ['lot_id' => $fallbackLot, 'code' => 'coverage_conflict']);

        $unaccentedCoverage = Coverage::factory()->create(['tenant_id' => $tenant, 'provider_id' => null, 'commune_name' => 'Canete', 'is_active' => true]);
        Coverage::factory()->create(['tenant_id' => $tenant, 'provider_id' => null, 'commune_name' => ' caÑete ', 'is_active' => true]);

        $accentedLot = app(OperationWorkflow::class)->createLot($tenant, $user, ['name' => 'Cañete', 'operation_date' => '2026-09-28', 'master_load_id' => $master, 'reception_load_ids' => [$reception]]);

        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $accentedLot, 'tracking' => 'PKG-1', 'coverage_id' => $accentedCoverage]);
        $this->assertDatabaseMissing('Ope_Incidencias', ['lot_id' => $accentedLot, 'code' => 'coverage_conflict']);

        $unaccentedLot = app(OperationWorkflow::class)->createLot($tenant, $user, ['name' => 'Canete', 'operation_date' => '2026-09-28', 'master_load_id' => $unaccentedMaster, 'reception_load_ids' => [$reception]]);

        $this->assertDatabaseHas('Ope_Bultos', ['lot_id' => $unaccentedLot, 'tracking' => 'PKG-1', 'coverage_id' => $unaccentedCoverage->id]);
        $this->assertDatabaseMissing('Ope_Incidencias', ['lot_id' => $unaccentedLot, 'code' => 'coverage_conflict']);
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
        [$lot,$coverage] = $this->lot($tenant, $user, [['2026-09-28', '#VALUE!', 2, 'Uno', '123'], ['2026-09-28', 'PKG-1', 6, 'Uno', '123']]);
        $issue = DB::table('Ope_Incidencias')->first();

        $this->post('/operaciones/ubicaciones', ['name' => 'Prohibido'])->assertForbidden();
        $this->post('/operaciones/procesos/'.$lot.'/incidencias/'.$issue->id, ['action' => 'exclude', 'reason' => 'Motivo de exclusión suficiente'])->assertForbidden();
        $this->postJson('/operaciones/procesos/'.$lot.'/incidencias', ['resolutions' => [['issue_id' => $issue->id, 'action' => 'exclude', 'reason' => 'Motivo de exclusión suficiente']]])->assertForbidden();

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
        $this->post('/operaciones/configuraciones', ['agency_id' => 999999, 'role' => 'troncal', 'template' => 'Test'])->assertSessionHasErrors('agency_id');

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

    public function test_agency_configuration_uses_the_catalog_route_and_requires_customer_guide_when_configured(): void
    {
        [$tenant,$user] = $this->member();
        [$lot,$coverage] = $this->lot($tenant, $user, [['2026-09-28', 'PKG-1', 4, 'Uno', null]]);
        DB::table('PPR_coverages')->where('id', $coverage)->update(['ID_ComunaMatrizAgencia' => 13]);
        $agencyId = DB::table('Ope_Agencias')->where(['tenant_id' => $tenant, 'agency_code' => 13])->value('id');

        $this->post('/operaciones/configuraciones', ['agency_id' => $agencyId, 'role' => 'troncal', 'template' => '{bultos} / {peso}', 'requires_customer_guide' => 1])->assertSessionHasNoErrors();

        $configuration = DB::table('Ope_GuiaConfiguraciones')->first();
        $origin = DB::table('Ope_Ubicaciones')->where('id', $configuration->origin_id)->firstOrFail();
        $destination = DB::table('Ope_Ubicaciones')->where('id', $configuration->destination_id)->firstOrFail();
        $this->assertSame('Galvarino 8481, Bodega 17', $origin->address);
        $this->assertSame('Cinco de Abril 399', $destination->address);
        $this->assertDatabaseHas('Ope_GuiaConfiguraciones', ['coverage_id' => $coverage, 'name' => 'Chillan', 'requires_customer_guide' => true]);
        $this->post('/operaciones/procesos/'.$lot.'/salidas', ['name' => 'Sur', 'departure_date' => '2026-09-28', 'configuration_ids' => [$configuration->id]])->assertSessionHasErrors('configuration_ids');
        $this->assertDatabaseCount('Ope_ProgramacionSalidas', 0);
    }

    public function test_reception_packages_prepare_agency_guides_without_manual_configuration(): void
    {
        [$tenant, $user] = $this->member();
        [$lot, $coverage] = $this->lot($tenant, $user, [], 'Chillán', 15);

        $this->assertDatabaseCount('Ope_Bultos', 1);
        $this->assertDatabaseMissing('Ope_Bultos', ['tracking' => 'PKG-2']);
        $this->assertSame(3, DB::table('Ope_GuiaConfiguraciones')->where('coverage_id', $coverage)->count());
        $configurations = DB::table('Ope_GuiaConfiguraciones')->where('coverage_id', $coverage)->orderBy('sequence')->get();
        $this->assertSame(['troncal', 'posta1', 'posta2'], $configurations->pluck('role')->all());
        $this->assertSame($configurations[0]->destination_id, $configurations[1]->origin_id);
        $this->assertSame($configurations[1]->destination_id, $configurations[2]->origin_id);
        $this->assertSame($configurations[0]->destination_id, $configurations[1]->destination_id);
        $this->assertSame('Gran Bretaña', DB::table('Ope_Ubicaciones')->where('id', $configurations[2]->destination_id)->value('address'));

        $this->get('/operaciones/configuracion')->assertOk()->assertSee('no necesitas configurar guías aquí')->assertDontSee('Configurar guías por agencia');
        $this->get('/operaciones/procesos/'.$lot.'/salidas')->assertOk()->assertSee('Posta 2')->assertDontSee('Falta configurar');
        $this->get('/operaciones/procesos/'.$lot.'/salidas')->assertOk();
        $this->assertSame(3, DB::table('Ope_GuiaConfiguraciones')->where('coverage_id', $coverage)->count());

        foreach ($configurations as $configuration) {
            $this->post('/operaciones/procesos/'.$lot.'/salidas', [
                'name' => 'Salida de prueba', 'departure_date' => '2026-09-28', 'configuration_ids' => [$configuration->id],
            ])->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('Ope_ProgramacionSalidas', 3);
        $this->assertDatabaseCount('Ope_BultoTramos', 3);
    }

    public function test_aerial_post_guides_start_at_their_airport_and_end_at_the_agency(): void
    {
        [$tenant, $user] = $this->member();
        [$lot, $coverage] = $this->lot($tenant, $user, [], 'Antofagasta', 1);
        $configurations = DB::table('Ope_GuiaConfiguraciones')->where('coverage_id', $coverage)->orderBy('sequence')->get();
        $this->assertSame(['troncal', 'posta1'], $configurations->pluck('role')->all());

        $origin = DB::table('Ope_Ubicaciones')->where('id', $configurations[1]->origin_id)->firstOrFail();
        $destination = DB::table('Ope_Ubicaciones')->where('id', $configurations[1]->destination_id)->firstOrFail();
        $this->assertSame('Aeropuerto Andrés Sabella Gálvez', $origin->address);
        $this->assertSame('Antofagasta', $origin->commune);
        $this->assertSame('Manutara 1090', $destination->address);

        foreach ($configurations as $configuration) {
            $this->post('/operaciones/procesos/'.$lot.'/salidas', [
                'name' => 'Aéreo', 'departure_date' => '2026-09-28', 'configuration_ids' => [$configuration->id],
            ])->assertSessionHasNoErrors();
        }
        $rows = app(OperationWorkflow::class)->departureSpreadsheetRows($tenant, $lot);
        $this->assertSame('Aeropuerto Andrés Sabella Gálvez', $rows[1]['origin_address']);
        $this->assertSame('Manutara 1090', $rows[1]['destination_address']);
    }

    public function test_second_post_can_start_at_its_own_registered_address(): void
    {
        [$tenant, $user] = $this->member();
        [$lot, $coverage] = $this->lot($tenant, $user, [], 'Concepción', 15);
        $post = DB::table('Ope_Postas')->where(['tenant_id' => $tenant, 'post_code' => 9])->firstOrFail();
        $this->put('/operaciones/origenes-postas/'.$post->id, [
            'origin_address' => 'Terminal de relevo', 'origin_commune' => 'Concepción',
        ])->assertSessionHasNoErrors();

        $configurations = DB::table('Ope_GuiaConfiguraciones')->where('coverage_id', $coverage)->orderBy('sequence')->get();
        $this->assertSame('Terminal de relevo', DB::table('Ope_Ubicaciones')->where('id', $configurations[2]->origin_id)->value('address'));
        $this->assertSame('Gran Bretaña', DB::table('Ope_Ubicaciones')->where('id', $configurations[2]->destination_id)->value('address'));
        foreach ($configurations as $configuration) {
            $this->post('/operaciones/procesos/'.$lot.'/salidas', [
                'name' => 'Relevo', 'departure_date' => '2026-09-28', 'configuration_ids' => [$configuration->id],
            ])->assertSessionHasNoErrors();
        }
        $rows = app(OperationWorkflow::class)->departureSpreadsheetRows($tenant, $lot);
        $this->assertSame('Terminal de relevo', $rows[2]['origin_address']);
    }

    public function test_changing_a_post_origin_refreshes_pending_guides_but_preserves_approved_history(): void
    {
        [$tenant, $user] = $this->member();
        [$lot, $coverage] = $this->lot($tenant, $user, [], 'Antofagasta', 1);
        $configurations = DB::table('Ope_GuiaConfiguraciones')->where('coverage_id', $coverage)->orderBy('sequence')->get();
        foreach ($configurations as $configuration) {
            $this->departure($tenant, $user, $lot, $configuration->id);
        }
        $post = DB::table('Ope_Postas')->where(['tenant_id' => $tenant, 'post_code' => 1])->firstOrFail();

        $this->put('/operaciones/origenes-postas/'.$post->id, [
            'origin_address' => 'Terminal de carga nuevo', 'origin_commune' => 'Antofagasta',
        ])->assertSessionHasNoErrors();

        $postConfiguration = DB::table('Ope_GuiaConfiguraciones')->where('id', $configurations[1]->id)->firstOrFail();
        $this->assertSame('Terminal de carga nuevo', DB::table('Ope_Ubicaciones')->where('id', $postConfiguration->origin_id)->value('address'));
        $departure = DB::table('Ope_ProgramacionSalidas')->where(['lot_id' => $lot, 'role' => 'posta1'])->firstOrFail();
        $snapshot = app(OperationWorkflow::class)->preview($departure->id);
        $this->assertSame('Terminal de carga nuevo', $snapshot['origin']['address']);
        $this->assertSame('Terminal de carga nuevo', app(OperationWorkflow::class)->departureSpreadsheetRows($tenant, $lot)[1]['origin_address']);

        $trunkDeparture = DB::table('Ope_ProgramacionSalidas')->where(['lot_id' => $lot, 'role' => 'troncal'])->value('id');
        app(OperationWorkflow::class)->approve($tenant, $user, $trunkDeparture);
        $guide = app(OperationWorkflow::class)->approve($tenant, $user, $departure->id);
        $this->put('/operaciones/origenes-postas/'.$post->id, [
            'origin_address' => 'Terminal de carga posterior', 'origin_commune' => 'Antofagasta',
        ])->assertSessionHasNoErrors();
        $historical = json_decode(DB::table('Ope_Guias')->where('id', $guide)->value('snapshot'), true);
        $this->assertSame('Terminal de carga nuevo', $historical['origin']['address']);
    }

    public function test_all_agency_legs_can_be_programmed_together_for_later_review(): void
    {
        [$tenant, $user] = $this->member();
        [$lot, $coverage] = $this->lot($tenant, $user, [], 'Chillán', 15);
        $this->get('/operaciones/procesos/'.$lot.'/salidas')
            ->assertSee('Seleccionar todas las disponibles')
            ->assertSee('type="checkbox" name="configuration_ids[]"', false);
        $configurationIds = DB::table('Ope_GuiaConfiguraciones')->where('coverage_id', $coverage)->orderByDesc('sequence')->pluck('id')->all();

        $this->post('/operaciones/procesos/'.$lot.'/salidas', [
            'name' => 'Viaje masivo', 'departure_date' => '2026-09-28', 'configuration_ids' => $configurationIds,
        ])->assertRedirect(route('operations.departures.index', $lot))->assertSessionHasNoErrors();

        $this->assertSame(['troncal', 'posta1', 'posta2'], DB::table('Ope_ProgramacionSalidas')->where('lot_id', $lot)->orderBy('id')->pluck('role')->all());
        $this->assertSame(3, DB::table('Ope_BultoTramos')->count());
        $this->assertSame(3, DB::table('Ope_ProgramacionSalidas')->where('status', 'draft')->count());
        $this->assertDatabaseCount('Ope_Guias', 0);
        $this->get('/operaciones/procesos/'.$lot.'/salidas')->assertSee('Revisar salida y guía')->assertSee('3 filas de las salidas vigentes');
        $rows = app(OperationWorkflow::class)->departureSpreadsheetRows($tenant, $lot);
        $this->assertSame(['Troncal', 'Posta 1', 'Posta 2'], array_map(fn ($row): string => explode(' · ', $row['transport'])[0], $rows));
        $this->assertSame([1, 1, 1], array_column($rows, 'number'));
    }

    public function test_spreadsheet_numbers_restart_for_each_transport_and_destination_agency(): void
    {
        [$tenant, $user] = $this->member();
        $buin = Coverage::factory()->create(['tenant_id' => $tenant, 'commune_name' => 'Buin', 'ID_ComunaMatrizAgencia' => 8]);
        $rancagua = Coverage::factory()->create(['tenant_id' => $tenant, 'commune_name' => 'Rancagua', 'ID_ComunaMatrizAgencia' => 9]);
        $master = $this->source($tenant, $user, 'master', [
            ['PKG-1', 1, 'Buin', 'Cliente Uno', 'Normal', 'Destino uno'],
            ['PKG-2', 2, 'Buin', 'Cliente Dos', 'Normal', 'Destino dos'],
            ['PKG-3', 3, 'Rancagua', 'Cliente Tres', 'Normal', 'Destino tres'],
            ['PKG-4', 4, 'Rancagua', 'Cliente Cuatro', 'Normal', 'Destino cuatro'],
        ]);
        $reception = $this->source($tenant, $user, 'reception', [
            ['2026-09-28', 'PKG-1', 1, 'Operario Uno', 'G1'],
            ['2026-09-28', 'PKG-2', 2, 'Operario Uno', 'G2'],
            ['2026-09-28', 'PKG-3', 3, 'Operario Uno', 'G3'],
            ['2026-09-28', 'PKG-4', 4, 'Operario Uno', 'G4'],
        ]);
        $lot = app(OperationWorkflow::class)->createLot($tenant, $user, ['name' => 'Dos destinos', 'operation_date' => '2026-09-28', 'master_load_id' => $master, 'reception_load_ids' => [$reception]]);
        $configurationIds = DB::table('Ope_GuiaConfiguraciones')->whereIn('coverage_id', [$buin->id, $rancagua->id])->where('role', 'troncal')->pluck('id')->all();
        $departures = app(OperationWorkflow::class)->createDepartures($tenant, $user, $lot, ['name' => 'Salida Sur', 'departure_date' => '2026-09-28', 'configuration_ids' => $configurationIds]);
        $legacySnapshot = DB::table('Ope_SalidaAgencias')->where('departure_id', $departures[0])->firstOrFail();
        $snapshot = json_decode($legacySnapshot->snapshot, true);
        unset($snapshot['transport']);
        DB::table('Ope_SalidaAgencias')->where('id', $legacySnapshot->id)->update(['snapshot' => json_encode($snapshot)]);

        $rows = app(OperationWorkflow::class)->departureSpreadsheetRows($tenant, $lot);
        $this->assertCount(4, $rows);
        $this->assertSame(['Buin', 'Buin', 'Rancagua', 'Rancagua'], array_column($rows, 'agency'));
        $this->assertSame([1, 2, 1, 2], array_column($rows, 'number'));
        $this->assertCount(1, array_unique(array_column($rows, 'transport')));
        $this->assertStringContainsString('Troncal Sur', $rows[0]['transport']);
        $this->get('/operaciones/procesos/'.$lot.'/salidas')->assertOk()->assertSee('El N.º comienza en 1');
    }

    public function test_departure_spreadsheet_shows_every_guide_line_and_downloads_the_same_rows(): void
    {
        [$tenant, $user] = $this->member();
        [$lot, $coverage] = $this->lot($tenant, $user, [
            ['2026-09-28', 'PKG-1', 4.125, 'Uno', '00123'],
            ['2026-09-28', 'PKG-2', 2.250, 'Dos', '00456'],
        ]);
        $configuration = $this->configuration($tenant, $coverage);
        DB::table('Ope_GuiaConfiguraciones')->where('id', $configuration)->update(['template' => '{cliente} / {guia_cliente}: {bultos} bultos, {peso} kg']);
        $departure = $this->departure($tenant, $user, $lot, $configuration);
        $this->put('/operaciones/salidas/'.$departure.'/transporte', $this->transport())->assertSessionHasNoErrors();

        $this->get('/operaciones/procesos/'.$lot.'/salidas')
            ->assertOk()
            ->assertSee('Planilla general de salidas')
            ->assertSee('Descargar Excel')
            ->assertSee('2 filas de las salidas vigentes')
            ->assertSee('Fecha declarada')
            ->assertSee('28-09-2026')
            ->assertSee('Cliente Uno / 00123: 1 bultos, 4 kg')
            ->assertSee('Cliente Uno / 00456: 1 bultos, 2 kg');

        $response = $this->get(route('operations.departures.spreadsheet', $lot))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'operation-departures-');

        try {
            file_put_contents($path, $response->streamedContent());
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $this->assertSame(['Fecha declarada', 'Transporte', 'N.º', 'Dirección origen', 'Comuna origen', 'Patente', 'RUT chofer', 'Nombre chofer', 'Dirección destino', 'Comuna destino', 'Agencia', 'Glosa', 'Bultos', 'Suma de peso'], $sheet->rangeToArray('A1:N1')[0]);
            $this->assertSame(3, $sheet->getHighestRow());
            $this->assertSame('28-09-2026', $sheet->getCell('A2')->getFormattedValue());
            $this->assertSame('Troncal', $sheet->getCell('B2')->getValue());
            $this->assertSame(1, $sheet->getCell('C2')->getValue());
            $this->assertSame(2, $sheet->getCell('C3')->getValue());
            $this->assertSame('Dirección origen', $sheet->getCell('D2')->getValue());
            $this->assertSame('Santiago', $sheet->getCell('E2')->getValue());
            $this->assertSame('ABCD12', $sheet->getCell('F2')->getValue());
            $this->assertSame('12345678-5', $sheet->getCell('G2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('G2')->getDataType());
            $this->assertSame('Chofer Uno', $sheet->getCell('H2')->getValue());
            $this->assertSame('Dirección destino', $sheet->getCell('I2')->getValue());
            $this->assertSame('Chillán', $sheet->getCell('J2')->getValue());
            $this->assertSame('Agencia Chillán', $sheet->getCell('K2')->getValue());
            $this->assertSame('Cliente Uno / 00123: 1 bultos, 4 kg', $sheet->getCell('L2')->getValue());
            $this->assertSame(1, $sheet->getCell('M2')->getValue());
            $this->assertSame(4, $sheet->getCell('N2')->getValue());
            $this->assertSame(2, $sheet->getCell('N3')->getValue());
            $this->assertSame('0', $sheet->getCell('N2')->getStyle()->getNumberFormat()->getFormatCode());
            $spreadsheet->disconnectWorksheets();
        } finally {
            unlink($path);
        }

        $this->post('/operaciones/salidas/'.$departure.'/cancelar', ['reason' => 'Se reinicia la programación de la agencia.'])->assertSessionHasNoErrors();
        $this->get('/operaciones/procesos/'.$lot.'/salidas')->assertOk()->assertSee('0 filas de las salidas vigentes');
        $emptyResponse = $this->get(route('operations.departures.spreadsheet', $lot))->assertOk();
        $emptyPath = tempnam(sys_get_temp_dir(), 'operation-empty-');

        try {
            file_put_contents($emptyPath, $emptyResponse->streamedContent());
            $emptySheet = IOFactory::load($emptyPath);
            $this->assertSame(1, $emptySheet->getActiveSheet()->getHighestRow());
            $emptySheet->disconnectWorksheets();
        } finally {
            unlink($emptyPath);
        }

        $replacement = $this->departure($tenant, $user, $lot, $configuration);
        $this->put('/operaciones/salidas/'.$replacement.'/transporte', $this->transport())->assertSessionHasNoErrors();
        $this->post('/operaciones/salidas/'.$replacement.'/aprobar', ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->get('/operaciones/procesos/'.$lot.'/salidas')->assertOk()->assertSee('2 filas de las salidas vigentes');
        $approvedResponse = $this->get(route('operations.departures.spreadsheet', $lot))->assertOk();
        $approvedPath = tempnam(sys_get_temp_dir(), 'operation-approved-');

        try {
            file_put_contents($approvedPath, $approvedResponse->streamedContent());
            $approvedSheet = IOFactory::load($approvedPath);
            $this->assertSame(3, $approvedSheet->getActiveSheet()->getHighestRow());
            $this->assertSame('Cliente Uno / 00123: 1 bultos, 4 kg', $approvedSheet->getActiveSheet()->getCell('L2')->getValue());
            $approvedSheet->disconnectWorksheets();
        } finally {
            unlink($approvedPath);
        }
    }

    public function test_mass_programming_rolls_back_every_agency_if_one_lacks_required_data(): void
    {
        [$tenant, $user] = $this->member();
        $firstCoverage = Coverage::factory()->create(['tenant_id' => $tenant, 'commune_name' => 'Calama', 'ID_ComunaMatrizAgencia' => 3]);
        $secondCoverage = Coverage::factory()->create(['tenant_id' => $tenant, 'commune_name' => 'Concepcion', 'ID_ComunaMatrizAgencia' => 15]);
        $master = $this->source($tenant, $user, 'master', [
            ['PKG-1', 2, 'Calama', 'Cliente Uno', 'Normal', 'Destino uno'],
            ['PKG-2', 3, 'Concepcion', 'Cliente Dos', 'Normal', 'Destino dos'],
        ]);
        $reception = $this->source($tenant, $user, 'reception', [
            ['2026-09-28', 'PKG-1', 2, 'Uno', '123'],
            ['2026-09-28', 'PKG-2', 3, 'Dos', null],
        ]);
        $lot = app(OperationWorkflow::class)->createLot($tenant, $user, [
            'name' => 'Dos agencias', 'operation_date' => '2026-09-28',
            'master_load_id' => $master, 'reception_load_ids' => [$reception],
        ]);
        $firstConfiguration = DB::table('Ope_GuiaConfiguraciones')->where(['coverage_id' => $firstCoverage->id, 'role' => 'troncal'])->value('id');
        $secondConfiguration = DB::table('Ope_GuiaConfiguraciones')->where(['coverage_id' => $secondCoverage->id, 'role' => 'troncal'])->value('id');
        DB::table('Ope_GuiaConfiguraciones')->where('id', $secondConfiguration)->update(['requires_customer_guide' => true]);
        $payload = ['name' => 'Salida conjunta', 'departure_date' => '2026-09-28', 'configuration_ids' => [$secondConfiguration, $firstConfiguration]];

        $this->post('/operaciones/procesos/'.$lot.'/salidas', $payload)->assertSessionHasErrors('configuration_ids');
        $this->assertDatabaseCount('Ope_ProgramacionSalidas', 0);
        $this->assertDatabaseCount('Ope_BultoTramos', 0);

        DB::table('Ope_GuiaConfiguraciones')->where('id', $secondConfiguration)->update(['requires_customer_guide' => false]);
        $this->post('/operaciones/procesos/'.$lot.'/salidas', $payload)
            ->assertRedirect(route('operations.departures.index', $lot))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('Ope_ProgramacionSalidas', 2);
        $this->assertDatabaseCount('Ope_BultoTramos', 2);
        $this->assertDatabaseCount('Ope_Guias', 0);
    }

    public function test_agency_without_second_post_prepares_only_two_automatic_legs(): void
    {
        [$tenant, $user] = $this->member();
        [$lot, $coverage] = $this->lot($tenant, $user, [], 'Chillán', 3);

        $this->assertSame(['troncal', 'posta1'], DB::table('Ope_GuiaConfiguraciones')->where('coverage_id', $coverage)->orderBy('sequence')->pluck('role')->all());
        $final = DB::table('Ope_GuiaConfiguraciones')->where(['coverage_id' => $coverage, 'role' => 'posta1'])->firstOrFail();
        $this->assertSame('Pasaje las canteras 959 villa kamac Mayu', DB::table('Ope_Ubicaciones')->where('id', $final->destination_id)->value('address'));
        $this->get('/operaciones/procesos/'.$lot.'/salidas')->assertOk()->assertDontSee('No se pudo completar la ruta automática');
    }

    public function test_missing_agency_delivery_address_is_reported_without_inventing_a_destination(): void
    {
        [$tenant, $user] = $this->member();
        [$lot, $coverage] = $this->lot($tenant, $user, [], 'Chillán', 16);
        $agency = DB::table('Ope_Agencias')->where(['tenant_id' => $tenant, 'agency_code' => 16])->firstOrFail();

        $this->assertSame(2, DB::table('Ope_GuiaConfiguraciones')->where('coverage_id', $coverage)->count());
        $this->assertDatabaseMissing('Ope_GuiaConfiguraciones', ['coverage_id' => $coverage, 'role' => 'posta2']);
        $this->get('/operaciones/procesos/'.$lot.'/salidas')
            ->assertOk()
            ->assertSee('falta la dirección de entrega de la agencia')
            ->assertSee('la dirección física se registra en Agencias y guías')
            ->assertSee('Completar dirección de Los Angeles')
            ->assertSee('#agency-'.$agency->id);

        $this->put('/operaciones/agencias/'.$agency->id, ['address' => 'Destino confirmado 123', 'commune' => 'Los Angeles'])->assertSessionHasNoErrors();
        $this->get('/operaciones/procesos/'.$lot.'/salidas')->assertOk()->assertDontSee('No se pudo completar la ruta automática');
        $this->assertDatabaseHas('Ope_GuiaConfiguraciones', ['coverage_id' => $coverage, 'role' => 'posta2']);
        $destinationId = DB::table('Ope_GuiaConfiguraciones')->where(['coverage_id' => $coverage, 'role' => 'posta2'])->value('destination_id');
        $this->assertSame('Destino confirmado 123', DB::table('Ope_Ubicaciones')->where('id', $destinationId)->value('address'));
    }

    public function test_one_agency_gets_three_guides_with_catalog_transport_and_all_its_coverages(): void
    {
        [$tenant, $user] = $this->member();
        [$lot, $coverage] = $this->lot($tenant, $user);
        DB::table('PPR_coverages')->where('id', $coverage)->update(['ID_ComunaMatrizAgencia' => 15]);
        $otherCoverage = Coverage::factory()->create(['tenant_id' => $tenant, 'ID_ComunaMatrizAgencia' => 15]);
        DB::table('Ope_Bultos')->insert([
            'lot_id' => $lot, 'tracking' => 'PKG-EXTRA', 'weight' => 2.5, 'merchant' => 'Cliente Dos',
            'service' => 'Normal', 'commune' => 'Concepcion', 'coverage_id' => $otherCoverage->id,
            'snapshot' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $agencyId = DB::table('Ope_Agencias')->where(['tenant_id' => $tenant, 'agency_code' => 15])->value('id');
        $trunk = DB::table('Ope_Troncales')->where(['tenant_id' => $tenant, 'trunk_code' => 4])->firstOrFail();
        $firstPost = DB::table('Ope_Postas')->where(['tenant_id' => $tenant, 'post_code' => 8])->firstOrFail();
        $secondPost = DB::table('Ope_Postas')->where(['tenant_id' => $tenant, 'post_code' => 9])->firstOrFail();

        $this->post('/operaciones/configuraciones', ['agency_id' => $agencyId, 'role' => 'troncal', 'template' => '{bultos} bultos'])->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('Ope_GuiaConfiguraciones')->where('role', 'troncal')->count());
        $transfer = DB::table('Ope_GuiaConfiguraciones')->where('coverage_id', $coverage)->where('role', 'troncal')->firstOrFail()->destination_id;
        $this->post('/operaciones/configuraciones', ['agency_id' => $agencyId, 'role' => 'posta1', 'transfer_location_id' => $transfer, 'template' => '{bultos} bultos'])->assertSessionHasNoErrors();
        $this->post('/operaciones/configuraciones', ['agency_id' => $agencyId, 'role' => 'posta2', 'template' => '{bultos} bultos'])->assertSessionHasNoErrors();

        $this->get('/operaciones/procesos/'.$lot.'/salidas')->assertOk()->assertSee('2 coberturas')->assertSee('Troncal Sur (Chillan-Concepcion)');
        foreach (['troncal' => $trunk, 'posta1' => $firstPost, 'posta2' => $secondPost] as $role => $transport) {
            $configuration = DB::table('Ope_GuiaConfiguraciones')->where(['coverage_id' => $coverage, 'role' => $role])->firstOrFail();
            $this->post('/operaciones/procesos/'.$lot.'/salidas', [
                'name' => 'Salida de prueba', 'departure_date' => '2026-09-28', 'configuration_ids' => [$configuration->id],
            ])->assertSessionHasNoErrors();
            $departure = DB::table('Ope_ProgramacionSalidas')->orderByDesc('id')->firstOrFail();
            $driver = DB::table('Ope_Choferes')->where('id', $transport->driver_id)->firstOrFail();
            $this->assertSame($transport->plate, $departure->plate);
            $this->assertSame($driver->rut, $departure->driver_rut);
            $this->assertSame($driver->name, $departure->driver_name);
            $this->assertSame(2, DB::table('Ope_BultoTramos')->where('departure_id', $departure->id)->count());
            $this->post('/operaciones/salidas/'.$departure->id.'/aprobar', ['confirmed' => 1])->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('Ope_ProgramacionSalidas', 3);
        $this->assertDatabaseCount('Ope_Guias', 3);
        $finalLocation = DB::table('Ope_Ubicaciones')->where('id', DB::table('Ope_ProgramacionSalidas')->where('role', 'posta2')->value('destination_id'))->firstOrFail();
        $this->assertSame('Gran Bretaña', $finalLocation->address);
        $this->assertSame('Concepcion', $finalLocation->commune);

        $approvedGuide = DB::table('Ope_Guias as guide')
            ->join('Ope_ProgramacionSalidas as departure', 'departure.id', '=', 'guide.departure_id')
            ->where('departure.role', 'posta2')
            ->value('guide.snapshot');
        $this->put('/operaciones/agencias/'.$agencyId, ['address' => 'Nueva dirección 123', 'commune' => 'Concepcion'])->assertSessionHasNoErrors();
        $newDestination = DB::table('Ope_Ubicaciones')->where('id', DB::table('Ope_GuiaConfiguraciones')->where(['coverage_id' => $coverage, 'role' => 'posta2'])->value('destination_id'))->firstOrFail();
        $this->assertSame('Nueva dirección 123', $newDestination->address);
        $this->assertSame('Gran Bretaña', json_decode($approvedGuide, true)['destination']['address']);
    }

    public function test_agency_without_second_post_finishes_at_its_delivery_address(): void
    {
        [$tenant] = $this->member();
        $coverage = Coverage::factory()->create(['tenant_id' => $tenant, 'ID_ComunaMatrizAgencia' => 3]);
        $agencyId = DB::table('Ope_Agencias')->where(['tenant_id' => $tenant, 'agency_code' => 3])->value('id');

        $this->post('/operaciones/configuraciones', ['agency_id' => $agencyId, 'role' => 'troncal', 'template' => '{bultos}'])->assertSessionHasNoErrors();
        $this->post('/operaciones/configuraciones', ['agency_id' => $agencyId, 'role' => 'posta1', 'template' => '{bultos}'])->assertSessionHasNoErrors();
        $this->post('/operaciones/configuraciones', ['agency_id' => $agencyId, 'role' => 'posta2', 'template' => '{bultos}'])->assertSessionHasErrors('role');

        $this->assertSame(2, DB::table('Ope_GuiaConfiguraciones')->where('coverage_id', $coverage->id)->count());
        $final = DB::table('Ope_GuiaConfiguraciones')->where(['coverage_id' => $coverage->id, 'role' => 'posta1'])->firstOrFail();
        $destination = DB::table('Ope_Ubicaciones')->where('id', $final->destination_id)->firstOrFail();
        $this->assertSame('Pasaje las canteras 959 villa kamac Mayu', $destination->address);
        $this->assertSame('Calama', $destination->commune);
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
