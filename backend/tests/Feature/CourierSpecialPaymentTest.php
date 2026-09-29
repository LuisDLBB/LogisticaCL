<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\CourierSpecialPayment;
use App\Models\Coverage;
use App\Models\Provider;
use App\Models\ServiceType;
use App\Models\Tenant;
use App\Modules\ProviderPayments\Services\CourierPaymentAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CourierSpecialPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_september_calama_special_is_associated_with_marcelo_when_loaded(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $marcelo = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => '13172671-6',
            'legal_name' => 'Marcelo Avendaño', 'operational_name' => 'Marcelo Avendaño (Calama)']);
        $path = $this->workbook([
            ['2026-09-03', 'Usuario', 'Jefe', 'Operador Calama', 'REG', 'ESP-SEP-CALAMA', 'Calama', 'Cliente', null, 30000],
        ]);

        try {
            $this->post(route('provider-payments.courier-movements.especiales.store'), [
                'file' => new UploadedFile($path, 'especiales.xlsx', null, null, true),
                'period_month' => '2026-09',
            ])->assertSessionHas('status');
            $this->assertSame($marcelo->id, CourierSpecialPayment::query()->firstOrFail()->provider_id);
            $this->get(route('provider-payments.courier-movements.especiales', ['periodo' => '202609-Especiales']))
                ->assertOk()->assertSee($marcelo->operational_name);
        } finally {
            @unlink($path);
        }
    }

    public function test_master_key_corrects_one_finalized_special_provider_without_changing_its_amount_or_other_payments(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $oldProvider = Provider::factory()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '77346078-7',
            'legal_name' => '4 Nortes Logistica SPA', 'operational_name' => '4N RM',
            'tax_document_type' => 'PENDIENTE',
        ]);
        $claudio = Provider::factory()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '12538127-8',
            'legal_name' => 'Claudio Andres Cuevas Aravena',
            'operational_name' => 'Claudio Cuevas (Temuco)',
            'tax_document_type' => 'Boleta de Honorarios',
        ]);
        $movement = CourierMovement::factory()->create([
            'tenant_id' => $tenant->id, 'tracking_number' => '4N202608121636-823',
            'nombre_proceso' => '202608-Variable',
        ]);
        $payment = CourierPaymentMovement::query()->create([
            'tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id,
            'periodo' => '202608', 'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variables',
            'seguimiento_paquete' => $movement->tracking_number, 'zona' => 'Regiones',
            'peso_final' => 1, 'valor' => 650, 'condicion_pago' => 'SI',
        ]);
        $snapshot = $payment->getRawOriginal();
        $payment->update([
            'nombre_proceso' => 'Especiales', 'tipo_pago' => 'Especiales',
            'provider_id' => $oldProvider->id, 'rut_proveedor' => $oldProvider->tax_id,
            'razon_social_proveedor' => $oldProvider->legal_name,
            'nombre_operacional' => $oldProvider->operational_name,
            'tipo_documento' => $oldProvider->tax_document_type,
            'valor' => 30000, 'empresa_mandante' => '4N',
        ]);
        $special = CourierSpecialPayment::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608-Especiales',
            'codigo_seguimiento' => $movement->tracking_number,
            'finalized_tracking_number' => $movement->tracking_number,
            'provider_id' => $oldProvider->id, 'monto' => 30000,
            'finalized_at' => $payment->fresh()->updated_at,
            'payment_before_finalization' => $snapshot,
        ]);
        config()->set('provider-payments.process_deletion_key', 'test-master-key');

        $this->get(route('provider-payments.courier-movements.especiales', ['periodo' => $special->periodo]))
            ->assertOk()->assertSee('Corregir proveedor');
        $this->post(route('provider-payments.courier-movements.especiales.correct-provider', $special->id), [
            'provider_id' => $claudio->id, 'password' => 'incorrecta',
        ])->assertSessionHasErrors('password');
        $this->assertSame($oldProvider->id, $special->fresh()->provider_id);

        $this->post(route('provider-payments.courier-movements.especiales.correct-provider', $special->id), [
            'provider_id' => $claudio->id, 'password' => 'test-master-key',
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('Pago_Movimientos_Courier', [
            'id' => $payment->id, 'seguimiento_paquete' => $movement->tracking_number,
            'provider_id' => $claudio->id, 'razon_social_proveedor' => $claudio->legal_name,
            'rut_proveedor' => $claudio->tax_id, 'nombre_operacional' => $claudio->operational_name,
            'tipo_documento' => $claudio->tax_document_type, 'zona' => 'Regiones',
            'nombre_proceso' => 'Especiales', 'valor' => 30000, 'condicion_pago' => 'SI',
        ]);
        $this->assertSame(1, CourierPaymentMovement::query()->where('seguimiento_paquete', $movement->tracking_number)->count());
        $this->assertSame($claudio->id, $special->fresh()->provider_id);
        $this->assertEquals($payment->fresh()->updated_at, $special->fresh()->finalized_at);
        $this->assertSame(650, $special->fresh()->payment_before_finalization['valor']);

        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])
            ->assertSessionHas('status');
        $this->assertDatabaseHas('Maestro_Pagos', [
            'seguimiento_paquete' => $movement->tracking_number,
            'rut_proveedor' => $claudio->tax_id, 'tipo_documento' => 'Boleta de Honorarios',
            'empresa_mandante' => '4N', 'valor' => 30000,
        ]);
        $this->post(route('provider-payments.courier-movements.especiales.correct-provider', $special->id), [
            'provider_id' => $oldProvider->id, 'password' => 'test-master-key',
        ])->assertSessionHasErrors('period');
        $this->assertSame($claudio->id, $special->fresh()->provider_id);
    }

    public function test_special_import_rejects_paid_tracking_and_offers_excel_report(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $movement = CourierMovement::factory()->create([
            'tenant_id' => $tenant->id, 'tracking_number' => 'PAID-001',
            'nombre_proceso' => '202608-Variable',
        ]);
        CourierPaymentMovement::query()->create([
            'tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id,
            'periodo' => '202608', 'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variable',
            'seguimiento_paquete' => 'PAID-001', 'peso_final' => 1,
            'condicion_pago' => 'SI', 'valor' => 5000,
            'zona' => 'RM', 'razon_social_proveedor' => 'Proveedor de prueba',
            'rut_proveedor' => '11111111-1', 'empresa_mandante' => '4N',
            'tipo_documento' => 'Factura',
        ]);
        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])
            ->assertSessionHas('status');
        Storage::fake('local');
        $path = $this->workbook([
            ['2026-09-01', 'Usuario', 'Jefe', 'Operador Temuco', 'REG', 'PAID-001', 'Temuco', 'Cliente', 'Ya pagado', 30000],
            ['2026-09-01', 'Usuario', 'Jefe', 'Operador Temuco', 'REG', 'N/A', 'Temuco', 'Cliente', 'Nuevo', 12000],
        ]);

        try {
            $this->post(route('provider-payments.courier-movements.especiales.store'), [
                'period_month' => '2026-09',
                'file' => new UploadedFile($path, 'especiales.xlsx', null, null, true),
            ])->assertSessionHas('paid_report_token');
        } finally {
            unlink($path);
        }

        $this->assertDatabaseCount('courier_special_payments', 1);
        $this->assertDatabaseMissing('courier_special_payments', ['codigo_seguimiento' => 'PAID-001']);
        $this->get(route('provider-payments.courier-movements.especiales', ['periodo' => '202609-Especiales']))
            ->assertOk()->assertSee('Exportar registros ya pagados en Excel');
        $token = session('paid_tracking_reports')[0];
        $this->get(route('provider-payments.courier-movements.paid-report.download', $token))
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_special_file_cannot_move_unfinished_rows_out_of_a_definitively_closed_month(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $movement = CourierMovement::factory()->create([
            'tenant_id' => $tenant->id, 'tracking_number' => 'CLOSE-001', 'nombre_proceso' => '202608-Variable',
        ]);
        CourierPaymentMovement::query()->create([
            'tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id,
            'periodo' => '202608', 'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variable',
            'seguimiento_paquete' => 'CLOSE-001', 'peso_final' => 1, 'condicion_pago' => 'SI', 'valor' => 5000,
            'zona' => 'RM', 'razon_social_proveedor' => 'Proveedor de prueba',
            'rut_proveedor' => '11111111-1', 'empresa_mandante' => '4N',
            'tipo_documento' => 'Factura',
        ]);
        $path = $this->workbook([
            ['2026-08-01', 'Usuario', 'Jefe', 'Operador Temuco', 'REG', 'N/A', 'Temuco', 'Cliente', 'Pendiente', 12000],
        ]);
        $special = CourierSpecialPayment::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608-Especiales',
            'hash_archivo' => hash_file('sha256', $path), 'finalized_at' => null,
        ]);
        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])->assertSessionHas('status');

        try {
            $this->post(route('provider-payments.courier-movements.especiales.store'), [
                'period_month' => '2026-09',
                'file' => new UploadedFile($path, 'especiales.xlsx', null, null, true),
            ])->assertSessionHasErrors('file');
        } finally {
            unlink($path);
        }

        $this->assertSame('202608-Especiales', $special->fresh()->periodo);
        $this->assertDatabaseCount('courier_special_payments', 1);
    }

    public function test_master_key_reopens_a_closed_special_period_and_removes_its_synthetic_payment(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $period = '202608-Especiales';
        $tracking = 'ESP-20260824-0001';
        $movement = CourierMovement::query()->create([
            'tenant_id' => $tenant->id, 'source_system' => 'Especiales',
            'tracking_number' => $tracking, 'nombre_proceso' => $period,
        ]);
        $payment = CourierPaymentMovement::query()->create([
            'tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id,
            'periodo' => '202608', 'nombre_proceso' => $period, 'tipo_pago' => 'Especiales',
            'seguimiento_paquete' => $tracking, 'peso_final' => 1, 'valor' => 10000,
        ]);
        $special = CourierSpecialPayment::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => $period,
            'finalized_tracking_number' => $tracking, 'finalized_at' => $payment->updated_at,
        ]);
        config()->set('provider-payments.process_deletion_key', 'test-master-key');

        $this->post(route('provider-payments.courier-movements.especiales.reopen'), [
            'periodo' => $period, 'password' => 'test-master-key',
        ])->assertRedirect(route('provider-payments.courier-movements.especiales', ['periodo' => $period]));

        $this->assertNull($special->fresh()->finalized_at);
        $this->assertDatabaseMissing('Pago_Movimientos_Courier', ['id' => $payment->id]);
        $this->assertDatabaseMissing('movimientos_courier', ['id' => $movement->id]);
        $this->get(route('provider-payments.courier-movements.especiales', ['periodo' => $period]))
            ->assertOk()->assertSee('Grabar datos · Finalizar proceso')->assertDontSee('Reabrir período');
    }

    public function test_reopening_specials_keeps_ds_group_in_rm_even_if_the_old_snapshot_says_regiones(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '77201525-9',
            'tax_id_number' => '77201525', 'tax_id_check_digit' => '9',
        ]);
        $movement = CourierMovement::factory()->create([
            'tenant_id' => $tenant->id, 'tracking_number' => '4N202608010001-999',
            'nombre_proceso' => '202608-Variable',
        ]);
        $payment = CourierPaymentMovement::query()->create([
            'tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id,
            'seguimiento_paquete' => $movement->tracking_number, 'periodo' => '202608',
            'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variable',
            'provider_id' => $provider->id, 'rut_proveedor' => $provider->tax_id,
            'zona' => 'Regiones', 'peso_final' => 1, 'valor' => 100,
        ]);
        $snapshot = $payment->getRawOriginal();
        $snapshot['zona'] = 'Regiones';
        $payment->update(['nombre_proceso' => '202608-Especiales', 'tipo_pago' => 'Especiales', 'valor' => 200]);
        CourierSpecialPayment::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608-Especiales',
            'finalized_tracking_number' => $movement->tracking_number,
            'finalized_at' => $payment->fresh()->updated_at,
            'payment_before_finalization' => $snapshot,
        ]);
        config()->set('provider-payments.process_deletion_key', 'test-master-key');

        $this->post(route('provider-payments.courier-movements.especiales.reopen'), [
            'periodo' => '202608-Especiales', 'password' => 'test-master-key',
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('Pago_Movimientos_Courier', [
            'id' => $payment->id, 'nombre_proceso' => 'Variable', 'zona' => 'RM', 'valor' => 100,
        ]);
    }

    public function test_selected_payment_period_applies_to_every_row_regardless_of_date(): void
    {
        $path = $this->workbook([
            ['2026-07-29', 'Usuario', 'Jefe', 'Agente 1', 'REG', 'N/A', 'Concepción', 'Cliente A', 'Especial', 33000],
            ['2026-08-01', 'Usuario', 'Jefe', 'Agente 2', 'REG', 'N/A', 'Temuco', null, null, 4167],
        ]);

        try {
            $this->post(route('provider-payments.courier-movements.especiales.store'), [
                'file' => new UploadedFile($path, 'especiales.xlsx', null, null, true),
                'period_month' => '2026-08',
            ])->assertRedirect(route('provider-payments.courier-movements.especiales'));

            $this->assertSame(2, CourierSpecialPayment::query()->count());
            $this->assertDatabaseHas('courier_special_payments', ['periodo' => '202608-Especiales', 'fecha' => '2026-07-29', 'monto' => 33000]);
            $this->assertDatabaseHas('courier_special_payments', ['periodo' => '202608-Especiales', 'monto' => 4167]);
            $this->get(route('provider-payments.courier-movements.especiales'))
                ->assertOk()->assertSee('202608-Especiales')->assertDontSee('202607-Especiales');

            $this->post(route('provider-payments.courier-movements.especiales.store'), [
                'file' => new UploadedFile($path, 'especiales.xlsx', null, null, true),
                'period_month' => '2026-08',
            ])->assertSessionHas('status', 'Período 202608-Especiales: 0 pagos cargados, 0 corregidos, 2 filas ya registradas y 0 seguimientos ya pagados que no se cargaron.');
            $this->assertSame(2, CourierSpecialPayment::query()->count());

            $this->post(route('provider-payments.courier-movements.especiales.store'), [
                'file' => new UploadedFile($path, 'especiales.xlsx', null, null, true),
                'period_month' => '2026-09',
            ])->assertSessionHas('status', 'Período 202609-Especiales: 0 pagos cargados, 2 corregidos, 2 filas ya registradas y 0 seguimientos ya pagados que no se cargaron.');
            $this->assertSame(2, CourierSpecialPayment::query()->where('periodo', '202609-Especiales')->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_invalid_amount_does_not_import_any_row(): void
    {
        $path = $this->workbook([
            ['2026-07-29', 'Usuario', 'Jefe', 'Agente', 'REG', 'ABC', 'Concepción', 'Cliente', null, 33000],
            ['2026-07-30', 'Usuario', 'Jefe', 'Agente', 'REG', 'DEF', 'Concepción', 'Cliente', null, 'inválido'],
        ]);

        try {
            $this->post(route('provider-payments.courier-movements.especiales.store'), [
                'file' => new UploadedFile($path, 'especiales.xlsx', null, null, true),
                'period_month' => '2026-08',
            ])->assertSessionHasErrors('file');
            $this->assertSame(0, CourierSpecialPayment::query()->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_a_loaded_payment_can_be_corrected_without_changing_its_source(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $payment = CourierSpecialPayment::factory()->create([
            'tenant_id' => $tenant->id,
            'periodo' => '202608-Especiales',
            'archivo_origen' => 'base-original.xlsx',
            'fila_origen' => 7,
        ]);
        $sourceHash = $payment->hash_archivo;

        $this->put(route('provider-payments.courier-movements.especiales.update', $payment), [
            'fecha' => '2026-08-24',
            'usuario_ingresa' => 'Usuario corregido',
            'autoriza' => 'Supervisor',
            'agente' => 'Agente corregido',
            'zona_tipo' => 'REG',
            'codigo_seguimiento' => '4N202608240001-001',
            'localidad' => 'Temuco',
            'cliente' => 'Cliente nuevo',
            'descripcion' => 'Ajuste validado',
            'monto' => 25000,
        ])->assertRedirect(route('provider-payments.courier-movements.especiales', ['periodo' => '202608-Especiales']))
            ->assertSessionHas('status', 'Pago especial actualizado.');

        $this->assertDatabaseHas('courier_special_payments', [
            'id' => $payment->id,
            'periodo' => '202608-Especiales',
            'agente' => 'Agente corregido',
            'cliente' => 'Cliente nuevo',
            'monto' => 25000,
            'archivo_origen' => 'base-original.xlsx',
            'hash_archivo' => $sourceHash,
            'fila_origen' => 7,
        ]);
    }

    public function test_invalid_amount_does_not_change_a_loaded_payment(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $payment = CourierSpecialPayment::factory()->create(['tenant_id' => $tenant->id, 'monto' => 9000]);

        $this->put(route('provider-payments.courier-movements.especiales.update', $payment), [
            'fecha' => '2026-08-24', 'usuario_ingresa' => 'Usuario', 'autoriza' => 'Supervisor',
            'agente' => 'Agente', 'zona_tipo' => 'REG', 'localidad' => 'Temuco', 'monto' => -1,
        ])->assertSessionHasErrors('monto');

        $this->assertDatabaseHas('courier_special_payments', ['id' => $payment->id, 'monto' => 9000]);
    }

    public function test_a_payment_from_another_tenant_cannot_be_corrected(): void
    {
        $payment = CourierSpecialPayment::factory()->create(['monto' => 9000]);

        $this->put(route('provider-payments.courier-movements.especiales.update', $payment), [
            'fecha' => '2026-08-24', 'usuario_ingresa' => 'Usuario', 'autoriza' => 'Supervisor',
            'agente' => 'Agente', 'zona_tipo' => 'REG', 'localidad' => 'Temuco', 'monto' => 25000,
        ])->assertNotFound();

        $this->assertDatabaseHas('courier_special_payments', ['id' => $payment->id, 'monto' => 9000]);
    }

    public function test_search_filters_loaded_payments_without_changing_the_period(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        CourierSpecialPayment::factory()->create(['tenant_id' => $tenant->id, 'periodo' => '202608-Especiales', 'agente' => 'Agente Uno']);
        CourierSpecialPayment::factory()->create(['tenant_id' => $tenant->id, 'periodo' => '202608-Especiales', 'agente' => 'Agente Dos']);

        $this->get(route('provider-payments.courier-movements.especiales', [
            'periodo' => '202608-Especiales', 'q' => 'Agente Uno',
        ]))->assertOk()->assertSee('Agente Uno')->assertDontSee('Agente Dos')
            ->assertSee('1 registros encontrados.');
    }

    public function test_payments_with_missing_associations_appear_first_with_distinct_colors(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id]);
        $client = Client::query()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '55555555-5', 'tax_id_number' => '55555555',
            'tax_id_check_digit' => '5', 'commercial_name' => 'Cliente confirmado',
            'legal_name' => 'Cliente confirmado Spa',
        ]);
        CourierSpecialPayment::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608-Especiales',
            'agente' => 'Completo', 'provider_id' => $provider->id, 'client_id' => $client->id,
        ]);
        CourierSpecialPayment::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608-Especiales',
            'agente' => 'Solo cliente', 'client_id' => $client->id,
        ]);
        CourierSpecialPayment::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608-Especiales',
            'agente' => 'Ninguna asociacion',
        ]);

        $this->get(route('provider-payments.courier-movements.especiales', ['periodo' => '202608-Especiales']))
            ->assertOk()
            ->assertSeeInOrder(['Ninguna asociacion', 'Solo cliente', 'Completo'])
            ->assertSee('special-row-review')
            ->assertSee('special-row-partial');
    }

    public function test_coverage_and_client_name_propose_editable_associations(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => '77777777-7']);
        $alternative = Provider::factory()->create(['tenant_id' => $tenant->id]);
        Coverage::factory()->create([
            'tenant_id' => $tenant->id, 'provider_id' => null,
            'provider_tax_id' => '77777777-7', 'commune_name' => 'Concepción',
        ]);
        $client = Client::query()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '11111111-1', 'tax_id_number' => '11111111',
            'tax_id_check_digit' => '1', 'commercial_name' => 'ZebraMatrix',
            'source_merchant_name' => 'ZebraMatrix', 'legal_name' => 'ZebraMatrix Spa',
        ]);
        $payment = CourierSpecialPayment::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608-Especiales',
            'agente' => 'Operador Concepcion', 'cliente' => 'ZM',
        ]);

        $this->get(route('provider-payments.courier-movements.especiales'))
            ->assertOk()
            ->assertSee('Revisar coincidencia')
            ->assertSee('Nombre de pila del proveedor')
            ->assertSee('Nombre de pila del cliente')
            ->assertSee('form="asociar-'.$payment->id.'"', false)
            ->assertViewHas('associations', fn (array $associations): bool => $associations[$payment->id]['provider']['id'] === $provider->id
                && $associations[$payment->id]['provider']['score'] === 100
                && $associations[$payment->id]['client']['id'] === $client->id
                && $associations[$payment->id]['client']['uncertain'] === true);

        $this->assertDatabaseHas('courier_special_payments', [
            'id' => $payment->id, 'provider_id' => null, 'client_id' => null,
        ]);

        $this->put(route('provider-payments.courier-movements.especiales.update', $payment), [
            'fecha' => '2026-08-24', 'usuario_ingresa' => 'Usuario', 'autoriza' => 'Supervisor',
            'agente' => 'Operador Concepcion', 'zona_tipo' => 'REG', 'localidad' => 'Concepción',
            'cliente' => 'ZM', 'monto' => 25000, 'provider_id' => $alternative->id, 'client_id' => $client->id,
        ])->assertSessionHas('status', 'Pago especial actualizado.');

        $this->assertDatabaseHas('courier_special_payments', [
            'id' => $payment->id, 'provider_id' => $alternative->id, 'client_id' => $client->id,
        ]);
    }

    public function test_a_provider_from_another_tenant_cannot_be_associated(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $payment = CourierSpecialPayment::factory()->create(['tenant_id' => $tenant->id]);
        $foreignProvider = Provider::factory()->create();

        $this->put(route('provider-payments.courier-movements.especiales.associate', $payment), [
            'provider_id' => $foreignProvider->id,
        ])->assertSessionHasErrors('provider_id');

        $this->assertDatabaseHas('courier_special_payments', ['id' => $payment->id, 'provider_id' => null]);
    }

    public function test_tracking_code_assigns_client_and_service_on_import(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $client = Client::query()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '33333333-3', 'tax_id_number' => '33333333',
            'tax_id_check_digit' => '3', 'commercial_name' => 'Cliente del seguimiento',
            'legal_name' => 'Cliente del seguimiento Spa',
        ]);
        $service = ServiceType::query()->create(['service_code' => 99, 'name' => 'Servicio Especial Prueba']);
        $trackingCode = '4N202608240001-999';
        CourierMovement::factory()->create([
            'tenant_id' => $tenant->id, 'client_id' => $client->id,
            'tracking_number' => $trackingCode, 'service_name' => $service->name,
        ]);
        $path = $this->workbook([
            ['2026-08-24', 'Usuario', 'Supervisor', 'Operador Concepcion', 'REG', $trackingCode, 'Concepción', 'Texto original distinto', 'Pago especial', 25000],
        ]);

        try {
            $this->post(route('provider-payments.courier-movements.especiales.store'), [
                'file' => new UploadedFile($path, 'especiales.xlsx', null, null, true),
                'period_month' => '2026-08',
            ])->assertRedirect();

            $this->assertDatabaseHas('courier_special_payments', [
                'codigo_seguimiento' => $trackingCode,
                'cliente' => 'Texto original distinto',
                'client_id' => $client->id,
                'service_type_id' => $service->id,
            ]);
        } finally {
            @unlink($path);
        }
    }

    public function test_inline_association_changes_selected_ids_without_changing_original_text(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id]);
        $client = Client::query()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '44444444-4', 'tax_id_number' => '44444444',
            'tax_id_check_digit' => '4', 'commercial_name' => 'Cliente seleccionado',
            'legal_name' => 'Cliente seleccionado Spa',
        ]);
        $service = ServiceType::query()->create(['service_code' => 98, 'name' => 'Servicio seleccionado']);
        $payment = CourierSpecialPayment::factory()->create([
            'tenant_id' => $tenant->id, 'agente' => 'Operador original', 'cliente' => 'Texto cliente original',
        ]);

        $this->put(route('provider-payments.courier-movements.especiales.associate', $payment), [
            'provider_id' => $provider->id, 'client_id' => $client->id, 'service_type_id' => $service->id,
        ])->assertSessionHas('status', 'Proveedor, cliente y servicio asociados al pago especial.');

        $this->assertDatabaseHas('courier_special_payments', [
            'id' => $payment->id, 'agente' => 'Operador original', 'cliente' => 'Texto cliente original',
            'provider_id' => $provider->id, 'client_id' => $client->id, 'service_type_id' => $service->id,
        ]);
    }

    public function test_page_associations_save_multiple_rows_and_leave_other_rows_unchanged(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id]);
        $client = Client::query()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '66666666-6', 'tax_id_number' => '66666666',
            'tax_id_check_digit' => '6', 'commercial_name' => 'Cliente de página',
            'legal_name' => 'Cliente de página Spa',
        ]);
        $service = ServiceType::query()->create(['service_code' => 97, 'name' => 'Servicio de página']);
        $first = CourierSpecialPayment::factory()->create(['tenant_id' => $tenant->id, 'periodo' => '202608-Especiales']);
        $second = CourierSpecialPayment::factory()->create(['tenant_id' => $tenant->id, 'periodo' => '202608-Especiales']);
        $untouched = CourierSpecialPayment::factory()->create(['tenant_id' => $tenant->id, 'periodo' => '202608-Especiales']);

        $this->put(route('provider-payments.courier-movements.especiales.associate-page'), [
            'periodo' => '202608-Especiales',
            'rows' => [
                ['id' => $first->id, 'provider_id' => $provider->id, 'client_id' => $client->id, 'service_type_id' => $service->id],
                ['id' => $second->id, 'provider_id' => $provider->id, 'client_id' => '', 'service_type_id' => $service->id],
            ],
        ])->assertRedirect(route('provider-payments.courier-movements.especiales', ['periodo' => '202608-Especiales']))
            ->assertSessionHas('status', '2 pagos especiales guardados.');

        $this->assertDatabaseHas('courier_special_payments', [
            'id' => $first->id, 'provider_id' => $provider->id,
            'client_id' => $client->id, 'service_type_id' => $service->id,
        ]);
        $this->assertDatabaseHas('courier_special_payments', [
            'id' => $second->id, 'provider_id' => $provider->id,
            'client_id' => null, 'service_type_id' => $service->id,
        ]);
        $this->assertDatabaseHas('courier_special_payments', [
            'id' => $untouched->id, 'provider_id' => null, 'client_id' => null,
        ]);
    }

    public function test_page_associations_reject_a_row_outside_the_selected_period(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $payment = CourierSpecialPayment::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202607-Especiales',
        ]);

        $this->put(route('provider-payments.courier-movements.especiales.associate-page'), [
            'periodo' => '202608-Especiales',
            'rows' => [['id' => $payment->id, 'provider_id' => '', 'client_id' => '', 'service_type_id' => '']],
        ])->assertSessionHasErrors('rows');

        $this->assertDatabaseHas('courier_special_payments', [
            'id' => $payment->id, 'periodo' => '202607-Especiales', 'client_id' => null,
        ]);
    }

    public function test_page_associations_reject_a_provider_from_another_tenant_without_saving_any_row(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $payment = CourierSpecialPayment::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608-Especiales',
        ]);
        $foreignProvider = Provider::factory()->create();

        $this->put(route('provider-payments.courier-movements.especiales.associate-page'), [
            'periodo' => '202608-Especiales',
            'rows' => [['id' => $payment->id, 'provider_id' => $foreignProvider->id, 'client_id' => '', 'service_type_id' => '']],
        ])->assertSessionHasErrors('rows.0.provider_id');

        $this->assertDatabaseHas('courier_special_payments', ['id' => $payment->id, 'provider_id' => null]);
    }

    public function test_ambiguous_tracking_code_does_not_assign_a_service(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        CourierMovement::factory()->create([
            'tenant_id' => $tenant->id, 'tracking_code' => 'CODIGO-COMPARTIDO',
            'service_name' => 'Servicio Standar',
        ]);
        CourierMovement::factory()->create([
            'tenant_id' => $tenant->id, 'tracking_code' => 'CODIGO-COMPARTIDO',
            'service_name' => 'Servicio Spot (Cotizacion)',
        ]);
        $payment = CourierSpecialPayment::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608-Especiales',
            'codigo_seguimiento' => 'CODIGO-COMPARTIDO',
        ]);

        $this->artisan('courier-special:sync-tracking', ['period' => '202608-Especiales'])
            ->assertSuccessful();

        $this->assertDatabaseHas('courier_special_payments', [
            'id' => $payment->id, 'client_id' => null, 'service_type_id' => null,
        ]);
    }

    public function test_finalization_updates_matching_payments_and_creates_missing_esp_movements_once(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id]);
        $client = Client::query()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '88888888-8', 'tax_id_number' => '88888888',
            'tax_id_check_digit' => '8', 'commercial_name' => 'Cliente especial',
            'source_merchant_name' => 'Cliente especial', 'legal_name' => 'Cliente Especial Spa',
        ]);
        $service = ServiceType::query()->create(['service_code' => 96, 'name' => 'Servicio de prueba final']);
        Coverage::factory()->create([
            'tenant_id' => $tenant->id, 'provider_id' => $provider->id,
            'provider_tax_id' => $provider->tax_id, 'commune_name' => 'Concepción',
            'matrix_commune_name' => 'Concepción', 'zone' => 'Regiones', 'is_active' => true,
        ]);
        $existingMovement = CourierMovement::factory()->create([
            'tenant_id' => $tenant->id, 'tracking_number' => '4N202608240001-001',
            'nombre_proceso' => '202608-Variable',
        ]);
        $existingPayment = CourierPaymentMovement::query()->create([
            'tenant_id' => $tenant->id, 'courier_movement_id' => $existingMovement->id,
            'seguimiento_paquete' => $existingMovement->tracking_number, 'tipo_pago' => 'Variables',
            'nombre_proceso' => 'Variable', 'periodo' => '202608', 'peso_final' => 3,
            'condicion_pago' => 'NO', 'valor' => 2345,
        ]);
        CourierMovement::factory()->create([
            'tenant_id' => $tenant->id, 'tracking_number' => 'ESP-20260823-0002',
        ]);
        $common = [
            'tenant_id' => $tenant->id, 'periodo' => '202608-Especiales',
            'provider_id' => $provider->id, 'client_id' => $client->id,
            'service_type_id' => $service->id, 'fecha' => '2026-08-24',
            'agente' => 'Operador Concepcion', 'localidad' => 'Centro',
            'descripcion' => 'Entrega especial', 'autoriza' => 'Jefa',
        ];
        $matched = CourierSpecialPayment::factory()->create($common + [
            'codigo_seguimiento' => strtolower($existingMovement->tracking_number), 'monto' => 10000,
        ]);
        $new = CourierSpecialPayment::factory()->create($common + [
            'codigo_seguimiento' => 'N/A', 'monto' => 5000,
        ]);

        $this->post(route('provider-payments.courier-movements.especiales.finalize'), [
            'periodo' => '202608-Especiales',
        ])->assertRedirect(route('provider-payments.courier-movements.especiales', ['periodo' => '202608-Especiales']))
            ->assertSessionHas('status', 'Proceso finalizado: 1 movimientos actualizados y 1 creados.');

        $this->assertDatabaseHas('Pago_Movimientos_Courier', [
            'id' => $existingPayment->id, 'periodo' => '202608',
            'nombre_proceso' => 'Especiales', 'client_id' => $client->id,
            'provider_id' => $provider->id, 'service_type_id' => $service->id,
            'service_code' => 96, 'service_name' => $service->name,
            'condicion_pago' => 'SI', 'valor' => 10000,
        ]);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', [
            'seguimiento_paquete' => 'ESP-20260824-0003', 'periodo' => '202608',
            'nombre_proceso' => 'Especiales', 'comuna_matriz' => 'Concepción',
            'peso_final' => 1, 'valor' => 5000,
        ]);
        $synthetic = CourierPaymentMovement::query()->where('seguimiento_paquete', 'ESP-20260824-0003')->firstOrFail();
        $this->assertSame('Operador Concepcion / Centro / Entrega especial / Jefa', $synthetic->direccion);
        $this->assertDatabaseHas('movimientos_courier', [
            'id' => $synthetic->courier_movement_id, 'tracking_number' => 'ESP-20260824-0003',
            'client_id' => $client->id, 'service_name' => $service->name, 'peso_final' => 1,
        ]);
        $this->assertDatabaseHas('courier_special_payments', [
            'id' => $matched->id, 'finalized_tracking_number' => $existingMovement->tracking_number,
        ]);
        $this->assertDatabaseHas('courier_special_payments', [
            'id' => $new->id, 'finalized_tracking_number' => 'ESP-20260824-0003',
        ]);

        $this->get(route('provider-payments.courier-movements.especiales', ['periodo' => '202608-Especiales']))
            ->assertOk()->assertSee('Período cerrado')->assertSee('Reabrir período')
            ->assertDontSee('Guardar todos los cambios en pantalla');
        $this->put(route('provider-payments.courier-movements.especiales.associate', $matched->id), [
            'provider_id' => $provider->id, 'client_id' => $client->id, 'service_type_id' => $service->id,
        ])->assertSessionHasErrors('periodo');
        $this->post(route('provider-payments.courier-movements.especiales.store'), [
            'period_month' => '2026-08', 'file' => UploadedFile::fake()->create('especiales.xlsx'),
        ])->assertSessionHasErrors('periodo');
        config()->set('provider-payments.process_deletion_key', 'test-master-key');
        $this->post(route('provider-payments.courier-movements.especiales.reopen'), [
            'periodo' => '202608-Especiales', 'password' => 'incorrecta',
        ])->assertSessionHasErrors('password');
        $this->assertDatabaseHas('courier_special_payments', ['id' => $matched->id, 'periodo' => '202608-Especiales']);

        $this->post(route('provider-payments.courier-movements.especiales.finalize'), [
            'periodo' => '202608-Especiales',
        ])->assertSessionHasErrors('periodo');
        $this->assertSame(2, CourierPaymentMovement::query()->where('tenant_id', $tenant->id)->count());

        app(CourierPaymentAssigner::class)->assign($tenant->id, '202608');
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['id' => $existingPayment->id, 'valor' => 10000]);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['id' => $synthetic->id, 'valor' => 5000]);

        config()->set('provider-payments.process_deletion_key', 'test-master-key');
        $snapshot = $matched->fresh()->payment_before_finalization;
        $matched->update(['payment_before_finalization' => null]);
        $this->delete(route('provider-payments.movements.processes.destroy'), [
            'process_name' => '202608-Especiales', 'password' => 'test-master-key',
        ])->assertSessionHasErrors('process_name');
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['id' => $existingPayment->id, 'valor' => 10000]);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['id' => $synthetic->id, 'valor' => 5000]);
        $matched->update(['payment_before_finalization' => $snapshot]);

        $this->delete(route('provider-payments.movements.processes.destroy'), [
            'process_name' => '202608-Variable', 'password' => 'test-master-key',
        ])->assertSessionHasErrors('process_name');
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['id' => $existingPayment->id, 'valor' => 10000]);

        $this->delete(route('provider-payments.movements.processes.destroy'), [
            'process_name' => '202608-Especiales', 'password' => 'test-master-key',
        ])->assertRedirect(route('provider-payments.dashboard', ['period' => '202608']))
            ->assertSessionHas('status', 'Especiales revertidos. Movimientos nuevos eliminados: 1. Pagos anteriores restaurados: 1.');

        $this->assertDatabaseHas('Pago_Movimientos_Courier', [
            'id' => $existingPayment->id, 'nombre_proceso' => 'Variable',
            'tipo_pago' => 'Variables', 'condicion_pago' => 'NO', 'valor' => 2345,
        ]);
        $this->assertDatabaseMissing('Pago_Movimientos_Courier', ['id' => $synthetic->id]);
        $this->assertDatabaseMissing('movimientos_courier', ['id' => $synthetic->courier_movement_id]);
        $this->assertDatabaseHas('movimientos_courier', ['id' => $existingMovement->id]);
        $this->assertDatabaseHas('courier_special_payments', [
            'id' => $matched->id, 'finalized_at' => null,
            'finalized_tracking_number' => null, 'payment_before_finalization' => null,
        ]);
        $this->assertDatabaseHas('courier_special_payments', [
            'id' => $new->id, 'finalized_at' => null, 'finalized_tracking_number' => null,
        ]);
    }

    public function test_finalization_requires_every_row_to_have_provider_client_and_service(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        CourierSpecialPayment::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608-Especiales',
        ]);

        $this->post(route('provider-payments.courier-movements.especiales.finalize'), [
            'periodo' => '202608-Especiales',
        ])->assertSessionHasErrors('periodo');
        $this->assertSame(0, CourierPaymentMovement::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_finalization_respects_the_manually_selected_provider_and_fixed_special_amount(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $originalProvider = Provider::factory()->create(['tenant_id' => $tenant->id]);
        $selectedProvider = Provider::factory()->create(['tenant_id' => $tenant->id]);
        $client = Client::query()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '77777777-7',
            'tax_id_number' => '77777777', 'tax_id_check_digit' => '7',
            'commercial_name' => 'Cliente manual', 'legal_name' => 'Cliente Manual Spa',
        ]);
        $service = ServiceType::query()->create(['service_code' => 94, 'name' => 'Servicio especial manual']);
        Coverage::factory()->create([
            'tenant_id' => $tenant->id, 'provider_id' => $originalProvider->id,
            'provider_tax_id' => $originalProvider->tax_id, 'commune_name' => 'Cunco',
            'matrix_commune_name' => '4N Temuco', 'zone' => 'Regiones', 'is_active' => true,
        ]);
        $movement = CourierMovement::factory()->create([
            'tenant_id' => $tenant->id, 'tracking_number' => '4N202608200001-001',
        ]);
        $existingPayment = CourierPaymentMovement::query()->create([
            'tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id,
            'seguimiento_paquete' => $movement->tracking_number, 'tipo_pago' => 'Variables',
            'comuna_matriz' => '4N Temuco', 'zona' => 'Regiones', 'peso_final' => 2,
            'rut_proveedor' => $originalProvider->tax_id, 'valor' => 100,
            'periodo' => '202608', 'nombre_proceso' => 'Variable',
        ]);
        $common = [
            'tenant_id' => $tenant->id, 'periodo' => '202608-Especiales',
            'provider_id' => $selectedProvider->id, 'client_id' => $client->id,
            'service_type_id' => $service->id, 'agente' => 'Operador Temuco',
            'localidad' => 'Cunco',
        ];
        CourierSpecialPayment::factory()->create($common + [
            'codigo_seguimiento' => $movement->tracking_number, 'monto' => 30000,
        ]);
        CourierSpecialPayment::factory()->create($common + [
            'codigo_seguimiento' => 'N/A', 'monto' => 9000,
        ]);

        $this->post(route('provider-payments.courier-movements.especiales.finalize'), [
            'periodo' => '202608-Especiales',
        ])->assertSessionHas('status', 'Proceso finalizado: 1 movimientos actualizados y 1 creados.');

        $this->assertDatabaseHas('Pago_Movimientos_Courier', [
            'id' => $existingPayment->id, 'provider_id' => $selectedProvider->id,
            'rut_proveedor' => $selectedProvider->tax_id, 'comuna_matriz' => '4N Temuco',
            'valor' => 30000, 'condicion_pago' => 'SI',
        ]);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', [
            'provider_id' => $selectedProvider->id, 'rut_proveedor' => $selectedProvider->tax_id,
            'comuna_matriz' => '4N Temuco', 'valor' => 9000,
            'tipo_pago' => 'Especiales', 'nombre_proceso' => 'Especiales',
        ]);
    }

    public function test_finalization_replaces_a_matched_payments_old_zone_with_the_selected_providers_coverage(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id]);
        $client = Client::query()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '66666666-6',
            'tax_id_number' => '66666666', 'tax_id_check_digit' => '6',
            'commercial_name' => 'Cliente especial', 'legal_name' => 'Cliente Especial Spa',
        ]);
        $service = ServiceType::query()->create(['service_code' => 93, 'name' => 'Servicio especial']);
        Coverage::factory()->create([
            'tenant_id' => $tenant->id, 'provider_id' => $provider->id,
            'provider_tax_id' => $provider->tax_id, 'commune_name' => 'San Fernando',
            'matrix_commune_name' => 'Matriz San Fernando', 'zone' => 'Regiones', 'is_active' => true,
        ]);
        $movement = CourierMovement::factory()->create([
            'tenant_id' => $tenant->id, 'tracking_number' => '4N202608073675-457',
        ]);
        $payment = CourierPaymentMovement::query()->create([
            'tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id,
            'seguimiento_paquete' => $movement->tracking_number, 'tipo_pago' => 'Variables',
            'nombre_proceso' => '202608-Variable', 'periodo' => '202608',
            'comuna_matriz' => '4N RM', 'zona' => 'RM', 'peso_final' => 1, 'valor' => 100,
        ]);
        $special = CourierSpecialPayment::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608-Especiales',
            'provider_id' => $provider->id, 'client_id' => $client->id,
            'service_type_id' => $service->id, 'agente' => 'Operador San Fernando',
            'localidad' => 'Las Cabras', 'zona_tipo' => 'REG',
            'codigo_seguimiento' => $movement->tracking_number, 'monto' => 40000,
        ]);

        $this->post(route('provider-payments.courier-movements.especiales.finalize'), [
            'periodo' => '202608-Especiales',
        ])->assertSessionHas('status', 'Proceso finalizado: 1 movimientos actualizados y 0 creados.');

        $this->assertDatabaseHas('Pago_Movimientos_Courier', [
            'id' => $payment->id, 'provider_id' => $provider->id,
            'comuna_matriz' => 'Matriz San Fernando', 'zona' => 'Regiones', 'valor' => 40000,
        ]);
        $this->assertSame('RM', $special->fresh()->payment_before_finalization['zona']);

        config()->set('provider-payments.process_deletion_key', 'test-master-key');
        $this->post(route('provider-payments.courier-movements.especiales.reopen'), [
            'periodo' => '202608-Especiales', 'password' => 'test-master-key',
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('Pago_Movimientos_Courier', [
            'id' => $payment->id, 'nombre_proceso' => 'Variable',
            'comuna_matriz' => '4N RM', 'zona' => 'RM', 'valor' => 100,
        ]);
    }

    public function test_finalization_rolls_back_if_a_later_row_has_no_matrix_commune(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id]);
        $client = Client::query()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '99999999-9', 'tax_id_number' => '99999999',
            'tax_id_check_digit' => '9', 'commercial_name' => 'Cliente cobertura',
            'legal_name' => 'Cliente cobertura Spa',
        ]);
        $service = ServiceType::query()->create(['service_code' => 95, 'name' => 'Servicio cobertura']);
        Coverage::factory()->create([
            'tenant_id' => $tenant->id, 'provider_id' => $provider->id,
            'commune_name' => 'Concepción', 'matrix_commune_name' => 'Concepción', 'is_active' => true,
        ]);
        $common = [
            'tenant_id' => $tenant->id, 'periodo' => '202608-Especiales',
            'provider_id' => $provider->id, 'client_id' => $client->id,
            'service_type_id' => $service->id, 'codigo_seguimiento' => 'N/A',
        ];
        $first = CourierSpecialPayment::factory()->create($common + ['agente' => 'Operador Concepcion']);
        CourierSpecialPayment::factory()->create($common + ['agente' => 'Operador Temuco']);

        $this->post(route('provider-payments.courier-movements.especiales.finalize'), [
            'periodo' => '202608-Especiales',
        ])->assertSessionHasErrors('periodo');
        $this->assertDatabaseHas('courier_special_payments', ['id' => $first->id, 'finalized_at' => null]);
        $this->assertSame(0, CourierPaymentMovement::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, CourierMovement::query()->where('tenant_id', $tenant->id)
            ->where('source_system', 'Especiales')->count());
    }

    public function test_a_nonfinalized_special_payment_can_be_deleted_without_a_master_password(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $payment = CourierSpecialPayment::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608-Especiales',
        ]);

        $this->delete(route('provider-payments.courier-movements.especiales.destroy', $payment))
            ->assertSessionHas('status', 'Pago especial eliminado.');
        $this->assertDatabaseMissing('courier_special_payments', ['id' => $payment->id]);
    }

    public function test_a_finalized_special_payment_cannot_be_deleted_from_the_upload_screen(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $payment = CourierSpecialPayment::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608-Especiales',
            'finalized_tracking_number' => 'ESP-20260824-0001', 'finalized_at' => now(),
        ]);

        $this->delete(route('provider-payments.courier-movements.especiales.destroy', $payment))
            ->assertSessionHasErrors('payment');
        $this->assertDatabaseHas('courier_special_payments', ['id' => $payment->id]);
    }

    /** @param array<int, array<int, mixed>> $data */
    private function workbook(array $data): string
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([
            ['Fecha', 'Usuario Ingresa', 'Autoriza', 'Agente', 'Zona / Tipo', 'ID', 'Localidad', 'Cliente', 'Descripcion', 'Monto $$'],
            ...$data,
        ]);
        $path = tempnam(sys_get_temp_dir(), 'special-payments-').'.xlsx';
        (new Xlsx($sheet))->save($path);
        $sheet->disconnectWorksheets();

        return $path;
    }
}
