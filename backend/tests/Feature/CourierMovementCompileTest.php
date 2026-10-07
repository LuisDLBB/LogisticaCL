<?php

namespace Tests\Feature;

use App\Models\Acuerdo;
use App\Models\BaseServicio;
use App\Models\Client;
use App\Models\CostCenter;
use App\Models\CostCenterKey;
use App\Models\CostCenterWeightRate;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\CourierStatus;
use App\Models\Coverage;
use App\Models\Provider;
use App\Models\RutaCv;
use App\Models\ServiceType;
use App\Models\Tenant;
use App\Modules\ProviderPayments\Services\CourierPaymentAssigner;
use App\Modules\ProviderPayments\Services\CourierSourcePartitioner;
use App\Modules\ProviderPayments\Services\PeumoRateResolver;
use Database\Seeders\CalamaProviderTransitionSeeder;
use Database\Seeders\ClaudioCuevasPaymentKeysSeeder;
use Database\Seeders\ProveedoresUsuarios4NSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\ProviderPaymentsWorkflowTestCase;

class CourierMovementCompileTest extends ProviderPaymentsWorkflowTestCase
{
    use RefreshDatabase;

    public function test_claudio_receives_only_the_four_missing_temuco_payment_rules(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $claudio = Provider::factory()->create([
            'tenant_id' => $tenant->id,
            'tax_id' => '12538127-8',
            'operational_name' => 'Claudio Cuevas (Temuco)',
        ]);
        $rules = [
            ['89807200-2', 7, 'SI', 7],
            ['89807200-2', 11, 'NO', 0],
            ['81826800-9', 31, 'SI', 8],
            ['90106000-2', 11, 'SI', 8],
        ];
        foreach ($rules as [$clientRut, $serviceCode, $status, $center]) {
            CostCenterKey::factory()->create([
                'tenant_id' => $tenant->id,
                'provider_tax_id' => '77346078-7',
                'agent_name' => '4N Temuco',
                'client_tax_id' => $clientRut,
                'service_code' => $serviceCode,
                'payment_status' => $status,
                'cost_center_code' => $center,
            ]);
        }

        $this->seed(ClaudioCuevasPaymentKeysSeeder::class);
        $this->seed(ClaudioCuevasPaymentKeysSeeder::class);

        $copied = CostCenterKey::query()->where('provider_tax_id', $claudio->tax_id)->get();
        $this->assertCount(4, $copied);
        foreach ($rules as [$clientRut, $serviceCode, $status, $center]) {
            $key = $copied->first(fn (CostCenterKey $candidate): bool => $candidate->client_tax_id === $clientRut && $candidate->service_code === $serviceCode
            );
            $this->assertNotNull($key);
            $this->assertSame($claudio->id, $key->provider_id);
            $this->assertSame($status, $key->payment_status);
            $this->assertSame($center, $key->cost_center_code);
        }
    }

    public function test_work_page_shows_loaded_processes_before_the_payment_summary(): void
    {
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        CourierMovement::factory()->create(['tenant_id' => $tenantId, 'nombre_proceso' => '202609-Variable']);
        CourierMovement::factory()->create(['tenant_id' => $tenantId, 'nombre_proceso' => '202609-Lanas']);

        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202609']))
            ->assertOk()
            ->assertSee('2 movimientos cargados')
            ->assertSee('0 pagos preparados')
            ->assertSee('Preparar procesos seleccionados')
            ->assertSee('Los movimientos cargados que aún no se han trabajado no figuran')
            ->assertSeeInOrder(['Procesos cargados del período 202609', 'Preparar procesos seleccionados', 'Resumen de pago']);
    }

    public function test_september_payment_save_keeps_victor_courier_but_corrects_other_calama_processes(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $victor = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => '13013180-8',
            'legal_name' => 'Víctor Robledo', 'operational_name' => 'Victor Robledo (Calama)']);
        $marcelo = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => '13172671-6',
            'legal_name' => 'Marcelo Avendaño', 'operational_name' => 'Marcelo Avendaño (Calama)',
            'tax_document_type' => 'Factura']);

        $variableMovement = CourierMovement::query()->create(['tenant_id' => $tenant->id,
            'tracking_number' => 'VAR-20260901-0001', 'nombre_proceso' => '202609-Variable']);
        $specialMovement = CourierMovement::query()->create(['tenant_id' => $tenant->id,
            'tracking_number' => 'ESP-20260901-0001', 'nombre_proceso' => '202609-Especiales']);
        $variable = CourierPaymentMovement::query()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202609', 'tipo_pago' => 'Variable',
            'nombre_proceso' => 'Variable', 'courier_movement_id' => $variableMovement->id, 'peso_final' => 1,
            'seguimiento_paquete' => 'VAR-20260901-0001', 'comuna_destino' => 'Calama',
            'nombre_repartidor' => 'Victor Robledo', 'provider_id' => $victor->id,
            'rut_proveedor' => $victor->tax_id,
        ]);
        $special = CourierPaymentMovement::query()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202609', 'tipo_pago' => 'Especiales',
            'nombre_proceso' => 'Especiales', 'courier_movement_id' => $specialMovement->id, 'peso_final' => 1,
            'seguimiento_paquete' => 'ESP-20260901-0001', 'comuna_destino' => 'Calama',
            'provider_id' => $victor->id, 'rut_proveedor' => $victor->tax_id,
        ]);

        $this->assertSame($victor->tax_id, $variable->fresh()->rut_proveedor);
        $this->assertSame($marcelo->id, $special->fresh()->provider_id);
        $this->assertSame($marcelo->tax_id, $special->fresh()->rut_proveedor);
        $this->assertSame($marcelo->tax_document_type, $special->fresh()->tipo_documento);
    }

    public function test_compilation_assigns_maribel_from_the_courier_user_mapping_without_changing_other_ds_group_deliveries(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $dsGroup = Provider::create([
            'tenant_id' => $tenant->id, 'tax_id' => '77201525-9', 'tax_id_number' => '77201525',
            'tax_id_check_digit' => '9', 'legal_name' => 'DS GROUP SPA', 'operator_type' => 'Courier',
        ]);
        $maribel = Provider::create([
            'tenant_id' => $tenant->id, 'tax_id' => '15743735-6', 'tax_id_number' => '15743735',
            'tax_id_check_digit' => '6', 'legal_name' => 'Maribel Elena Silva Donoso',
            'operational_name' => 'Maribel Silva (Courier Stgo)', 'operator_type' => 'Courier',
            'tax_document_type' => 'Factura',
        ]);
        Coverage::create([
            'tenant_id' => $tenant->id, 'commune_name' => 'Santiago', 'matrix_commune_name' => '4N RM',
            'provider_id' => $dsGroup->id, 'provider_tax_id' => $dsGroup->tax_id,
            'provider_name_source' => $dsGroup->legal_name, 'zone' => 'RM', 'is_active' => true,
        ]);
        $this->seed(ProveedoresUsuarios4NSeeder::class);

        foreach (['Maribel Silva Donoso', 'Maribel Elena Silva Donoso', 'Otro repartidor'] as $index => $courier) {
            CourierMovement::create([
                'tenant_id' => $tenant->id, 'tracking_number' => '4N202609010001-00'.$index,
                'nombre_proceso' => '202609-Variable', 'tipo_pago' => 'Variable',
                'destination_commune_name' => 'Santiago', 'courier_name' => $courier,
            ]);
        }

        $this->post(route('provider-payments.courier-movements.compile.store'), [
            'period' => '202609', 'processes' => ['Variable'],
        ])->assertRedirect();

        foreach (['Maribel Silva Donoso', 'Maribel Elena Silva Donoso'] as $courier) {
            $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', [
                'periodo' => '202609', 'nombre_repartidor' => $courier,
                'rut_proveedor' => $maribel->tax_id, 'razon_social_proveedor' => $maribel->legal_name,
                'nombre_operacional' => $maribel->operational_name, 'tipo_documento' => 'Factura',
            ]);
        }
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', [
            'periodo' => '202609', 'nombre_repartidor' => 'Otro repartidor', 'rut_proveedor' => $dsGroup->tax_id,
        ]);
        $this->assertDatabaseCount('PPR_Maestro_Pagos', 0);
    }

    public function test_compilation_assigns_claudio_from_the_4n_temuco_courier_mapping(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $internal = Provider::create([
            'tenant_id' => $tenant->id, 'tax_id' => '77346078-7', 'tax_id_number' => '77346078',
            'tax_id_check_digit' => '7', 'legal_name' => '4 Nortes Logistica SPA', 'operator_type' => 'Courier',
        ]);
        $claudio = Provider::create([
            'tenant_id' => $tenant->id, 'tax_id' => '12538127-8', 'tax_id_number' => '12538127',
            'tax_id_check_digit' => '8', 'legal_name' => 'Claudio Andres Cuevas Aravena',
            'operational_name' => 'Claudio Cuevas (Temuco)', 'operator_type' => 'Courier',
            'tax_document_type' => 'Boleta de Honorarios',
        ]);
        Coverage::create([
            'tenant_id' => $tenant->id, 'commune_name' => 'Temuco', 'matrix_commune_name' => '4N Temuco',
            'provider_id' => $internal->id, 'provider_tax_id' => $internal->tax_id,
            'provider_name_source' => $internal->legal_name, 'zone' => 'Regiones', 'is_active' => true,
        ]);
        DB::table('PPR_Proveedores_usuarios_4N')->insert([
            'RutProveedor' => $internal->tax_id, 'ComunaMatriz' => '4N Temuco',
            'NombreRepartidor' => 'Claudio Andres Cuevas Aravena', 'NuevoRutProveedor' => $claudio->tax_id,
        ]);
        foreach (['Claudio Andres Cuevas Aravena', 'Otro repartidor'] as $index => $courier) {
            CourierMovement::create([
                'tenant_id' => $tenant->id, 'tracking_number' => '4N202609010201-00'.$index,
                'nombre_proceso' => '202609-Variable', 'tipo_pago' => 'Variable',
                'destination_commune_name' => 'Temuco', 'courier_name' => $courier,
            ]);
        }

        $this->post(route('provider-payments.courier-movements.compile.store'), [
            'period' => '202609', 'processes' => ['Variable'],
        ])->assertRedirect();

        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', [
            'periodo' => '202609', 'nombre_repartidor' => 'Claudio Andres Cuevas Aravena',
            'rut_proveedor' => $claudio->tax_id, 'razon_social_proveedor' => $claudio->legal_name,
            'tipo_documento' => $claudio->tax_document_type,
        ]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', [
            'periodo' => '202609', 'nombre_repartidor' => 'Otro repartidor', 'rut_proveedor' => $internal->tax_id,
        ]);
    }

    public function test_calama_transition_keeps_victor_deliveries_and_assigns_other_september_movements_to_marcelo(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $victor = Provider::create([
            'tenant_id' => $tenant->id, 'tax_id' => '13013180-8', 'tax_id_number' => '13013180',
            'tax_id_check_digit' => '8', 'legal_name' => 'Víctor Enrique Robledo Escalona',
            'operational_name' => 'Victor Robledo (Calama)', 'operator_type' => 'Regiones', 'tax_document_type' => 'Factura',
        ]);
        $marcelo = Provider::create([
            'tenant_id' => $tenant->id, 'tax_id' => '13172671-6', 'tax_id_number' => '13172671',
            'tax_id_check_digit' => '6', 'legal_name' => 'Marcelo Alejandro Avendaño Tapia',
            'operational_name' => 'Marcelo Avendaño (Calama)', 'operator_type' => 'Regiones', 'tax_document_type' => 'Factura',
        ]);
        foreach (['Calama', 'San Pedro De Atacama', 'Sierra Gorda', 'Tocopilla'] as $commune) {
            Coverage::create([
                'tenant_id' => $tenant->id, 'commune_name' => $commune,
                'matrix_commune_name' => $marcelo->operational_name,
                'provider_id' => $marcelo->id, 'provider_tax_id' => $marcelo->tax_id,
                'provider_name_source' => $marcelo->operational_name, 'zone' => 'Regiones', 'is_active' => true,
            ]);
        }
        foreach ([
            ['Variable', 'Calama', 'Victor Robledo', $victor],
            ['Lanas', 'San Pedro De Atacama', 'Víctor Robledo', $victor],
            ['Retornos', 'Sierra Gorda', 'Victor Robledo', $victor],
            ['Variable', 'Tocopilla', 'Marcelo Avendaño', $marcelo],
            ['Retornos', 'Calama', null, $marcelo],
        ] as $index => [$process, $commune, $courier, $expectedProvider]) {
            CourierMovement::create([
                'tenant_id' => $tenant->id, 'tracking_number' => '4N202609020001-00'.$index,
                'nombre_proceso' => '202609-'.$process, 'tipo_pago' => $process,
                'destination_commune_name' => $commune, 'courier_name' => $courier,
            ]);
        }

        $this->post(route('provider-payments.courier-movements.compile.store'), [
            'period' => '202609', 'processes' => ['Variable', 'Lanas', 'Retornos'],
        ])->assertRedirect();

        foreach ([$victor, $victor, $victor, $marcelo, $marcelo] as $index => $provider) {
            $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', [
                'seguimiento_paquete' => '4N202609020001-00'.$index,
                'rut_proveedor' => $provider->tax_id,
                'razon_social_proveedor' => $provider->legal_name,
                'comuna_matriz' => $provider->operational_name,
                'tipo_documento' => 'Factura',
            ]);
        }
    }

    public function test_calama_transition_seeder_preserves_victor_rules_and_creates_marcelo_rules(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        Provider::create([
            'tenant_id' => $tenant->id, 'tax_id' => '13013180-8', 'tax_id_number' => '13013180',
            'tax_id_check_digit' => '8', 'legal_name' => 'Víctor Enrique Robledo Escalona',
            'operational_name' => 'Victor Robledo (Calama)', 'operator_type' => 'Regiones',
        ]);
        Coverage::create([
            'tenant_id' => $tenant->id, 'commune_name' => 'Calama', 'matrix_commune_name' => 'Victor Robledo (Calama)',
            'provider_tax_id' => '13013180-8', 'zone' => 'Regiones', 'is_active' => true,
        ]);

        $this->seed(CalamaProviderTransitionSeeder::class);
        $this->seed(CalamaProviderTransitionSeeder::class);

        $this->assertDatabaseHas('PPR_coverages', [
            'commune_name' => 'Calama', 'provider_tax_id' => '13172671-6',
            'matrix_commune_name' => 'Marcelo Avendaño (Calama)',
        ]);
        $this->assertSame(88, DB::table('PPR_llave_centro_costos')->where('provider_tax_id', '13013180-8')->count());
        $this->assertSame(88, DB::table('PPR_llave_centro_costos')->where('provider_tax_id', '13172671-6')->count());
        $this->assertDatabaseCount('PPR_Maestro_Pagos', 0);
    }

    public function test_peumo_is_separated_and_its_rate_is_assigned_to_each_package_by_dispatch_guide(): void
    {
        $partitioner = app(CourierSourcePartitioner::class);
        $this->assertSame('peumo', $partitioner->classify('Comercial Peumo Ltda', 'Cliente', 'Servicio Standar   (V. Trabajadores)'));
        $this->assertSame('variables', $partitioner->classify('Comercial Peumo Ltda', 'Cliente', 'Otro servicio'));

        $rates = app(PeumoRateResolver::class);
        $this->assertSame(['status' => 'ok', 'first' => 2000, 'rest' => 1200, 'locality' => 'Los Angeles'], $rates->resolve('Los Ángeles'));
        $this->assertSame(2000, $rates->resolve('Los ﾁngeles')['first']);
        $this->assertSame('ambiguous', $rates->resolve('María Pinto')['status']);
        $this->assertSame('missing', $rates->resolve('Futrono')['status']);

        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        foreach ([
            ['PEU-1', 'Santiago'], ['PEU-1', 'Santiago'], ['PEU-1', 'Santiago'],
            ['PEU-2', 'Peumo'], ['PEU-3', 'María Pinto'],
        ] as $index => [$guide, $commune]) {
            $tracking = '4N20261101000'.($index + 1);
            $movement = CourierMovement::create([
                'tenant_id' => $tenant->id, 'tracking_number' => $tracking,
                'nombre_proceso' => '202611-Peumo', 'tipo_pago' => 'Peumo',
                'merchant_name' => 'Comercial Peumo Ltda', 'service_name' => 'Servicio Standar (V. Trabajadores)',
                'dispatch_guide' => $guide, 'destination_commune_name' => $commune,
            ]);
            CourierPaymentMovement::create([
                'tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id,
                'periodo' => '202611', 'nombre_proceso' => 'Peumo', 'tipo_pago' => 'Peumo',
                'seguimiento_paquete' => $tracking, 'comuna_destino' => $commune,
                'peso_final' => 1,
                'rut_cliente' => '85037900-9', 'rut_proveedor' => '11111111-1',
            ]);
        }

        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202611'])
            ->assertRedirect()->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'Peumo: 4 bultos con tarifa, 1 pendientes'));
        foreach ([1500, 500, 500, 2250, null] as $index => $value) {
            $payment = CourierPaymentMovement::query()->where('seguimiento_paquete', '4N20261101000'.($index + 1))->firstOrFail();
            $this->assertSame($value, $payment->valor);
            $this->assertSame($value === null ? null : 'SI', $payment->condicion_pago);
        }
    }

    public function test_compiling_peumo_saves_the_first_and_remaining_package_amounts_in_valor(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $client = Client::firstOrCreate(['tenant_id' => $tenant->id, 'tax_id' => '85037900-9'], [
            'tax_id_number' => '85037900', 'tax_id_check_digit' => '9',
            'source_merchant_name' => 'Comercial Peumo Ltda', 'commercial_name' => 'Peumo',
            'legal_name' => 'Comercial Peumo Limitada',
        ]);
        $provider = Provider::create([
            'tenant_id' => $tenant->id, 'tax_id' => '11111111-1', 'tax_id_number' => '11111111',
            'tax_id_check_digit' => '1', 'legal_name' => 'Proveedor Peumo', 'operator_type' => 'Courier',
        ]);
        Coverage::create([
            'tenant_id' => $tenant->id, 'commune_name' => 'Peumo', 'matrix_commune_name' => 'Peumo',
            'provider_id' => $provider->id, 'provider_tax_id' => $provider->tax_id,
            'provider_name_source' => $provider->legal_name, 'zone' => 'Regiones', 'is_active' => true,
        ]);
        foreach ([1, 2] as $index) {
            CourierMovement::create([
                'tenant_id' => $tenant->id, 'client_id' => $client->id,
                'tracking_number' => '4N20261102000'.$index,
                'nombre_proceso' => '202611-Peumo', 'tipo_pago' => 'Peumo',
                'merchant_name' => 'Comercial Peumo Ltda',
                'service_name' => 'Servicio Standar (V. Trabajadores)',
                'dispatch_guide' => 'PEU-456', 'destination_commune_name' => 'Peumo',
            ]);
        }

        $this->post(route('provider-payments.courier-movements.compile.store'), [
            'period' => '202611', 'processes' => ['Peumo'],
        ])->assertRedirect()->assertSessionHas('status', fn (string $status): bool => str_contains($status, '2 bultos con Valor asignado'));

        $this->assertSame([2250, 1500], CourierPaymentMovement::query()->where('periodo', '202611')
            ->where('nombre_proceso', 'Peumo')->orderBy('id')->pluck('valor')->all());
    }

    public function test_process_list_displays_the_saved_name_without_repeating_the_period(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $movement = CourierMovement::create([
            'tenant_id' => $tenant->id,
            'tracking_number' => '4N202608010001-000',
            'nombre_proceso' => '202608-Retornos',
            'tipo_pago' => 'Retornos',
        ]);
        CourierPaymentMovement::create([
            'tenant_id' => $tenant->id,
            'courier_movement_id' => $movement->id,
            'periodo' => '202608',
            'nombre_proceso' => '202608-Retornos',
            'tipo_pago' => 'Retornos',
            'peso_final' => 1,
        ]);

        $this->get(route('provider-payments.courier-movements.compile', ['period' => '202608']))
            ->assertOk()
            ->assertSee('202608-Retornos')
            ->assertDontSee('202608-202608-Retornos');
    }

    public function test_export_consolidates_only_payable_movements_from_the_selected_period(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        foreach ([
            ['202608', '202608-Acuerdos', 'SI', 100, 'RM'],
            ['202608', '202608-Acuerdos', 'SI', 250, 'RM'],
            ['202608', '202608-Acuerdos', 'SI', 75, 'Regiones'],
            ['202608', '202608-Apoyo', 'SI', 40, 'RM'],
            ['202608', '202608-Acuerdos', 'NO', 900, 'RM'],
            ['202607', '202607-Acuerdos', 'SI', 500, 'RM'],
        ] as $index => [$period, $process, $condition, $amount, $zone]) {
            $movement = CourierMovement::create([
                'tenant_id' => $tenant->id,
                'tracking_number' => 'EXPORT-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'nombre_proceso' => $process,
                'tipo_pago' => 'Acuerdos',
            ]);
            CourierPaymentMovement::create([
                'tenant_id' => $tenant->id,
                'courier_movement_id' => $movement->id,
                'periodo' => $period,
                'nombre_proceso' => $process,
                'tipo_pago' => 'Acuerdos',
                'peso_final' => 1,
                'zona' => $zone,
                'razon_social_proveedor' => 'Proveedor Uno SpA',
                'nombre_operacional' => 'Proveedor Uno',
                'rut_proveedor' => '01234567-8',
                'tipo_documento' => 'Factura',
                'empresa_mandante' => '4N',
                'condicion_pago' => $condition,
                'valor' => $amount,
            ]);
        }

        $this->get(route('provider-payments.courier-movements.compile', ['period' => '202608']))
            ->assertOk()
            ->assertSee('Exportar consolidado Excel');
        $response = $this->get(route('provider-payments.courier-movements.compile.export', ['period' => '202608']))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'courier-export-');

        try {
            file_put_contents($path, $response->streamedContent());
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $this->assertSame('Razón social proveedor', $sheet->getCell('A4')->getValue());
            $this->assertSame('Proveedor Uno SpA', $sheet->getCell('A5')->getValue());
            $this->assertSame('Proveedor Uno', $sheet->getCell('B5')->getValue());
            $this->assertSame('01234567-8', $sheet->getCell('C5')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C5')->getDataType());
            $this->assertSame('Zona', $sheet->getCell('D4')->getValue());
            $this->assertSame('RM', $sheet->getCell('D5')->getValue());
            $this->assertSame('Acuerdos', $sheet->getCell('E5')->getValue());
            $this->assertSame('202608', $sheet->getCell('F5')->getValue());
            $this->assertSame(350, $sheet->getCell('G5')->getValue());
            $this->assertSame('Factura', $sheet->getCell('H5')->getValue());
            $this->assertSame('4N', $sheet->getCell('I5')->getValue());
            $this->assertSame('Regiones', $sheet->getCell('D6')->getValue());
            $this->assertSame('Acuerdos', $sheet->getCell('E6')->getValue());
            $this->assertSame(75, $sheet->getCell('G6')->getValue());
            $this->assertSame('Apoyo', $sheet->getCell('E7')->getValue());
            $this->assertSame(40, $sheet->getCell('G7')->getValue());
            $this->assertSame('TOTAL', $sheet->getCell('A8')->getValue());
            $this->assertSame(465, $sheet->getCell('G8')->getValue());
            $spreadsheet->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_ds_group_payments_use_rm_even_when_the_loaded_coverage_says_regiones(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '77201525-9',
            'tax_id_number' => '77201525', 'tax_id_check_digit' => '9',
        ]);
        $coverage = Coverage::factory()->create([
            'tenant_id' => $tenant->id, 'provider_id' => $provider->id,
            'provider_tax_id' => $provider->tax_id, 'commune_name' => 'Curacaví',
            'matrix_commune_name' => 'Operador Curacavi', 'zone' => 'Regiones', 'is_active' => true,
        ]);
        $this->assertSame('RM', $coverage->fresh()->zone);

        DB::table('PPR_coverages')->where('id', $coverage->id)->update(['zone' => 'Regiones']);
        $movement = CourierMovement::query()->create([
            'tenant_id' => $tenant->id, 'tracking_number' => '4N202608010001-999',
            'nombre_proceso' => '202608-Variable', 'tipo_pago' => 'Variable',
            'destination_commune_name' => 'Curacaví',
        ]);

        $this->post(route('provider-payments.courier-movements.compile.store'), [
            'period' => '202608', 'processes' => ['Variable'],
        ])->assertRedirect();

        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', [
            'courier_movement_id' => $movement->id, 'rut_proveedor' => $provider->tax_id,
            'comuna_matriz' => 'Operador Curacavi', 'zona' => 'RM',
        ]);
        $payment = CourierPaymentMovement::query()->where('courier_movement_id', $movement->id)->firstOrFail();
        $payment->update(['zona' => 'Regiones']);
        $this->assertSame('RM', $payment->fresh()->zona);

        $route = RutaCv::factory()->create([
            'tenant_id' => $tenant->id, 'provider_id' => $provider->id,
            'rut_proveedor' => $provider->tax_id, 'zona' => 'Regiones',
        ]);
        $service = BaseServicio::factory()->create([
            'tenant_id' => $tenant->id, 'provider_id' => $provider->id,
            'rut_proveedor' => $provider->tax_id, 'zona' => 'Regiones',
        ]);
        $agreement = Acuerdo::query()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608',
            'nombre_proceso' => '202608-Acuerdos', 'proveedor_origen' => 'DS GROUP',
            'rut_proveedor_origen' => '77.201.525-9', 'agencia' => 'Curacaví',
            'servicio' => 'Fijo Mensual', 'costo' => 1000, 'zona' => 'Regiones',
        ]);
        $this->assertSame('RM', $route->fresh()->zona);
        $this->assertSame('RM', $service->fresh()->zona);
        $this->assertSame('RM', $agreement->fresh()->zona);
    }

    public function test_retorno_payments_store_process_name_without_repeating_the_period(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $movement = CourierMovement::query()->create([
            'tenant_id' => $tenant->id,
            'tracking_number' => '4N202608010001-000',
            'nombre_proceso' => '202608-Retornos',
            'tipo_pago' => 'Retornos',
            'destination_commune_name' => 'Santiago',
        ]);

        $this->post(route('provider-payments.courier-movements.compile.store'), [
            'period' => '202608', 'processes' => ['Retornos'],
        ])->assertRedirect();
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', [
            'courier_movement_id' => $movement->id,
            'tipo_pago' => 'Retornos',
            'nombre_proceso' => 'Retornos',
            'periodo' => '202608',
        ]);
    }

    public function test_external_delivery_is_always_no_even_with_a_payable_key(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $client = Client::create(['tenant_id' => $tenant->id, 'tax_id' => '22222222-2', 'tax_id_number' => '22222222', 'tax_id_check_digit' => '2', 'source_merchant_name' => 'Cliente externo', 'commercial_name' => 'Cliente externo', 'legal_name' => 'Cliente externo']);
        $service = ServiceType::factory()->create(['service_code' => 77, 'name' => 'Servicio externo']);
        Coverage::create(['tenant_id' => $tenant->id, 'commune_name' => 'Chile Chico', 'matrix_commune_name' => 'Envio externo',
            'provider_tax_id' => '0-0', 'provider_name_source' => 'Envio externo', 'zone' => 'Regiones']);
        CostCenter::updateOrCreate(['cost_center_code' => 98], ['dispatch_guide_detail' => 'Envio externo', 'additional_kilo_value' => 0, 'is_active' => true]);
        CostCenterWeightRate::updateOrCreate(['cost_center_code' => 98, 'final_weight' => 1], ['value' => 100, 'is_active' => true]);
        CostCenterKey::create(['tenant_id' => $tenant->id, 'provider_tax_id' => '0-0', 'client_id' => $client->id,
            'client_tax_id' => $client->tax_id, 'merchant_name' => $client->source_merchant_name,
            'service_type_id' => $service->id, 'service_code' => $service->service_code, 'service_name' => $service->name,
            'agent_name' => 'Envio externo', 'payment_status' => 'SI', 'cost_center_code' => 98, 'is_active' => true]);
        $movement = CourierMovement::create(['tenant_id' => $tenant->id, 'client_id' => $client->id,
            'tracking_number' => '4N202607030001-111', 'nombre_proceso' => '202607-Variable',
            'destination_commune_name' => 'Chile Chico', 'service_name' => $service->name, 'peso_real' => 1,
            'courier_name' => 'Hugo Lopez']);

        $this->post(route('provider-payments.courier-movements.compile.store'), ['period' => '202607', 'processes' => ['Variable']])->assertRedirect();
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['courier_movement_id' => $movement->id,
            'rut_proveedor' => '0-0', 'razon_social_proveedor' => 'Envio externo', 'condicion_pago' => 'NO', 'valor' => null]);

        DB::table('PPR_Pago_Movimientos_Courier')->where('courier_movement_id', $movement->id)
            ->update(['condicion_pago' => 'SI', 'valor' => 100]);
        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202607'])->assertRedirect();
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['courier_movement_id' => $movement->id,
            'rut_proveedor' => '0-0', 'razon_social_proveedor' => 'Envio externo', 'condicion_pago' => 'NO', 'valor' => null]);

        DB::table('PPR_Pago_Movimientos_Courier')->where('courier_movement_id', $movement->id)
            ->update(['condicion_pago' => 'SI', 'valor' => 100]);
        $this->post(route('provider-payments.courier-movements.compile.store'), ['period' => '202607', 'processes' => ['Variable']])->assertRedirect();
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['courier_movement_id' => $movement->id,
            'condicion_pago' => 'NO', 'valor' => null]);
    }

    public function test_assign_payments_uses_the_key_for_each_postman_cargo_matrix(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::create(['tenant_id' => $tenant->id, 'tax_id' => '76556632-0', 'tax_id_number' => '76556632', 'tax_id_check_digit' => '0', 'legal_name' => 'Postman Cargo', 'operator_type' => 'Courier']);
        $client = Client::create(['tenant_id' => $tenant->id, 'tax_id' => '22222222-2', 'tax_id_number' => '22222222', 'tax_id_check_digit' => '2', 'source_merchant_name' => 'Cliente', 'commercial_name' => 'Cliente', 'legal_name' => 'Cliente']);
        $service = ServiceType::factory()->create(['service_code' => 77, 'name' => 'Material Publicitario']);

        foreach ([['Postman Cargo (Iquique)', 10, 100], ['Postman Cargo (Alto Hospicio)', 13, 200]] as [$matrix, $center, $value]) {
            CostCenter::updateOrCreate(['cost_center_code' => $center], ['dispatch_guide_detail' => $matrix, 'additional_kilo_value' => 0, 'is_active' => true]);
            CostCenterWeightRate::updateOrCreate(['cost_center_code' => $center, 'final_weight' => 1], ['value' => $value, 'is_active' => true]);
            CostCenterKey::create(['tenant_id' => $tenant->id, 'provider_id' => $provider->id, 'provider_tax_id' => $provider->tax_id,
                'client_id' => $client->id, 'client_tax_id' => $client->tax_id, 'merchant_name' => $client->source_merchant_name,
                'service_type_id' => $service->id, 'service_code' => $service->service_code, 'service_name' => $service->name,
                'agent_name' => $matrix, 'payment_status' => 'SI', 'cost_center_code' => $center, 'is_active' => true]);
            $movement = CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => '4N20260703000'.$center.'-111',
                'nombre_proceso' => '202607-Variable', 'service_name' => $service->name, 'peso_real' => 1]);
            CourierPaymentMovement::create(['tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id, 'periodo' => '202607',
                'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variable', 'comuna_matriz' => $matrix, 'peso_final' => 1,
                'rut_proveedor' => $provider->tax_id, 'rut_cliente' => $client->tax_id]);
        }

        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202607'])->assertRedirect();
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['comuna_matriz' => 'Postman Cargo (Iquique)', 'condicion_pago' => 'SI', 'valor' => 100]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['comuna_matriz' => 'Postman Cargo (Alto Hospicio)', 'condicion_pago' => 'SI', 'valor' => 200]);
    }

    public function test_assign_payments_defaults_missing_lanas_weight_to_one_before_looking_up_minimum_rate(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::create(['tenant_id' => $tenant->id, 'tax_id' => '11111111-1', 'tax_id_number' => '11111111', 'tax_id_check_digit' => '1', 'legal_name' => 'Proveedor Lanas', 'operator_type' => 'Courier']);
        $client = Client::create(['tenant_id' => $tenant->id, 'tax_id' => '22222222-2', 'tax_id_number' => '22222222', 'tax_id_check_digit' => '2', 'source_merchant_name' => 'Cliente Lanas', 'commercial_name' => 'Cliente Lanas', 'legal_name' => 'Cliente Lanas']);
        $service = ServiceType::factory()->create(['service_code' => 77, 'name' => 'Servicio Lanas']);
        CostCenter::updateOrCreate(['cost_center_code' => 98], ['dispatch_guide_detail' => 'Tarifa Lanas', 'additional_kilo_value' => 50, 'is_active' => true]);
        CostCenterWeightRate::updateOrCreate(['cost_center_code' => 98, 'final_weight' => 1], ['value' => 100, 'is_active' => true]);
        CostCenterKey::create(['tenant_id' => $tenant->id, 'provider_id' => $provider->id, 'provider_tax_id' => $provider->tax_id,
            'client_id' => $client->id, 'client_tax_id' => $client->tax_id, 'merchant_name' => $client->source_merchant_name,
            'service_type_id' => $service->id, 'service_code' => 77, 'service_name' => $service->name,
            'payment_status' => 'SI', 'cost_center_code' => 98, 'is_active' => true]);
        foreach ([['lana_pendiente', 'Lanas', null], ['lana_no', 'Lanas', 'NO'], ['variable', 'Variable', null]] as $index => [$tracking, $process, $condition]) {
            $movement = CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => '4N20260703000'.$index.'-111', 'nombre_proceso' => '202607-'.$process, 'service_name' => $service->name]);
            CourierPaymentMovement::create(['tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id, 'periodo' => '202607',
                'nombre_proceso' => $process, 'tipo_pago' => $process, 'seguimiento_paquete' => $tracking,
                'peso_final' => 0, 'rut_proveedor' => $provider->tax_id, 'rut_cliente' => $client->tax_id,
                'condicion_pago' => $condition]);
        }

        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202607'])
            ->assertRedirect()->assertSessionHas('status', fn (string $status): bool => str_contains($status, '2 Lanas sin peso ajustadas a 1 kg'));
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => 'lana_pendiente', 'peso_final' => 1, 'condicion_pago' => 'SI', 'valor' => 100]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => 'lana_no', 'peso_final' => 1, 'condicion_pago' => 'NO', 'valor' => null]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => 'variable', 'peso_final' => 1, 'condicion_pago' => 'SI', 'valor' => 100]);
        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202607'])
            ->assertRedirect()->assertSessionHas('status', fn (string $status): bool => str_contains($status, '0 Lanas sin peso ajustadas a 1 kg'));
    }

    public function test_assign_payments_can_recalculate_only_one_provider_without_changing_others(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $client = Client::create([
            'tenant_id' => $tenant->id, 'tax_id' => '22222222-2', 'tax_id_number' => '22222222',
            'tax_id_check_digit' => '2', 'source_merchant_name' => 'Cliente',
            'commercial_name' => 'Cliente', 'legal_name' => 'Cliente',
        ]);
        $service = ServiceType::factory()->create(['service_code' => 88, 'name' => 'Servicio Prueba']);
        CostCenter::updateOrCreate(['cost_center_code' => 98], [
            'dispatch_guide_detail' => 'Tarifa prueba', 'additional_kilo_value' => 0, 'is_active' => true,
        ]);
        CostCenterWeightRate::updateOrCreate(['cost_center_code' => 98, 'final_weight' => 1], [
            'value' => 900, 'is_active' => true,
        ]);
        foreach (['12538127-8', '11111111-1'] as $index => $rut) {
            $provider = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => $rut]);
            CostCenterKey::create([
                'tenant_id' => $tenant->id, 'provider_id' => $provider->id,
                'provider_tax_id' => $provider->tax_id, 'client_id' => $client->id,
                'client_tax_id' => $client->tax_id, 'merchant_name' => 'Cliente',
                'service_type_id' => $service->id, 'service_code' => 88,
                'service_name' => $service->name, 'payment_status' => 'SI',
                'cost_center_code' => 98, 'is_active' => true,
            ]);
            $movement = CourierMovement::create([
                'tenant_id' => $tenant->id, 'tracking_number' => '4N202609010301-00'.$index,
                'nombre_proceso' => '202609-Variable', 'service_name' => $service->name,
                'peso_real' => 1,
            ]);
            CourierPaymentMovement::create([
                'tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id,
                'periodo' => '202609', 'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variable',
                'seguimiento_paquete' => $movement->tracking_number, 'peso_final' => 1,
                'rut_proveedor' => $rut, 'rut_cliente' => $client->tax_id,
            ]);
        }

        app(CourierPaymentAssigner::class)->assign($tenant->id, '202609', '12538127-8');

        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', [
            'rut_proveedor' => '12538127-8', 'condicion_pago' => 'SI', 'valor' => 900,
        ]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', [
            'rut_proveedor' => '11111111-1', 'condicion_pago' => null, 'valor' => null,
        ]);
    }

    public function test_assign_payments_uses_coverage_fixed_return_value_and_payment_condition(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::create(['tenant_id' => $tenant->id, 'tax_id' => '11111111-1', 'tax_id_number' => '11111111', 'tax_id_check_digit' => '1', 'legal_name' => 'Proveedor Retornos', 'operator_type' => 'Courier']);
        Coverage::create(['tenant_id' => $tenant->id, 'provider_id' => $provider->id, 'commune_name' => 'Viña del Mar', 'zone' => 'Regiones', 'return_payment_applies' => true, 'return_value' => 1294]);
        Coverage::create(['tenant_id' => $tenant->id, 'provider_id' => $provider->id, 'commune_name' => 'Quilpué', 'zone' => 'Regiones', 'return_payment_applies' => false, 'return_value' => 1000]);
        Coverage::create(['tenant_id' => $tenant->id, 'commune_name' => 'Valparaíso', 'zone' => 'Regiones', 'return_payment_applies' => true, 'return_value' => 800]);
        Coverage::create(['tenant_id' => $tenant->id, 'commune_name' => 'Concón', 'zone' => 'Regiones', 'return_payment_applies' => true, 'return_value' => 500]);
        Coverage::create(['tenant_id' => $tenant->id, 'commune_name' => 'Concón', 'zone' => 'Regiones', 'return_payment_applies' => true, 'return_value' => 700]);
        foreach ([
            ['liviano', 'Vina del Mar', 1, null],
            ['pesado', 'Viña del Mar', 30, null],
            ['no', 'Quilpué', 1, null],
            ['protegido', 'Viña del Mar', 1, 'NO'],
            ['sin_proveedor', 'Valparaíso', 1, null],
            ['ambiguo', 'Concón', 1, null],
            ['sin_cobertura', 'La Serena', 1, null],
        ] as $index => [$tracking, $commune, $weight, $condition]) {
            $movement = CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => '4N20260702000'.$index.'-111', 'nombre_proceso' => '202607-Retornos', 'peso_real' => $weight]);
            CourierPaymentMovement::create(['tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id, 'periodo' => '202607',
                'nombre_proceso' => 'Retornos', 'tipo_pago' => 'Retornos', 'seguimiento_paquete' => $tracking,
                'comuna_destino' => $commune, 'fecha' => '2026-07-02', 'peso_final' => $weight,
                'rut_proveedor' => $provider->tax_id, 'condicion_pago' => $condition]);
        }

        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202607'])
            ->assertRedirect()->assertSessionHas('status');
        foreach (['liviano', 'pesado'] as $tracking) {
            $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => $tracking, 'condicion_pago' => 'SI', 'valor' => 1294]);
        }
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => 'no', 'condicion_pago' => 'NO', 'valor' => null]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => 'protegido', 'condicion_pago' => 'NO', 'valor' => null]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => 'sin_proveedor', 'condicion_pago' => 'SI', 'valor' => 800]);
        foreach (['ambiguo', 'sin_cobertura'] as $tracking) {
            $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => $tracking, 'condicion_pago' => null, 'valor' => null]);
        }
        Coverage::query()->where('commune_name', 'Viña del Mar')->update(['return_value' => 1500]);
        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202607'])->assertRedirect();
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => 'pesado', 'condicion_pago' => 'SI', 'valor' => 1500]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => 'protegido', 'condicion_pago' => 'NO', 'valor' => null]);
        $this->assertDatabaseCount('PPR_Pago_Movimientos_Courier', 7);
    }

    public function test_assign_payments_uses_active_keys_weight_rates_and_additional_kilo_without_overwriting_no(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::create(['tenant_id' => $tenant->id, 'tax_id' => '11111111-1', 'tax_id_number' => '11111111', 'tax_id_check_digit' => '1', 'legal_name' => 'Proveedor Tarifa', 'operator_type' => 'Courier']);
        $paidClient = Client::create(['tenant_id' => $tenant->id, 'tax_id' => '22222222-2', 'tax_id_number' => '22222222', 'tax_id_check_digit' => '2', 'source_merchant_name' => 'Cliente SI', 'commercial_name' => 'Cliente SI', 'legal_name' => 'Cliente SI']);
        $unpaidClient = Client::create(['tenant_id' => $tenant->id, 'tax_id' => '33333333-3', 'tax_id_number' => '33333333', 'tax_id_check_digit' => '3', 'source_merchant_name' => 'Cliente NO', 'commercial_name' => 'Cliente NO', 'legal_name' => 'Cliente NO']);
        $service = ServiceType::factory()->create(['service_code' => 77, 'name' => 'Servicio Tarifa']);
        CostCenter::updateOrCreate(['cost_center_code' => 98], ['dispatch_guide_detail' => 'Tarifa prueba', 'additional_kilo_value' => 50, 'is_active' => true]);
        CostCenterWeightRate::updateOrCreate(['cost_center_code' => 98, 'final_weight' => 1], ['value' => 100, 'is_active' => true]);
        CostCenterWeightRate::updateOrCreate(['cost_center_code' => 98, 'final_weight' => 20], ['value' => 1000, 'is_active' => true]);
        foreach ([[$paidClient, 'SI'], [$unpaidClient, 'NO']] as [$client, $status]) {
            CostCenterKey::create(['tenant_id' => $tenant->id, 'provider_id' => $provider->id, 'provider_tax_id' => $provider->tax_id,
                'client_id' => $client->id, 'client_tax_id' => $client->tax_id, 'merchant_name' => $client->source_merchant_name,
                'service_type_id' => $service->id, 'service_code' => 77, 'service_name' => $service->name,
                'payment_status' => $status, 'cost_center_code' => 98, 'is_active' => true]);
        }
        CostCenterKey::query()->where('client_id', $paidClient->id)->firstOrFail()->replicate()->save();
        foreach ([
            ['uno', $paidClient, 1, null, 'Servicio Tarifa'],
            ['veinte', $paidClient, 20, null, 'Servicio Tarifa'],
            ['veintitres', $paidClient, 23, null, 'Servicio Tarifa'],
            ['sin_tarifa', $paidClient, 2, null, 'Servicio Tarifa'],
            ['protegido', $paidClient, 20, 'NO', 'Servicio Tarifa'],
            ['no', $unpaidClient, 1, null, 'Servicio Tarifa'],
            ['sin_llave', $paidClient, 1, null, 'Servicio Desconocido'],
        ] as $index => [$tracking, $client, $weight, $condition, $serviceName]) {
            $movement = CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => '4N20260701000'.$index.'-111', 'nombre_proceso' => '202607-Variable', 'service_name' => $serviceName, 'peso_real' => $weight]);
            CourierPaymentMovement::create(['tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id, 'periodo' => '202607',
                'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variable', 'seguimiento_paquete' => $tracking,
                'peso_final' => $weight, 'rut_proveedor' => $provider->tax_id, 'rut_cliente' => $client->tax_id,
                'condicion_pago' => $condition]);
        }
        DB::table('PPR_Pago_Movimientos_Courier')->where('seguimiento_paquete', 'veinte')->update(['peso_final' => 1]);
        DB::table('PPR_movimientos_courier')->where('tracking_number', '4N202607010001-111')->update(['peso_final' => 1]);

        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607']))->assertOk()->assertSee('Asignar Pagos');
        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202607'])
            ->assertRedirect()->assertSessionHas('status');
        foreach (['uno' => 100, 'veinte' => 1000, 'veintitres' => 1150] as $tracking => $value) {
            $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => $tracking, 'condicion_pago' => 'SI', 'valor' => $value]);
        }
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => 'no', 'condicion_pago' => 'NO', 'valor' => null]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => 'veinte', 'peso_final' => 20, 'condicion_pago' => 'SI', 'valor' => 1000]);
        $this->assertDatabaseHas('PPR_movimientos_courier', ['tracking_number' => '4N202607010001-111', 'peso_final' => 20]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => 'protegido', 'condicion_pago' => 'NO', 'valor' => null]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => 'sin_llave', 'condicion_pago' => null, 'valor' => null]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => 'sin_tarifa', 'condicion_pago' => null, 'valor' => null]);
        CostCenterWeightRate::query()->where('cost_center_code', 98)->where('final_weight', 20)->update(['value' => 2000]);
        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202607'])->assertRedirect();
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => 'veinte', 'condicion_pago' => 'SI', 'valor' => 2000]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => 'veintitres', 'condicion_pago' => 'SI', 'valor' => 2150]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => 'protegido', 'condicion_pago' => 'NO', 'valor' => null]);
        $this->assertDatabaseCount('PPR_Pago_Movimientos_Courier', 7);
    }

    public function test_missing_cost_center_key_providers_can_be_reviewed_and_configured_from_work_page(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $missing = Provider::create(['tenant_id' => $tenant->id, 'tax_id' => '11111111-1', 'tax_id_number' => '11111111', 'tax_id_check_digit' => '1', 'legal_name' => 'Proveedor Sin Llave', 'operational_name' => 'Operador Sin Llave', 'operator_type' => 'Courier']);
        $configured = Provider::create(['tenant_id' => $tenant->id, 'tax_id' => '22222222-2', 'tax_id_number' => '22222222', 'tax_id_check_digit' => '2', 'legal_name' => 'Proveedor Configurado', 'operator_type' => 'Courier']);
        $client = Client::create(['tenant_id' => $tenant->id, 'tax_id' => '33333333-3', 'tax_id_number' => '33333333', 'tax_id_check_digit' => '3', 'source_merchant_name' => 'Cliente Llave', 'commercial_name' => 'Cliente Llave', 'legal_name' => 'Cliente Llave SPA']);
        $service = ServiceType::factory()->create(['service_code' => 88, 'name' => 'Servicio Llave']);
        CostCenter::updateOrCreate(['cost_center_code' => 88], ['dispatch_guide_detail' => 'Centro Llave', 'additional_kilo_value' => 0, 'is_active' => true]);
        CostCenterKey::create(['tenant_id' => $tenant->id, 'provider_id' => $configured->id, 'provider_tax_id' => $configured->tax_id, 'client_id' => $client->id, 'client_tax_id' => $client->tax_id, 'merchant_name' => $client->source_merchant_name, 'service_type_id' => $service->id, 'service_code' => 88, 'service_name' => $service->name, 'key_code' => 'configured', 'payment_status' => 'SI', 'cost_center_code' => 88, 'is_active' => true]);
        foreach ([[$missing, '202607'], [$missing, '202607'], [$configured, '202607'], [$missing, '202608']] as $index => [$provider, $period]) {
            $movement = CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => '4N20260701000'.$index.'-111', 'nombre_proceso' => $period.'-Variable', 'service_name' => $service->name]);
            CourierPaymentMovement::create(['tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id, 'periodo' => $period, 'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variable', 'peso_final' => 1, 'rut_proveedor' => $provider->tax_id, 'razon_social_proveedor' => $provider->legal_name, 'rut_cliente' => $client->tax_id]);
        }

        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607']))
            ->assertOk()->assertSee('Revisar Inconsistencias Llave CC (1)')
            ->assertSee('target="_blank"', false);
        $this->get(route('provider-payments.courier-movements.compile.keys.review', ['period' => '202607']))
            ->assertOk()
            ->assertSee('Proveedor Sin Llave')
            ->assertSee('Cliente Llave')->assertSee('Servicio Llave')
            ->assertSee('Nueva combinación')->assertSee('Guardar y calcular pagos');
        $this->post(route('provider-payments.courier-movements.compile.keys.generate'), ['period' => '202607'])
            ->assertRedirect(route('provider-payments.courier-movements.compile.keys.review', ['period' => '202607']));
        $this->assertDatabaseHas('PPR_llave_centro_costos', ['provider_id' => $missing->id, 'client_id' => $client->id, 'service_type_id' => $service->id, 'agent_name' => 'Operador Sin Llave', 'cost_center_code' => 0, 'payment_status' => 'NO', 'is_active' => false]);
        $this->post(route('provider-payments.courier-movements.compile.keys.generate'), ['period' => '202607'])->assertRedirect();
        $this->assertSame(1, CostCenterKey::query()->where('provider_id', $missing->id)->count());
        $draft = CostCenterKey::query()->where('provider_id', $missing->id)->firstOrFail();
        $this->get(route('provider-payments.courier-movements.compile.keys.review', ['period' => '202607']))
            ->assertOk()->assertSee('Guardar y calcular pagos')->assertSee('Inactiva');
        $this->post(route('provider-payments.courier-movements.compile.keys.save'), [
            'period' => '202607', 'rows' => [['id' => $draft->id, 'cost_center_code' => 88, 'payment_status' => 'SI', 'is_active' => 1]],
        ])->assertRedirect(route('provider-payments.courier-movements.compile.keys.review', ['period' => '202607', 'provider' => '', 'page' => 1]));
        $this->assertDatabaseHas('PPR_llave_centro_costos', ['id' => $draft->id, 'cost_center_code' => 88, 'payment_status' => 'SI', 'is_active' => true]);
        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607']))
            ->assertOk()->assertSee('Revisar Inconsistencias Llave CC (0)');
        $this->post(route('provider-payments.courier-movements.compile.keys.save'), [
            'period' => '202607', 'rows' => [['id' => $draft->id, 'cost_center_code' => 88, 'payment_status' => 'NO', 'is_active' => 0]],
        ])->assertRedirect();
        $this->assertDatabaseHas('PPR_llave_centro_costos', ['id' => $draft->id, 'payment_status' => 'NO', 'is_active' => false]);
    }

    public function test_new_combinations_can_be_edited_and_paid_without_generating_every_missing_key(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => '11111111-1']);
        $client = Client::create(['tenant_id' => $tenant->id, 'tax_id' => '33333333-3',
            'tax_id_number' => '33333333', 'tax_id_check_digit' => '3', 'source_merchant_name' => 'Cliente Nuevo',
            'commercial_name' => 'Cliente Nuevo', 'legal_name' => 'Cliente Nuevo SPA']);
        $paidService = ServiceType::factory()->create(['service_code' => 901, 'name' => 'Servicio Nuevo Pagado']);
        $otherService = ServiceType::factory()->create(['service_code' => 902, 'name' => 'Servicio Nuevo Pendiente']);
        CostCenter::firstOrCreate(['cost_center_code' => 0], ['dispatch_guide_detail' => 'Sin costo',
            'additional_kilo_value' => 0, 'is_active' => true]);
        CostCenter::create(['cost_center_code' => 901, 'dispatch_guide_detail' => 'Centro nuevo',
            'additional_kilo_value' => 0, 'is_active' => true]);
        CostCenterWeightRate::create(['cost_center_code' => 901, 'final_weight' => 1,
            'value' => 2500, 'is_active' => true]);
        foreach ([$paidService, $otherService] as $index => $service) {
            $movement = CourierMovement::create(['tenant_id' => $tenant->id,
                'tracking_number' => '4N20260901000'.$index.'-111', 'nombre_proceso' => '202609-Variable',
                'service_name' => $service->name, 'peso_real' => 1]);
            CourierPaymentMovement::create(['tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id,
                'periodo' => '202609', 'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variable',
                'seguimiento_paquete' => $movement->tracking_number, 'peso_final' => 1,
                'rut_proveedor' => $provider->tax_id, 'rut_cliente' => $client->tax_id]);
        }

        $this->get(route('provider-payments.courier-movements.compile.keys.review', ['period' => '202609']))
            ->assertOk()->assertSee('Nueva combinación')->assertSee('Guardar y calcular pagos');

        $selected = ['create' => 1, 'provider_tax_id' => $provider->tax_id,
            'client_tax_id' => $client->tax_id, 'service_code' => $paidService->service_code,
            'cost_center_code' => 901, 'payment_status' => 'SI', 'is_active' => 1];
        $unselected = ['provider_tax_id' => $provider->tax_id, 'client_tax_id' => $client->tax_id,
            'service_code' => $otherService->service_code, 'cost_center_code' => 0,
            'payment_status' => 'NO', 'is_active' => 0];
        $this->post(route('provider-payments.courier-movements.compile.keys.save'), [
            'period' => '202609', 'assign_payments' => 1,
            'rows' => [array_replace($selected, ['cost_center_code' => 0]), $unselected],
        ])->assertSessionHasErrors('rows');
        $this->assertDatabaseMissing('PPR_llave_centro_costos', ['provider_tax_id' => $provider->tax_id]);

        $this->post(route('provider-payments.courier-movements.compile.keys.save'), [
            'period' => '202609', 'assign_payments' => 1, 'rows' => [$selected, $unselected],
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('PPR_llave_centro_costos', ['provider_id' => $provider->id,
            'client_id' => $client->id, 'service_code' => 901, 'payment_status' => 'SI',
            'cost_center_code' => 901, 'is_active' => true]);
        $this->assertDatabaseMissing('PPR_llave_centro_costos', ['provider_id' => $provider->id, 'service_code' => 902]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => '4N202609010000-111',
            'condicion_pago' => 'SI', 'valor' => 2500]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => '4N202609010001-111',
            'condicion_pago' => null, 'valor' => null]);

        $this->post(route('provider-payments.courier-movements.compile.keys.save'), [
            'period' => '202609', 'rows' => [array_replace($unselected, ['create' => 1,
                'payment_status' => 'REVISAR', 'is_active' => 1])],
        ])->assertRedirect();
        $this->get(route('provider-payments.courier-movements.compile.keys.review', ['period' => '202609']))
            ->assertOk()->assertSee('Servicio Nuevo Pendiente')->assertSee('REVISAR');
    }

    public function test_payment_summary_totals_only_considered_net_values_by_zone(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        foreach ([['RM', 'SI', 1234], ['RM', 'NO', 9999], ['Regiones', 'SI', 2500], [null, null, 4000]] as $index => [$zone, $condition, $value]) {
            $movement = CourierMovement::create(['tenant_id' => $tenant->id,
                'tracking_number' => '4N20260704000'.$index.'-111', 'nombre_proceso' => '202607-Variable']);
            CourierPaymentMovement::create(['tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id,
                'periodo' => '202607', 'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variable',
                'peso_final' => 1, 'zona' => $zone, 'condicion_pago' => $condition, 'valor' => $value]);
        }

        $response = $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607']))
            ->assertOk()->assertSee('Registros Considerados')->assertSee('Registros No Considerados')
            ->assertSee('Total Neto Considerado')->assertSee('$ 1.234')->assertSee('$ 2.500')->assertSee('$ 3.734');
        $dashboard = $response->viewData('paymentDashboard');
        $this->assertSame(1234, (int) $dashboard->get('RM')->firstWhere('condicion_pago', 'SI')->neto_considerado);
        $this->assertSame(0, (int) $dashboard->get('RM')->firstWhere('condicion_pago', 'NO')->neto_considerado);
        $this->assertSame(2500, (int) $dashboard->get('Regiones')->firstWhere('condicion_pago', 'SI')->neto_considerado);
    }

    public function test_worked_records_can_be_searched_by_each_requested_field(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        foreach ([
            ['4N202607010001-111', 'RM', '4N RM', 'Cliente Alfa', '11111111-1', 'Alfa SPA', 'Operador Alfa', 'Factura', 'Repartidor Alfa', '4N'],
            ['4N202607010002-222', 'Regiones', 'Temuco', 'Cliente Beta', '22222222-2', 'Beta SPA', 'Operador Beta', 'Boleta', 'Repartidor Beta', 'Otra'],
        ] as [$tracking, $zone, $matrix, $merchant, $rut, $legal, $operational, $document, $courierName, $company]) {
            $movement = CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => $tracking, 'nombre_proceso' => '202607-Variable', 'service_name' => $zone === 'RM' ? 'Servicio Standar' : 'Material Publicitario']);
            CourierPaymentMovement::create(['tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id, 'periodo' => '202607', 'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variable', 'seguimiento_paquete' => $tracking, 'peso_final' => 1, 'zona' => $zone, 'comuna_matriz' => $matrix, 'comerciante_pila' => $merchant, 'rut_cliente' => $rut, 'razon_social_cliente' => $legal, 'razon_social_proveedor' => 'Proveedor '.$legal, 'nombre_operacional' => $operational, 'tipo_documento' => $document, 'nombre_repartidor' => $courierName, 'empresa_mandante' => $company, 'condicion_pago' => $zone === 'RM' ? 'NO' : null]);
        }
        CourierPaymentMovement::query()->where('seguimiento_paquete', '4N202607010001-111')->update(['valor' => 1234567]);

        foreach (['service' => 'Servicio Standar', 'zone' => 'RM', 'matrix' => '4N RM', 'client' => 'Cliente Alfa', 'payment_condition' => 'NO', 'provider_legal_name' => 'Proveedor Alfa SPA', 'operational_name' => 'Operador Alfa', 'document_type' => 'Factura', 'courier_name' => 'Repartidor Alfa', 'company' => '4N'] as $filter => $value) {
            $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607', $filter => $value]))
                ->assertOk()->assertSee('Registros trabajados (1)')->assertSee('4N202607010001-111')->assertDontSee('4N202607010002-222')
                ->assertSee('<select name="'.$filter.'">', false);
        }
        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607', 'zone' => 'RM', 'client' => 'Cliente Beta']))
            ->assertOk()->assertSee('Registros trabajados (0)');
        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607', 'zone' => 'RM']))
            ->assertOk()->assertSee('$ 1.234.567')->assertSee('<option value="Cliente Alfa"', false)
            ->assertSee('<option value="Servicio Standar"', false)
            ->assertDontSee('<option value="Material Publicitario"', false)
            ->assertDontSee('<option value="Cliente Beta"', false)
            ->assertDontSee('<option value="Operador Beta"', false)
            ->assertDontSee('<option value="Repartidor Beta"', false)
            ->assertSee('<option value="Regiones"', false);
        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607', 'zone' => 'RM', 'client' => 'Cliente Alfa']))
            ->assertOk()->assertSee('<option value="NO"', false)
            ->assertDontSee('<option value="__unset__"', false)
            ->assertDontSee('<option value="Boleta"', false);
        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607', 'payment_condition' => '__unset__']))
            ->assertOk()->assertSee('Registros trabajados (1)')->assertSee('4N202607010002-222')->assertDontSee('4N202607010001-111')
            ->assertSee('Sin definir')->assertDontSee('<select name="client_legal_name">', false);
    }

    public function test_worked_records_can_be_filtered_by_loaded_process_in_the_selected_period(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        foreach ([
            ['202608', 'Variable', '4N202608010001-111', 'RM'],
            ['202608', '202608-Especiales', 'ESP-20260802-0001', 'Regiones'],
            ['202607', 'Lanas', '4N202607010001-111', 'RM'],
        ] as [$period, $process, $tracking, $zone]) {
            $movement = CourierMovement::create([
                'tenant_id' => $tenant->id, 'tracking_number' => $tracking,
                'nombre_proceso' => str_starts_with($process, $period.'-') ? $process : $period.'-'.$process,
            ]);
            CourierPaymentMovement::create([
                'tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id,
                'periodo' => $period, 'nombre_proceso' => $process,
                'tipo_pago' => $process === '202608-Especiales' ? 'Especiales' : $process,
                'seguimiento_paquete' => $tracking, 'peso_final' => 1, 'zona' => $zone,
            ]);
        }

        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202608']))
            ->assertOk()->assertSee('<select name="process">', false)
            ->assertSee('<option value="Variable" >202608-Variable (1)</option>', false)
            ->assertSee('<option value="Especiales" >202608-Especiales (1)</option>', false)
            ->assertDontSee('<option value="Lanas">', false);
        $this->get(route('provider-payments.courier-movements.compile.work', [
            'period' => '202608', 'process' => 'Especiales',
        ]))->assertOk()->assertSee('Registros trabajados (1)')
            ->assertSee('ESP-20260802-0001')->assertDontSee('4N202608010001-111');
        $this->get(route('provider-payments.courier-movements.compile.work', [
            'period' => '202608', 'process' => 'Especiales', 'zone' => 'RM',
        ]))->assertOk()->assertSee('Registros trabajados (0)');
        $this->get(route('provider-payments.courier-movements.compile.work', [
            'period' => '202608', 'process' => 'Lanas',
        ]))->assertOk()->assertSee('Registros trabajados (2)');
    }

    public function test_process_counts_distinguish_payment_records_from_origin_movements(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        foreach ([
            ['4N202608010001-111', '202608-Variable', 'Variable', 'Variable'],
            ['4N202608010002-111', '202608-Variable', '202608-Especiales', 'Especiales'],
            ['ESP-20260802-0001', '202608-Especiales', '202608-Especiales', 'Especiales'],
        ] as [$tracking, $originProcess, $paymentProcess, $paymentType]) {
            $movement = CourierMovement::create([
                'tenant_id' => $tenant->id, 'tracking_number' => $tracking,
                'nombre_proceso' => $originProcess,
            ]);
            CourierPaymentMovement::create([
                'tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id,
                'periodo' => '202608', 'nombre_proceso' => $paymentProcess,
                'tipo_pago' => $paymentType, 'seguimiento_paquete' => $tracking, 'peso_final' => 1,
            ]);
        }

        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202608']))
            ->assertOk()
            ->assertSee('202608-Especiales · Pagos: 2 · Origen: 1')
            ->assertSee('202608-Variable · Pagos: 1 · Origen: 2')
            ->assertSee('<option value="Especiales" >202608-Especiales (2)</option>', false)
            ->assertSee('<option value="Variable" >202608-Variable (1)</option>', false);
    }

    public function test_rm_and_temuco_provider_button_updates_only_exact_courier_assignments(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        Provider::create(['tenant_id' => $tenant->id, 'tax_id' => '77346078-7', 'tax_id_number' => '77346078', 'tax_id_check_digit' => '7', 'legal_name' => '4N', 'operational_name' => '4N RM', 'operator_type' => 'Courier']);
        Provider::create(['tenant_id' => $tenant->id, 'tax_id' => '78350442-1', 'tax_id_number' => '78350442', 'tax_id_check_digit' => '1', 'legal_name' => 'Nuevo proveedor SPA', 'operational_name' => 'Claudio Operacional', 'operator_type' => 'Courier', 'tax_document_type' => 'Factura']);
        DB::table('PPR_Proveedores_usuarios_4N')->insert([
            ['RutProveedor' => '77346078-7', 'ComunaMatriz' => '4N RM', 'NombreRepartidor' => 'Claudio Gonzalez', 'NuevoRutProveedor' => '78350442-1'],
            ['RutProveedor' => '77346078-7', 'ComunaMatriz' => '4N Temuco', 'NombreRepartidor' => '4N-Demo', 'NuevoRutProveedor' => 'N/A'],
        ]);
        foreach ([['202607', '4N RM', 'Claudio González'], ['202607', '4N Temuco', '4N-Demo'], ['202608', '4N RM', 'Claudio González']] as $index => [$period, $matrix, $courier]) {
            $movement = CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => '4N20260701000'.$index.'-111', 'nombre_proceso' => $period.'-Variable']);
            CourierPaymentMovement::create(['tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id, 'periodo' => $period, 'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variable', 'peso_final' => 1, 'empresa_mandante' => '4N', 'rut_proveedor' => '77346078-7', 'razon_social_proveedor' => '4N', 'comuna_matriz' => $matrix, 'nombre_repartidor' => $courier]);
        }

        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607']))
            ->assertOk()->assertSee('Proveedores RM y Temuco (1)')->assertSee('Actualizar Proveedores RM y Temuco');
        $this->post(route('provider-payments.courier-movements.compile.providers-4n.update'), ['period' => '202607'])
            ->assertRedirect()->assertSessionHas('status', '1 proveedores actualizados. 1 con N/A conservados; 0 sin cruce completo.');

        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['periodo' => '202607', 'nombre_repartidor' => 'Claudio González', 'rut_proveedor' => '78350442-1', 'razon_social_proveedor' => 'Nuevo proveedor SPA', 'nombre_operacional' => 'Claudio Operacional', 'tipo_documento' => 'Factura']);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['periodo' => '202607', 'nombre_repartidor' => '4N-Demo', 'rut_proveedor' => '77346078-7']);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['periodo' => '202608', 'nombre_repartidor' => 'Claudio González', 'rut_proveedor' => '77346078-7']);
        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607']))
            ->assertOk()->assertSee('Proveedores RM y Temuco (0)')->assertSee('1 con N/A')
            ->assertSee('No quedan registros con asignación válida para actualizar.');
        $this->assertDatabaseCount('PPR_movimientos_courier', 3);
    }

    public function test_non_payable_statuses_are_marked_no_without_removing_records(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        CourierStatus::query()->updateOrCreate(['name' => 'Anulado'], ['consider_for_payment' => false]);
        CourierStatus::query()->updateOrCreate(['name' => 'Entregado'], ['consider_for_payment' => true]);
        foreach ([['4N202607010001-111', '202607-Variable', 'Anulado'], ['4N202607010002-222', '202607-Variable', 'Entregado'], ['4N202608010003-333', '202608-Variable', 'Anulado']] as [$tracking, $process, $status]) {
            CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => $tracking, 'nombre_proceso' => $process, 'status' => $status]);
        }
        foreach (['202607', '202608'] as $period) {
            $this->post(route('provider-payments.courier-movements.compile.store'), ['period' => $period, 'processes' => ['Variable']])->assertRedirect();
        }
        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607']))
            ->assertOk()->assertSee('Marcar condición de pago NO')->assertSee('Anulado: 1')->assertSee('Sin definir');
        $this->post(route('provider-payments.courier-movements.compile.non-payable.mark'), ['period' => '202607'])->assertRedirect();

        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['periodo' => '202607', 'estado_envio' => 'Anulado', 'condicion_pago' => 'NO']);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['periodo' => '202607', 'estado_envio' => 'Entregado', 'condicion_pago' => null]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['periodo' => '202608', 'estado_envio' => 'Anulado', 'condicion_pago' => null]);
        $this->post(route('provider-payments.courier-movements.compile.store'), ['period' => '202607', 'processes' => ['Variable']])->assertRedirect();
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['periodo' => '202607', 'estado_envio' => 'Anulado', 'condicion_pago' => 'NO']);
        $this->assertDatabaseCount('PPR_Pago_Movimientos_Courier', 3);
        $this->assertDatabaseCount('PPR_movimientos_courier', 3);
    }

    public function test_internal_provider_is_marked_no_only_in_selected_period(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        foreach ([
            ['202607', '4 Nortes Logistica SPA'],
            ['202607', 'Otro proveedor SPA'],
            ['202608', '4 Nortes Logistica SPA'],
        ] as $index => [$period, $providerName]) {
            $movement = CourierMovement::create([
                'tenant_id' => $tenant->id,
                'tracking_number' => '4N20260701000'.$index.'-111',
                'nombre_proceso' => $period.'-Variable',
            ]);
            CourierPaymentMovement::create([
                'tenant_id' => $tenant->id,
                'courier_movement_id' => $movement->id,
                'periodo' => $period,
                'nombre_proceso' => 'Variable',
                'tipo_pago' => 'Variable',
                'seguimiento_paquete' => $movement->tracking_number,
                'peso_final' => 1,
                'razon_social_proveedor' => $providerName,
            ]);
        }

        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607']))
            ->assertOk()->assertSee('NO PAGAR Sin Usuario/Interno (1)');
        $this->post(route('provider-payments.courier-movements.compile.internal-provider.mark'), ['period' => '202607'])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['periodo' => '202607', 'razon_social_proveedor' => '4 Nortes Logistica SPA', 'condicion_pago' => 'NO']);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['periodo' => '202607', 'razon_social_proveedor' => 'Otro proveedor SPA', 'condicion_pago' => null]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['periodo' => '202608', 'razon_social_proveedor' => '4 Nortes Logistica SPA', 'condicion_pago' => null]);
        $this->assertDatabaseCount('PPR_Pago_Movimientos_Courier', 3);
        $this->assertDatabaseCount('PPR_movimientos_courier', 3);
    }

    public function test_special_payments_are_excluded_from_work_screen_validations_and_updates(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $internalProvider = Provider::create([
            'tenant_id' => $tenant->id, 'tax_id' => '77346078-7',
            'tax_id_number' => '77346078', 'tax_id_check_digit' => '7',
            'legal_name' => '4 Nortes Logistica SPA', 'operational_name' => '4N Temuco',
            'operator_type' => 'Courier',
        ]);
        Provider::create([
            'tenant_id' => $tenant->id, 'tax_id' => '78350442-1',
            'tax_id_number' => '78350442', 'tax_id_check_digit' => '1',
            'legal_name' => 'Otro proveedor SPA', 'operational_name' => 'Operador Temuco',
            'operator_type' => 'Courier',
        ]);
        $client = Client::create([
            'tenant_id' => $tenant->id, 'tax_id' => '11111111-1',
            'tax_id_number' => '11111111', 'tax_id_check_digit' => '1',
            'commercial_name' => 'Cliente especial', 'source_merchant_name' => 'Cliente especial',
            'legal_name' => 'Cliente Especial SPA',
        ]);
        $service = ServiceType::factory()->create(['service_code' => 93, 'name' => 'Servicio especial']);
        CourierStatus::query()->updateOrCreate(['name' => 'Anulado'], ['consider_for_payment' => false]);
        DB::table('PPR_Proveedores_usuarios_4N')->insert([
            'RutProveedor' => $internalProvider->tax_id, 'ComunaMatriz' => '4N Temuco',
            'NombreRepartidor' => '4N-Demo', 'NuevoRutProveedor' => '78350442-1',
        ]);
        $movement = CourierMovement::create([
            'tenant_id' => $tenant->id, 'tracking_number' => 'ESP-20260824-0001',
            'nombre_proceso' => '202608-Especiales', 'tipo_pago' => 'Especiales',
            'service_name' => $service->name, 'status' => 'Anulado',
        ]);
        $payment = CourierPaymentMovement::create([
            'tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id,
            'periodo' => '202608', 'nombre_proceso' => '202608-Especiales',
            'tipo_pago' => 'Especiales', 'seguimiento_paquete' => $movement->tracking_number,
            'peso_final' => 1, 'comuna_matriz' => '4N Temuco', 'zona' => 'Regiones',
            'rut_proveedor' => $internalProvider->tax_id,
            'razon_social_proveedor' => $internalProvider->legal_name,
            'rut_cliente' => $client->tax_id, 'estado_envio' => 'Anulado',
            'nombre_repartidor' => '4N-Demo', 'condicion_pago' => 'SI', 'valor' => 9000,
        ]);
        $legacyMovement = CourierMovement::create([
            'tenant_id' => $tenant->id, 'tracking_number' => 'ESP-20260824-0002',
            'nombre_proceso' => '202608-Variable', 'service_name' => $service->name,
            'status' => 'Anulado',
        ]);
        $legacyPayment = CourierPaymentMovement::create([
            'tenant_id' => $tenant->id, 'courier_movement_id' => $legacyMovement->id,
            'periodo' => '202608', 'nombre_proceso' => '202608-Especiales',
            'tipo_pago' => 'Variable', 'seguimiento_paquete' => $legacyMovement->tracking_number,
            'peso_final' => 1, 'comuna_matriz' => '4N Temuco', 'zona' => 'Regiones',
            'rut_proveedor' => $internalProvider->tax_id,
            'razon_social_proveedor' => $internalProvider->legal_name,
            'rut_cliente' => $client->tax_id, 'estado_envio' => 'Anulado',
            'nombre_repartidor' => '4N-Demo', 'condicion_pago' => 'SI', 'valor' => 4500,
        ]);

        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202608']))
            ->assertOk()->assertSee('Estados NO PAGAR (0)')
            ->assertSee('Proveedores RM y Temuco (0)')
            ->assertSee('NO PAGAR Sin Usuario/Interno (0)')
            ->assertSee('Revisar Inconsistencias Llave CC (0)')
            ->assertSee('ESP-20260824-0001');
        $this->post(route('provider-payments.courier-movements.compile.non-payable.mark'), ['period' => '202608'])->assertRedirect();
        $this->post(route('provider-payments.courier-movements.compile.providers-4n.update'), ['period' => '202608'])->assertRedirect();
        $this->post(route('provider-payments.courier-movements.compile.internal-provider.mark'), ['period' => '202608'])->assertRedirect();
        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202608'])->assertRedirect();
        $this->post(route('provider-payments.courier-movements.compile.keys.generate'), ['period' => '202608'])->assertRedirect();
        $this->post(route('provider-payments.courier-movements.compile.store'), [
            'period' => '202608', 'processes' => ['Variable'],
        ])->assertRedirect();

        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', [
            'id' => $payment->id, 'rut_proveedor' => $internalProvider->tax_id,
            'razon_social_proveedor' => $internalProvider->legal_name,
            'condicion_pago' => 'SI', 'valor' => 9000, 'peso_final' => 1,
        ]);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', [
            'id' => $legacyPayment->id, 'rut_proveedor' => $internalProvider->tax_id,
            'condicion_pago' => 'SI', 'valor' => 4500,
        ]);
        $this->assertSame(0, CostCenterKey::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_selected_processes_are_compiled_without_changing_source_weights(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $client = Client::create(['tenant_id' => $tenant->id, 'tax_id' => '11111111-1', 'tax_id_number' => '11111111', 'tax_id_check_digit' => '1', 'commercial_name' => 'Comerciante', 'source_merchant_name' => 'Pila', 'legal_name' => 'Cliente SA']);
        $provider = Provider::create(['tenant_id' => $tenant->id, 'tax_id' => '22222222-2', 'tax_id_number' => '22222222', 'tax_id_check_digit' => '2', 'legal_name' => 'Proveedor SA', 'operational_name' => 'Repartidor Norte', 'operator_type' => 'Courier', 'tax_document_type' => 'Factura']);
        Coverage::create(['tenant_id' => $tenant->id, 'provider_id' => $provider->id, 'commune_name' => 'Viña del Mar', 'matrix_commune_name' => 'Valparaíso', 'zone' => 'Z1']);
        Coverage::create(['tenant_id' => $tenant->id, 'provider_tax_id' => $provider->tax_id, 'commune_name' => 'Peñalolén', 'matrix_commune_name' => '4N RM', 'zone' => 'RM']);
        $matched = CourierMovement::create(['tenant_id' => $tenant->id, 'client_id' => $client->id, 'tracking_number' => '4N202608050001-111', 'tracking_code' => '4N202608050001', 'nombre_proceso' => '202608-Variable', 'tipo_pago' => 'Variable', 'fecha' => '2026-08-05', 'recipient_address' => 'Calle 1', 'destination_commune_name' => 'Vina del Mar', 'peso_real' => 8, 'peso_transformado' => 5, 'status' => 'Entregado']);
        CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => '4N202608050002-222', 'nombre_proceso' => '202608-Lanas', 'peso_transformado' => 7]);
        CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => '4N202608050003-333', 'nombre_proceso' => '202608-Variable', 'destination_commune_name' => 'Penalolen', 'peso_transformado' => 7]);

        $this->get(route('provider-payments.courier-movements.compile'))->assertOk()->assertSee('Trabajar Registros de Courier');
        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202608']))->assertOk()->assertSee('Variable')->assertSee('Lanas');
        $this->post(route('provider-payments.courier-movements.compile.store'), ['period' => '202608', 'processes' => ['Variable']])->assertRedirect();

        $this->assertDatabaseCount('PPR_Pago_Movimientos_Courier', 2);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['courier_movement_id' => $matched->id, 'zona' => 'Z1', 'comuna_matriz' => 'Valparaíso', 'periodo' => '202608', 'nombre_proceso' => 'Variable', 'seguimiento_paquete' => '4N202608050001-111', 'peso_final' => 8, 'rut_cliente' => '11111111-1', 'rut_proveedor' => '22222222-2', 'empresa_mandante' => '4N']);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['seguimiento_paquete' => '4N202608050003-333', 'zona' => 'RM', 'comuna_matriz' => '4N RM', 'rut_proveedor' => '22222222-2', 'peso_final' => 7]);
        $this->assertSame('Calle 1', CourierPaymentMovement::query()->where('courier_movement_id', $matched->id)->firstOrFail()->direccion);
        $this->assertSame(8, $matched->fresh()->peso_real);
        $this->post(route('provider-payments.courier-movements.compile.store'), ['period' => '202608', 'processes' => ['Variable']])->assertRedirect();
        $this->assertDatabaseCount('PPR_Pago_Movimientos_Courier', 2);
        $this->post(route('provider-payments.courier-movements.compile.store'), ['period' => '202608', 'processes' => ['Lanas']])->assertRedirect();
        $this->get(route('provider-payments.courier-movements.compile', ['period' => '202608']))
            ->assertOk()->assertSee('<strong>202608-Variable</strong>', false)->assertSee('<strong>202608-Lanas</strong>', false)->assertSee('Eliminar proceso');
        config()->set('provider-payments.process_deletion_key', 'test-master-key');
        $this->delete(route('provider-payments.courier-movements.compile.destroy'), ['period' => '202608', 'process' => 'Variable'])
            ->assertSessionHasErrors('password');
        $this->assertDatabaseCount('PPR_Pago_Movimientos_Courier', 3);
        $this->delete(route('provider-payments.courier-movements.compile.destroy'), ['period' => '202608', 'process' => 'Variable', 'password' => 'test-master-key'])->assertRedirect();
        $this->assertDatabaseCount('PPR_Pago_Movimientos_Courier', 1);
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', ['nombre_proceso' => 'Lanas', 'periodo' => '202608']);
        $this->assertDatabaseCount('PPR_movimientos_courier', 3);
    }
}
