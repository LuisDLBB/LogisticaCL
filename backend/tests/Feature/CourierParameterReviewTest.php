<?php

namespace Tests\Feature;

use App\Models\Banco;
use App\Models\Client;
use App\Models\CostCenter;
use App\Models\CostCenterKey;
use App\Models\CourierImportError;
use App\Models\CourierMovement;
use App\Models\Coverage;
use App\Models\Provider;
use App\Models\ProviderBankAccount;
use App\Models\ServiceType;
use App\Models\Tenant;
use App\Models\TipoCuentaBancaria;
use App\Models\Vehicle;
use App\Models\WeightTransformation;
use App\Modules\ProviderPayments\ParameterReview;
use Database\Seeders\BancoSeeder;
use Database\Seeders\TipoCuentaBancariaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CourierParameterReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_file_is_required_with_a_spanish_message(): void
    {
        $this->post(route('provider-payments.courier-movements.validate'))
            ->assertSessionHasErrors(['file' => 'Archivo con problema: debes seleccionar un archivo. Formato recomendado: CSV UTF-8 con extensión .csv. También se admite Excel .xlsx.']);
    }

    public function test_csv_detected_as_plain_text_is_accepted(): void
    {
        $csv = "Seguimiento paquete,Comerciante,Servicio,Comuna,Peso,Estado\n4N20260916A,Cliente,Normal,Temuco,5,Entregado\n";
        $path = tempnam(sys_get_temp_dir(), 'courier_csv_');
        file_put_contents($path, $csv);
        $file = new UploadedFile($path, 'movimientos.csv', 'text/plain', null, true);

        $this->post(route('provider-payments.courier-movements.validate'), ['file' => $file])
            ->assertOk()
            ->assertSee('1 registros procesados');
    }

    public function test_semicolon_delimited_csv_is_detected_automatically(): void
    {
        $csv = "Seguimiento paquete;Comerciante;Servicio;Comuna;Peso;Estado\n4N20260916A;Cliente;Normal;Temuco;5;Entregado\n";
        $this->post(route('provider-payments.courier-movements.validate'), [
            'file' => UploadedFile::fake()->createWithContent('movimientos.csv', $csv),
        ])->assertOk()->assertSee('1 registros procesados')->assertSee('Cliente')->assertSee('Entregado');
    }

    public function test_four_north_is_the_default_company_for_parameter_review(): void
    {
        $this->withSession(['courier_review' => [
            'file' => 'prueba.csv',
            'records' => 0,
            'groups' => [],
            'missing_columns' => [],
        ]])->get(route('provider-payments.courier-movements.review-parameters'))
            ->assertOk()
            ->assertSee('Empresa de prueba:')
            ->assertSee('4N')
            ->assertDontSee('Selecciona una empresa');
    }

    public function test_initial_companies_are_registered_in_the_tenant_master(): void
    {
        $this->assertDatabaseHas('tenants', [
            'code' => '4N',
            'tax_id' => '77346078-7',
            'legal_name' => '4 Nortes Logistica SPA',
            'business_activity' => 'Logistica',
        ]);
        $this->assertDatabaseHas('tenants', [
            'code' => 'PMCB',
            'tax_id' => '77639015-1',
            'legal_name' => 'Transportes y Distribucion PMCB SPA',
            'business_activity' => 'Transporte de Carga por Carretera',
        ]);
    }

    public function test_windows_csv_accents_survive_json_session_storage(): void
    {
        $csv = mb_convert_encoding("Seguimiento paquete,Comerciante,Servicio,Comuna,Peso,Estado\n4N20260916A,Diseño,Distribución,Ñuñoa,5,En tránsito\n", 'Windows-1252', 'UTF-8');
        $this->post(route('provider-payments.courier-movements.validate'), [
            'file' => UploadedFile::fake()->createWithContent('movimientos.csv', $csv),
        ])->assertOk()->assertSee('En tránsito');
        $snapshot = session('courier_review');
        $restored = json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($snapshot, $restored);
        $this->withSession(['courier_review' => $restored])->get(route('provider-payments.courier-movements.review-parameters'))
            ->assertOk()->assertSee('Diseño')->assertSee('Ñuñoa')->assertDontSee('Necesitamos validar');
    }

    public function test_validation_preserves_grouped_values_and_review_shows_them(): void
    {
        $csv = "Seguimiento paquete,Comerciante,Servicio,Comuna,Peso\n4N20260916A,Cliente A,Normal,Temuco,5\n4N20260916B,Cliente A,Normal,Temuco,5\n4N20260916C,Cliente B,Especial,TEMUCO,\n";
        $this->post(route('provider-payments.courier-movements.validate'), [
            'file' => UploadedFile::fake()->createWithContent('movimientos.csv', $csv),
        ])->assertOk()->assertSessionHas('courier_review.records', 3);

        $response = $this->get(route('provider-payments.courier-movements.review-parameters'));
        $response->assertOk()->assertSee('Cliente A')->assertSee('TEMUCO')
            ->assertSee('Empresa de prueba:')->assertSee('4N')
            ->assertSee('Ingresar este valor en Comerciante (Pila)')
            ->assertSee('Crear cliente usando uno existente')
            ->assertSee('Crear combinación usando Llave Centro Costo');
    }

    public function test_comparison_is_scoped_and_matches_communes_without_changing_source_names(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $client = Client::create(['tenant_id' => $tenant->id, 'tax_id' => '1-9', 'tax_id_number' => '1', 'tax_id_check_digit' => '9', 'source_merchant_name' => 'Cliente A', 'commercial_name' => 'Comercial Cliente A', 'legal_name' => 'Cliente A Ltda']);
        $service = ServiceType::factory()->create(['name' => 'Normal']);
        $client->serviceTypes()->attach($service->id, ['is_active' => true]);
        Coverage::create(['tenant_id' => $tenant->id, 'commune_name' => 'Temuco', 'zone' => 'Regiones', 'provider_tax_id' => '1-9', 'is_active' => true]);
        Coverage::create(['tenant_id' => $other->id, 'commune_name' => 'Otra', 'zone' => 'Regiones', 'provider_tax_id' => '2-7', 'is_active' => true]);
        $input = [
            'clients' => [['values' => ['Cliente A'], 'count' => 2]],
            'services' => [['values' => ['Cliente A', 'Normal'], 'count' => 2], ['values' => ['Cliente A', 'Desconocido'], 'count' => 1]],
            'coverages' => [['values' => ['Temuco'], 'count' => 2], ['values' => ['TEMUCO'], 'count' => 3], ['values' => ['Otra'], 'count' => 4]],
            'weights' => [['values' => [''], 'count' => 1]],
        ];
        $groups = (new ParameterReview)->compare($input, $tenant->id);
        $this->assertSame([], $groups[0]['items']);
        $this->assertCount(1, $groups[1]['items']);
        $this->assertSame(4, $groups[2]['affected']);
        $this->assertSame([], $groups[3]['items']);
        $this->assertDatabaseCount('movimientos_courier', 0);
    }

    public function test_client_match_uses_comerciante_pila_instead_of_business_names(): void
    {
        $tenant = Tenant::factory()->create();
        Client::create([
            'tenant_id' => $tenant->id,
            'tax_id' => '12345678-9',
            'tax_id_number' => '12345678',
            'tax_id_check_digit' => '9',
            'source_merchant_name' => 'Pila Exacta',
            'commercial_name' => 'Otra Razón Comercial',
            'legal_name' => 'Otra Razón Social Ltda',
        ]);

        $input = [
            'clients' => [
                ['values' => ['Pila Exacta'], 'count' => 3],
                ['values' => ['Otra Razón Comercial'], 'count' => 2],
            ],
        ];
        $groups = (new ParameterReview)->compare($input, $tenant->id);

        $this->assertCount(1, $groups[0]['items']);
        $this->assertSame('Otra Razón Comercial', $groups[0]['items'][0]['values'][0]);
        $this->assertStringContainsString('Comerciante (Pila)', $groups[0]['items'][0]['action']);
    }

    public function test_client_match_ignores_case_and_only_unmatched_clients_are_reported(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $input = ['clients' => [
            ['values' => ['Revesderecho'], 'count' => 10],
            ['values' => ['Cliente inexistente'], 'count' => 2],
        ]];

        $groups = (new ParameterReview)->compare($input, $tenant->id);

        $this->assertCount(1, $groups[0]['items']);
        $this->assertSame('Cliente inexistente', $groups[0]['items'][0]['values'][0]);
        $this->assertSame(2, $groups[0]['affected']);
    }

    public function test_coverage_matching_prefers_accents_and_known_source_aliases(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        Coverage::create(['tenant_id' => $tenant->id, 'commune_name' => 'Concepcion', 'zone' => 'Regiones', 'provider_tax_id' => '1-9', 'is_active' => true]);
        Coverage::create(['tenant_id' => $tenant->id, 'commune_name' => 'CONCEPCIÓN', 'zone' => 'Regiones', 'provider_tax_id' => '1-9', 'is_active' => true]);
        Coverage::create(['tenant_id' => $tenant->id, 'commune_name' => 'Ñuñoa', 'zone' => 'RM', 'provider_tax_id' => '1-9', 'is_active' => true]);
        $input = ['coverages' => [
            ['values' => ['Concepción'], 'count' => 10],
            ['values' => ['?U?OA'], 'count' => 2],
            ['values' => ['#N/D'], 'count' => 1],
        ]];

        $groups = (new ParameterReview)->compare($input, $tenant->id);

        $this->assertCount(1, $groups[2]['items']);
        $this->assertSame('#N/D', $groups[2]['items'][0]['values'][0]);
    }

    public function test_new_coverage_copies_a_template_and_marks_the_error_resolved(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $template = Coverage::create(['tenant_id' => $tenant->id, 'commune_name' => 'Comuna Plantilla', 'matrix_commune_name' => 'Matriz', 'zone' => 'Regiones', 'provider_tax_id' => '1-9', 'delivery_frequency' => 'Lunes', 'route_code' => 'R-1', 'is_active' => true]);
        $batchId = (string) Str::uuid();
        CourierImportError::create(['tenant_id' => $tenant->id, 'batch_id' => $batchId, 'file_name' => 'prueba.csv', 'category' => 'coverages', 'source_key' => 'Comuna Nueva', 'source_values' => ['Comuna Nueva'], 'affected_records' => 2, 'action' => 'Crear cobertura', 'status' => 'PENDIENTE']);

        $this->withSession(['courier_review' => ['batch_id' => $batchId, 'file' => 'prueba.csv', 'records' => 2, 'groups' => [], 'missing_columns' => []], 'courier_review_exclusions.coverages' => ['Comuna Nueva']])
            ->post(route('provider-payments.maintainers.coberturas.store'), ['commune_name' => 'Comuna Nueva', 'template_id' => $template->id])
            ->assertRedirect(route('provider-payments.courier-movements.review-parameters'));

        $created = Coverage::query()->where('tenant_id', $tenant->id)->where('commune_name', 'Comuna Nueva')->firstOrFail();
        $this->assertSame($template->provider_tax_id, $created->provider_tax_id);
        $this->assertDatabaseHas('courier_import_errors', ['batch_id' => $batchId, 'source_key' => 'Comuna Nueva', 'status' => 'RESUELTO', 'exclude_from_import' => false]);
        $this->assertSame([], session('courier_review_exclusions.coverages'));
    }

    public function test_new_client_requires_new_identity_and_copies_a_template(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $template = Client::create(['tenant_id' => $tenant->id, 'tax_id' => '11111111-1', 'tax_id_number' => '11111111', 'tax_id_check_digit' => '1', 'source_merchant_name' => 'Plantilla', 'commercial_name' => 'Cliente Plantilla', 'legal_name' => 'Cliente Plantilla SPA', 'billing_address' => 'Dirección 123', 'billing_commune_name' => 'Santiago', 'business_activity' => 'Comercio']);
        $batchId = (string) Str::uuid();
        CourierImportError::create(['tenant_id' => $tenant->id, 'batch_id' => $batchId, 'file_name' => 'prueba.csv', 'category' => 'clients', 'source_key' => 'Cliente Nuevo', 'source_values' => ['Cliente Nuevo'], 'affected_records' => 2, 'action' => 'Crear cliente', 'status' => 'PENDIENTE']);

        $this->withSession(['courier_review' => ['batch_id' => $batchId, 'file' => 'prueba.csv', 'records' => 2, 'groups' => [], 'missing_columns' => []]])
            ->post(route('provider-payments.maintainers.clientes.store'), ['source_merchant_name' => 'Cliente Nuevo', 'tax_id' => '12.345.678-5', 'legal_name' => 'Cliente Nuevo SPA', 'template_id' => $template->id])
            ->assertRedirect(route('provider-payments.courier-movements.review-parameters'));

        $this->assertDatabaseHas('clients', ['tenant_id' => $tenant->id, 'tax_id' => '12345678-5', 'source_merchant_name' => 'Cliente Nuevo', 'legal_name' => 'Cliente Nuevo SPA', 'billing_address' => 'Dirección 123']);
        $this->assertDatabaseHas('courier_import_errors', ['batch_id' => $batchId, 'source_key' => 'Cliente Nuevo', 'status' => 'RESUELTO']);
    }

    public function test_client_creation_form_prefills_the_detected_merchant(): void
    {
        $this->get(route('provider-payments.maintainers.clientes', ['merchant' => 'Solventa']))
            ->assertOk()
            ->assertSee('Crear cliente')
            ->assertSee('value="Solventa"', false)
            ->assertSee('RUT nuevo')
            ->assertSee('Razón social nueva')
            ->assertSee('Sin plantilla: ingresar datos manualmente');
    }

    public function test_new_client_can_be_entered_manually_without_a_template(): void
    {
        $this->post(route('provider-payments.maintainers.clientes.store'), [
            'source_merchant_name' => 'Manual',
            'tax_id' => '12345678-5',
            'legal_name' => 'Cliente Manual SPA',
            'commercial_name' => 'Cliente Manual',
            'billing_company_code' => '4N',
            'billing_address' => 'Calle Manual 123',
            'billing_commune_name' => 'Santiago',
            'business_activity' => 'Comercio',
        ])->assertRedirect(route('provider-payments.maintainers.clientes'));

        $this->assertDatabaseHas('clients', [
            'tax_id' => '12345678-5',
            'legal_name' => 'Cliente Manual SPA',
            'commercial_name' => 'Cliente Manual',
            'billing_address' => 'Calle Manual 123',
        ]);
    }

    public function test_new_client_rejects_missing_identity_and_an_existing_rut(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $template = Client::create(['tenant_id' => $tenant->id, 'tax_id' => '11111111-1', 'tax_id_number' => '11111111', 'tax_id_check_digit' => '1', 'source_merchant_name' => 'Plantilla', 'commercial_name' => 'Plantilla', 'legal_name' => 'Plantilla SPA']);

        $this->post(route('provider-payments.maintainers.clientes.store'), ['template_id' => $template->id])
            ->assertSessionHasErrors(['source_merchant_name', 'tax_id', 'legal_name']);
        $this->post(route('provider-payments.maintainers.clientes.store'), ['source_merchant_name' => 'Otro', 'tax_id' => '11111111-1', 'legal_name' => 'Otro SPA', 'template_id' => $template->id])
            ->assertSessionHasErrors(['tax_id' => 'Este RUT ya pertenece a otro cliente de 4N.']);
    }

    public function test_service_combination_copies_a_cost_center_key_template(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $client = Client::create(['tenant_id' => $tenant->id, 'tax_id' => '12345678-5', 'tax_id_number' => '12345678', 'tax_id_check_digit' => '5', 'source_merchant_name' => 'Cliente Nuevo', 'commercial_name' => 'Cliente Nuevo', 'legal_name' => 'Cliente Nuevo SPA']);
        $service = ServiceType::factory()->create(['service_code' => 99, 'name' => 'Servicio Nuevo']);
        CostCenter::updateOrCreate(['cost_center_code' => 2], ['dispatch_guide_detail' => 'Centro 2', 'additional_kilo_value' => 0, 'is_active' => true]);
        CostCenter::updateOrCreate(['cost_center_code' => 3], ['dispatch_guide_detail' => 'Centro 3', 'additional_kilo_value' => 0, 'is_active' => true]);
        $template = CostCenterKey::create(['tenant_id' => $tenant->id, 'provider_tax_id' => '11111111-1', 'agent_name' => 'Agente Plantilla', 'client_tax_id' => '22222222-2', 'merchant_name' => 'Plantilla', 'service_code' => 99, 'service_name' => 'Servicio Nuevo', 'key_code' => '11111111-1/22222222-2/99', 'key_text' => 'Plantilla', 'payment_status' => 'SI', 'cost_center_code' => 2, 'is_active' => true]);
        $secondTemplate = CostCenterKey::create(['tenant_id' => $tenant->id, 'provider_tax_id' => '33333333-3', 'agent_name' => 'Segundo Agente', 'client_tax_id' => '22222222-2', 'merchant_name' => 'Plantilla', 'service_code' => 99, 'service_name' => 'Servicio Nuevo', 'key_code' => '33333333-3/22222222-2/99', 'key_text' => 'Plantilla 2', 'payment_status' => 'NO', 'cost_center_code' => 3, 'is_active' => true]);
        $batchId = (string) Str::uuid();
        CourierImportError::create(['tenant_id' => $tenant->id, 'batch_id' => $batchId, 'file_name' => 'prueba.csv', 'category' => 'services', 'source_key' => 'Cliente Nuevo → Servicio Nuevo', 'source_values' => ['Cliente Nuevo', 'Servicio Nuevo'], 'affected_records' => 2, 'action' => 'Crear combinación', 'status' => 'PENDIENTE']);

        $this->withSession(['courier_review' => ['batch_id' => $batchId, 'file' => 'prueba.csv', 'records' => 2, 'groups' => [], 'missing_columns' => []]])
            ->post(route('provider-payments.maintainers.llave-centro-costos.store'), ['merchant_name' => 'Cliente Nuevo', 'service_name' => 'Servicio Nuevo', 'template_id' => $template->id, 'rows' => [
                ['source_id' => $template->id, 'provider_tax_id' => '11111111-1', 'agent_name' => 'Agente Editado', 'payment_status' => 'SI', 'cost_center_code' => 2],
                ['source_id' => $secondTemplate->id, 'provider_tax_id' => '33333333-3', 'agent_name' => 'Segundo Agente', 'payment_status' => 'NO', 'cost_center_code' => 3],
            ]])
            ->assertRedirect(route('provider-payments.courier-movements.review-parameters'));

        $this->assertDatabaseHas('llave_centro_costos', ['tenant_id' => $tenant->id, 'client_id' => $client->id, 'service_type_id' => $service->id, 'provider_tax_id' => '11111111-1', 'payment_status' => 'SI', 'cost_center_code' => 2, 'key_code' => '11111111-1/12345678-5/99']);
        $this->assertDatabaseHas('llave_centro_costos', ['client_id' => $client->id, 'agent_name' => 'Agente Editado']);
        $this->assertDatabaseHas('llave_centro_costos', ['tenant_id' => $tenant->id, 'client_id' => $client->id, 'service_type_id' => $service->id, 'provider_tax_id' => '33333333-3', 'payment_status' => 'NO', 'cost_center_code' => 3, 'key_code' => '33333333-3/12345678-5/99']);
        $this->assertSame(2, CostCenterKey::query()->where('client_id', $client->id)->where('service_type_id', $service->id)->count());
        $this->assertDatabaseHas('client_service_type', ['client_id' => $client->id, 'service_type_id' => $service->id, 'is_active' => true]);
        $this->assertDatabaseHas('courier_import_errors', ['batch_id' => $batchId, 'source_key' => 'Cliente Nuevo → Servicio Nuevo', 'status' => 'RESUELTO']);
    }

    public function test_cost_center_key_preview_shows_cost_center_codes_and_descriptions(): void
    {
        CostCenter::updateOrCreate(['cost_center_code' => 2], ['dispatch_guide_detail' => 'STANDART REGIONES', 'additional_kilo_value' => 125, 'is_active' => true]);

        $this->get(route('provider-payments.maintainers.llave-centro-costos', ['merchant' => 'Solventa', 'service' => 'Servicio Standar']))
            ->assertOk()
            ->assertSee('Centro de costo')
            ->assertSee('STANDART REGIONES');
    }

    public function test_ready_file_loads_movements_and_can_replace_duplicates(): void
    {
        Storage::fake('local');
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $client = Client::create(['tenant_id' => $tenant->id, 'tax_id' => '12345678-5', 'tax_id_number' => '12345678', 'tax_id_check_digit' => '5', 'source_merchant_name' => 'Cliente Prueba', 'commercial_name' => 'Cliente Prueba', 'legal_name' => 'Cliente Prueba SPA']);
        WeightTransformation::create(['tenant_id' => $tenant->id, 'source_weight' => '1.00 kg', 'comparison_key' => '1', 'transformed_weight' => 1, 'is_active' => true]);
        $header = "Seguimiento paquete;Peso;Estado de entrega;Comerciante;Servicio;Comuna de destino;Dirección;Nombre del destinatario\n";
        $tracking = '4N202607013618-526';
        $snapshot = fn (string $path): array => ['batch_id' => (string) Str::uuid(), 'file' => 'prueba.csv', 'stored_path' => $path, 'extension' => 'csv', 'records' => 1, 'groups' => [], 'missing_columns' => []];

        Storage::disk('local')->put('courier-imports/primero.csv', $header."{$tracking};1.00 kg;Entregado;Cliente Prueba;Servicio Standar;Comuna Mala;Calle 1;Persona Uno\n");
        $this->withSession(['courier_review' => $snapshot('courier-imports/primero.csv'), 'courier_review_corrections.coverages' => ['Comuna Mala → Calle 1' => 'Temuco']])
            ->post(route('provider-payments.courier-movements.store'), ['process_year' => 2026, 'process_month' => 7, 'process_name' => '202607-Variable'])
            ->assertOk()->assertSee('1')->assertSee('Nuevos');

        $movement = CourierMovement::query()->where('tracking_number', $tracking)->firstOrFail();
        $this->assertSame($client->id, $movement->client_id);
        $this->assertSame('2026-07-01', $movement->fecha->toDateString());
        $this->assertSame(1, $movement->peso_transformado);
        $this->assertSame('Variables', $movement->tipo_pago);
        $this->assertSame('202607-Variable', $movement->nombre_proceso);
        $this->assertSame('Persona Uno', $movement->recipient_name);
        $this->assertSame('Temuco', $movement->destination_commune_name);

        Storage::disk('local')->put('courier-imports/reemplazo.csv', $header."{$tracking};1.00 kg;Fallido;Cliente Prueba;Servicio Standar;Comuna Mala;Calle 1;Persona Dos\n");
        $this->withSession(['courier_review' => $snapshot('courier-imports/reemplazo.csv'), 'courier_review_corrections.coverages' => ['Comuna Mala → Calle 1' => 'Temuco']])
            ->post(route('provider-payments.courier-movements.store'), ['replace_duplicates' => 1, 'process_year' => 2026, 'process_month' => 8, 'process_name' => '202608-Variable'])
            ->assertOk()->assertSee('Reemplazados');

        $movement->refresh();
        $this->assertSame('Fallido', $movement->status);
        $this->assertSame('Persona Dos', $movement->recipient_name);
        $this->assertSame('202608-Variable', $movement->nombre_proceso);
        $this->assertDatabaseCount('movimientos_courier', 1);
    }

    public function test_dashboard_defaults_to_latest_period_and_filters_process_totals(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => '4N202606010001', 'merchant_name' => 'Cliente Antiguo', 'status' => 'Entregado', 'nombre_proceso' => '202606-Variable']);
        CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => '4N202607010001', 'merchant_name' => 'Cliente Nuevo', 'status' => 'Fallido', 'nombre_proceso' => '202607-Variable']);
        CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => '4N202607010002', 'merchant_name' => 'Cliente Nuevo', 'status' => 'Entregado', 'nombre_proceso' => '202607-Variable']);

        $this->get(route('provider-payments.dashboard'))
            ->assertOk()->assertSee('202607-Variable')->assertSee('Cliente Nuevo')->assertDontSee('Cliente Antiguo')
            ->assertSee('2')->assertSee('Registros cargados');

        $this->get(route('provider-payments.dashboard', ['period' => '202606']))
            ->assertOk()->assertSee('202606-Variable')->assertSee('Cliente Antiguo')->assertDontSee('Cliente Nuevo');
    }

    public function test_excluded_coverage_errors_disappear_from_pending_review_but_remain_in_error_database(): void
    {
        $snapshot = [
            'batch_id' => (string) Str::uuid(), 'file' => 'prueba.csv', 'records' => 3, 'missing_columns' => [],
            'groups' => ['coverages' => [['values' => ['#N/D'], 'count' => 3]]],
        ];
        $this->withSession(['courier_review' => $snapshot])->get(route('provider-payments.courier-movements.review-parameters'))->assertSee('#N/D');
        $this->withSession(['courier_review' => $snapshot])->post(route('provider-payments.courier-movements.exclude-coverages'), [
            'coverage_errors' => [['source_key' => '#N/D', 'exclude' => 1, 'comment' => 'Sin comuna identificable']],
        ])->assertRedirect();

        $this->get(route('provider-payments.courier-movements.review-parameters'))->assertDontSee('#N/D');
        $this->assertDatabaseHas('courier_import_errors', ['batch_id' => $snapshot['batch_id'], 'source_key' => '#N/D', 'status' => 'NO_CARGAR', 'comment' => 'Sin comuna identificable']);
    }

    public function test_coverage_can_be_corrected_using_its_address_and_an_existing_commune(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        Coverage::create(['tenant_id' => $tenant->id, 'commune_name' => 'Temuco', 'zone' => 'Regiones', 'provider_tax_id' => '1-9', 'is_active' => true]);
        $snapshot = [
            'batch_id' => (string) Str::uuid(), 'file' => 'prueba.csv', 'records' => 2, 'missing_columns' => [],
            'groups' => ['coverages' => [['values' => ['3284', 'Calle Prueba 123'], 'count' => 2]]],
        ];

        $this->withSession(['courier_review' => $snapshot])->post(route('provider-payments.courier-movements.exclude-coverages'), [
            'coverage_errors' => [['source_key' => '3284 → Calle Prueba 123', 'corrected_commune' => 'Temuco', 'comment' => 'Código reemplazado usando dirección']],
        ])->assertRedirect();

        $this->assertSame('Temuco', session('courier_review_corrections.coverages')['3284 → Calle Prueba 123']);
        $this->get(route('provider-payments.courier-movements.review-parameters'))->assertDontSee('3284');
    }

    public function test_weight_master_resolves_known_values_and_only_reports_unknown_weights(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        WeightTransformation::create(['tenant_id' => $tenant->id, 'source_weight' => '0.01 kg', 'comparison_key' => '0.01', 'transformed_weight' => 1, 'is_active' => true]);
        $input = ['weights' => [
            ['values' => ['0.01 kg'], 'count' => 4],
            ['values' => [''], 'count' => 2],
            ['values' => ['peso desconocido'], 'count' => 1],
        ]];

        $groups = (new ParameterReview)->compare($input, $tenant->id);

        $this->assertCount(1, $groups[3]['items']);
        $this->assertSame('peso desconocido', $groups[3]['items'][0]['values'][0]);
        $this->assertSame(1, $groups[3]['affected']);
    }

    public function test_missing_previous_validation_is_explained(): void
    {
        $this->get(route('provider-payments.courier-movements.review-parameters'))
            ->assertOk()->assertSee('Necesitamos validar el archivo nuevamente');
    }

    public function test_services_maintainer_lists_services_and_assigns_the_next_code(): void
    {
        ServiceType::create(['service_code' => 7, 'name' => 'Servicio Existente', 'is_active' => true]);

        $this->get(route('provider-payments.maintainers.servicios'))
            ->assertOk()->assertSee('Servicio Existente')->assertSee('Próximo ID automático')->assertSee('8');

        $this->post(route('provider-payments.maintainers.servicios.store'), ['name' => 'Servicio Nuevo'])
            ->assertRedirect(route('provider-payments.maintainers.servicios'));

        $this->assertDatabaseHas('service_types', ['service_code' => 8, 'name' => 'Servicio Nuevo', 'is_active' => true]);
    }

    public function test_cost_centers_maintainer_lists_centers_and_assigns_the_next_code(): void
    {
        CostCenter::create(['cost_center_code' => 12, 'dispatch_guide_detail' => 'Centro Existente', 'additional_kilo_value' => 500, 'is_active' => true]);

        $this->get(route('provider-payments.maintainers.centro-de-costos'))
            ->assertOk()->assertSee('Centro Existente')->assertSee('Próximo ID automático')->assertSee('13');

        $this->post(route('provider-payments.maintainers.centro-de-costos.store'), [
            'dispatch_guide_detail' => 'Centro Nuevo',
            'additional_kilo_value' => 750,
        ])->assertRedirect(route('provider-payments.maintainers.centro-de-costos'));

        $this->assertDatabaseHas('cost_centers', ['cost_center_code' => 13, 'dispatch_guide_detail' => 'Centro Nuevo', 'additional_kilo_value' => 750, 'is_active' => true]);
    }

    public function test_weight_maintainer_lists_transformations_and_adds_a_real_weight(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        WeightTransformation::create(['tenant_id' => $tenant->id, 'source_weight' => '1.25 kg', 'comparison_key' => '1.25', 'transformed_weight' => 2, 'is_active' => true]);

        $this->get(route('provider-payments.maintainers.pesos.transformados'))
            ->assertOk()->assertSee('Peso Transformado')->assertSee('Equivalencias registradas');
        $this->get(route('provider-payments.maintainers.pesos.reales'))
            ->assertOk()->assertSee('1.25 kg')->assertSee('Agregar Peso Real');

        $this->post(route('provider-payments.maintainers.pesos.reales.store'), ['source_weight' => '2.75 kg', 'transformed_weight' => 3])
            ->assertRedirect(route('provider-payments.maintainers.pesos.reales'));

        $this->assertDatabaseHas('weight_transformations', ['tenant_id' => $tenant->id, 'source_weight' => '2.75 kg', 'comparison_key' => '2.75', 'transformed_weight' => 3]);
    }

    public function test_provider_rut_remains_immutable_when_operational_data_is_updated(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::create(['tenant_id' => $tenant->id, 'tax_id' => '11111111-1', 'tax_id_number' => '11111111', 'tax_id_check_digit' => '1', 'legal_name' => 'Proveedor Inicial', 'operator_type' => 'Courier']);

        $this->put(route('provider-payments.maintainers.proveedores.update', $provider), [
            'tax_id' => '22222222-2', 'legal_name' => 'Proveedor Actualizado', 'operator_type' => 'Courier', 'is_active' => 1,
        ])->assertRedirect();

        $provider->refresh();
        $this->assertSame('11111111-1', $provider->tax_id);
        $this->assertSame('Proveedor Actualizado', $provider->legal_name);
    }

    public function test_vehicle_keys_remain_immutable_when_operational_data_is_updated(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $vehicle = Vehicle::create(['tenant_id' => $tenant->id, 'rut_empresa' => '77346078-7', 'internal_code' => 'V-001', 'plate' => 'AAAA11', 'vehicle_type' => 'Furgón', 'ownership_type' => 'Propio', 'operational_status' => 'available']);

        $this->put(route('provider-payments.maintainers.vehiculos.update', $vehicle), [
            'rut_empresa' => '1-9', 'internal_code' => 'CAMBIADO', 'plate' => 'BBBB22', 'vehicle_type' => 'Camión',
            'ownership_type' => 'Propio', 'operational_status' => 'maintenance', 'is_active' => 1,
        ])->assertRedirect();

        $vehicle->refresh();
        $this->assertSame('77346078-7', $vehicle->rut_empresa);
        $this->assertSame('V-001', $vehicle->internal_code);
        $this->assertSame('AAAA11', $vehicle->plate);
        $this->assertSame('Camión', $vehicle->vehicle_type);
    }

    public function test_operational_master_pages_render_existing_records(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        Banco::create(['id_banco' => 99, 'banco' => 'Banco Prueba', 'codigo_sbif' => 999, 'nombre_entidad_financiera' => 'Entidad Financiera de Prueba', 'marcas_productos_asociados' => 'Marca Prueba']);
        $provider = Provider::create(['tenant_id' => $tenant->id, 'tax_id' => '11111111-1', 'tax_id_number' => '11111111', 'tax_id_check_digit' => '1', 'legal_name' => 'Proveedor Visible', 'operator_type' => 'Courier']);
        ProviderBankAccount::create(['provider_id' => $provider->id, 'account_holder_name' => 'Proveedor Visible', 'account_holder_tax_id' => '11111111-1', 'bank_name' => 'Banco Prueba', 'account_type' => 'Corriente', 'account_number' => '123456789']);

        $this->get(route('provider-payments.maintainers.proveedores'))->assertOk()->assertSee('Proveedor Visible')->assertSee('Banco Prueba')->assertSee('•••• 6789')->assertDontSee('123456789');
        $this->get(route('provider-payments.maintainers.bancos'))->assertOk()->assertSee('Banco Prueba')->assertSee('Entidad Financiera de Prueba')->assertDontSee('Proveedor Visible')->assertDontSee('123456789');
        $this->get(route('provider-payments.maintainers.vehiculos'))->assertOk()->assertSee('Nuevo vehículo');
        $this->get(route('provider-payments.maintainers.estados'))->assertOk()->assertSee('El nombre permanece protegido');
        $this->get(route('provider-payments.maintainers.coberturas'))->assertOk()->assertSee('Crear cobertura');
        $this->get(route('provider-payments.maintainers.llave-centro-costos'))->assertOk()->assertSee('Nueva llave');
    }

    public function test_provider_bank_and_account_type_are_limited_to_the_master_tables(): void
    {
        $this->seed([BancoSeeder::class, TipoCuentaBancariaSeeder::class]);

        $this->assertDatabaseCount('bancos', 26);
        $this->assertDatabaseHas('bancos', ['id_banco' => 1, 'banco' => 'Banco Chile', 'codigo_sbif' => 1]);
        $this->assertDatabaseHas('bancos', ['id_banco' => 26, 'banco' => 'Lautaro', 'codigo_sbif' => 677]);
        $this->assertDatabaseCount('tipos_cuenta_bancaria', 4);
        $this->assertSame(
            ['Cuenta Corriente', 'Cuenta Vista', 'Cuenta RUT', 'Cuenta Ahorro'],
            TipoCuentaBancaria::query()->orderBy('id_tipo_cuenta')->pluck('tipo_cuenta')->all(),
        );

        $this->get(route('provider-payments.maintainers.proveedores'))
            ->assertOk()
            ->assertSee('Banco Chile · SBIF 1')
            ->assertSee('Cuenta Corriente')
            ->assertSee('Cuenta Vista')
            ->assertSee('Cuenta RUT')
            ->assertSee('Cuenta Ahorro');

        $this->post(route('provider-payments.maintainers.proveedores.store'), [
            'tax_id' => '22222222-2',
            'legal_name' => 'Proveedor con datos inválidos',
            'operator_type' => 'Courier',
            'bank_name' => 'Banco inexistente',
            'account_type' => 'Cuenta inventada',
            'account_number' => '123456',
        ])->assertSessionHasErrors(['bank_name', 'account_type']);

        $this->assertDatabaseMissing('providers', ['tax_id' => '22222222-2']);
    }
}
