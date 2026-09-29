<?php

namespace Tests\Feature;

use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\CourierSpecialPayment;
use App\Models\Provider;
use App\Models\ProviderBankAccount;
use App\Models\Tenant;
use App\Modules\ProviderPayments\Services\MaestroPagoTaxCalculator;
use App\Modules\ProviderPayments\Services\PurchaseOrderDocument;
use Database\Seeders\ProviderOcFilenameSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\ProviderPaymentsWorkflowTestCase;

class MonthlyPaymentClosingTest extends ProviderPaymentsWorkflowTestCase
{
    use RefreshDatabase;

    public function test_monthly_close_waits_for_peumo_guides_and_rates_to_be_resolved(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        CourierMovement::create([
            'tenant_id' => $tenant->id, 'tracking_number' => '4N202611010001',
            'nombre_proceso' => '202611-Peumo', 'tipo_pago' => 'Peumo',
            'dispatch_guide' => 'PEU-123', 'destination_commune_name' => 'María Pinto',
        ]);

        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202611'])
            ->assertSessionHasErrors('period');
        $this->assertDatabaseMissing('Cierres_Pagos', ['tenant_id' => $tenant->id, 'periodo' => '202611']);
    }

    public function test_monthly_close_accepts_peumo_movements_paid_as_specials_and_peumo_rows_marked_no(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $special = $this->payment($tenant->id, 'PEUMO-SPECIAL', 'SI', 11111, null, [
            'periodo' => '202611', 'nombre_proceso' => 'Especiales', 'tipo_pago' => 'Especiales',
        ]);
        $special->courierMovement()->update(['nombre_proceso' => '202611-Peumo', 'tipo_pago' => 'Peumo']);
        $notPayable = $this->payment($tenant->id, 'PEUMO-NO', 'NO', 0, null, [
            'periodo' => '202611', 'nombre_proceso' => 'Peumo', 'tipo_pago' => 'Peumo',
        ]);
        $notPayable->courierMovement()->update(['nombre_proceso' => '202611-Peumo', 'tipo_pago' => 'Peumo']);

        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202611'])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('Cierres_Pagos', ['tenant_id' => $tenant->id, 'periodo' => '202611', 'registros' => 1]);
        $this->assertDatabaseHas('Maestro_Pagos', [
            'seguimiento_paquete' => 'PEUMO-SPECIAL', 'nombre_proceso' => 'Especiales', 'valor' => 11111,
        ]);
        $this->assertDatabaseMissing('Maestro_Pagos', ['seguimiento_paquete' => 'PEUMO-NO']);
    }

    public function test_boleta_rounding_matches_published_sii_examples_for_2026(): void
    {
        $calculator = app(MaestroPagoTaxCalculator::class);

        $this->assertSame(84722, $calculator->calculate('Boleta de Honorarios', 555555, 1)['valor_impuesto']);
        $this->assertSame(470833, $calculator->calculate('Boleta de Honorarios', 555555, 1)['valor_final_total']);
        $this->assertSame(254167, $calculator->calculate('Boleta de Honorarios', 1666666, 2)['valor_impuesto']);
        $this->assertSame(1412499, $calculator->calculate('Boleta de Honorarios', 1666666, 2)['valor_final_total']);
    }

    public function test_monthly_close_copies_only_payable_rows_and_locks_the_period(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $paid = $this->payment($tenant->id, 'CLOSE-001', 'SI', 12000);
        $paid->update(['tipo_documento' => 'Factura', 'empresa_mandante' => '4N']);
        $this->payment($tenant->id, 'CLOSE-002', 'NO', 9000);

        $this->get(route('provider-payments.courier-movements.compile', ['period' => '202608']))
            ->assertOk()->assertSee('Cerrar procesos del período 202608')->assertSee('1 pagos SI');
        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('Cierres_Pagos', ['tenant_id' => $tenant->id, 'periodo' => '202608', 'registros' => 1, 'total' => 12000]);
        $this->assertDatabaseHas('Maestro_Pagos', [
            'seguimiento_paquete' => 'CLOSE-001', 'pago_movimiento_id' => $paid->id,
            'periodo' => '202608', 'nombre_proceso' => 'Variable', 'condicion_pago' => 'SI', 'valor' => 12000,
            'tipo_documento' => 'Factura', 'empresa_mandante' => '4N', 'oc' => '2026080001',
            'impuesto' => 'IVA', 'valor_impuesto' => 2280, 'valor_final_total' => 14280,
        ]);
        $this->assertDatabaseMissing('Maestro_Pagos', ['seguimiento_paquete' => 'CLOSE-002']);
        $this->assertSame($paid->getRawOriginal('direccion'), DB::table('Maestro_Pagos')->where('seguimiento_paquete', 'CLOSE-001')->value('direccion'));
        $this->get(route('provider-payments.courier-movements.compile', ['period' => '202608']))
            ->assertOk()->assertSee('Período 202608 cerrado definitivamente')
            ->assertSee('Ver 1 órdenes de compra asignadas')->assertSee('2026080001')->assertDontSee('Reabrir proceso');
        $this->get(route('provider-payments.dashboard', ['period' => '202608']))
            ->assertOk()->assertSee('Cierre definitivo: trabajado')->assertSee('Período 202608: Cerrado definitivamente');
        $this->get(route('provider-payments.dashboard'))
            ->assertOk()->assertSee('Período 202608: Cerrado definitivamente')
            ->assertDontSee('<option value="202609"', false);
        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])
            ->assertSessionHasErrors('period');
        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202608'])
            ->assertSessionHasErrors('period');
    }

    public function test_close_rolls_back_when_a_payable_tracking_is_repeated(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $this->payment($tenant->id, 'CLOSE-001', 'SI', 1000);
        $this->payment($tenant->id, 'CLOSE-002', 'SI', 2000, 'CLOSE-001');

        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])
            ->assertSessionHasErrors('period');
        $this->assertDatabaseCount('Maestro_Pagos', 0);
        $this->assertDatabaseCount('Cierres_Pagos', 0);
    }

    public function test_close_requires_the_supplier_details_needed_for_a_purchase_order(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $this->payment($tenant->id, 'OC-MISSING-RUT', 'SI', 12000, null, ['rut_proveedor' => null]);

        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])
            ->assertSessionHasErrors('period');
        $this->assertDatabaseCount('Maestro_Pagos', 0);
        $this->assertDatabaseCount('Cierres_Pagos', 0);
    }

    public function test_close_calculates_taxes_for_each_document_type_in_whole_pesos(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $cases = [
            ['FACTURA-TAX', 'Factura', 101, 'IVA', '19.00', 19, 120],
            ['BOLETA-TAX', 'Boleta de Honorarios', 1002, 'Retencion', '15.25', 153, 849],
            ['EXENTA-TAX', 'Factura Exenta', 777, 'Exento', '0.00', 0, 777],
            ['TERCERO-TAX', 'Boleta de Honorarios Tercero', 2001, 'Retencion', '15.25', 305, 1696],
        ];
        foreach ($cases as $index => [$tracking, $document, $amount]) {
            $this->payment($tenant->id, $tracking, 'SI', $amount, null, [
                'tipo_documento' => $document,
                'rut_proveedor' => sprintf('1111111%d-%d', $index, $index),
            ]);
        }

        $this->get(route('provider-payments.courier-movements.compile.purchase-orders', ['period' => '202608']))
            ->assertOk()->assertSee('Valor impuesto')->assertSee('Valor final total')
            ->assertSee('4 órdenes de compra')->assertSee('$ 3.442');

        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])
            ->assertSessionHas('status');

        $this->get(route('provider-payments.courier-movements.compile.purchase-orders', ['period' => '202608']))
            ->assertOk()->assertSee('$ 3.442');
        foreach ($cases as [$tracking, , , $tax, $percentage, $taxAmount, $finalAmount]) {
            $row = DB::table('Maestro_Pagos')->where('seguimiento_paquete', $tracking)->first();
            $this->assertSame($tax, $row->impuesto);
            $this->assertSame($percentage, number_format((float) $row->porcentaje_impuesto, 2, '.', ''));
            $this->assertSame($taxAmount, (int) $row->valor_impuesto);
            $this->assertSame($finalAmount, (int) $row->valor_final_total);
        }
    }

    public function test_a_purchase_order_cannot_mix_invoice_and_boleta_payments(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $this->payment($tenant->id, 'INVOICE-001', 'SI', 1000);
        $this->payment($tenant->id, 'BOLETA-001', 'SI', 1000, null, ['tipo_documento' => 'Boleta de Honorarios']);

        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])
            ->assertSessionHasErrors('period');
        $this->assertDatabaseCount('Maestro_Pagos', 0);
    }

    public function test_download_requires_a_supplier_filename_and_it_can_be_added_in_providers(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '11111111-1',
            'tax_id_number' => '11111111', 'tax_id_check_digit' => '1',
        ]);
        $this->payment($tenant->id, 'ALIAS-001', 'SI', 1000);
        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])
            ->assertSessionHas('status');

        $this->get(route('provider-payments.courier-movements.compile.purchase-orders.pdf', '2026080001'))
            ->assertSessionHasErrors('oc');
        $this->post(route('provider-payments.maintainers.proveedores.oc-filename', $provider), [
            'company_code' => '4N', 'service_scope' => 'General', 'file_stem' => 'Proveedor_Prueba',
        ])->assertSessionHas('status');
        $this->get(route('provider-payments.maintainers.proveedores'))
            ->assertOk()->assertSee('Nombres de archivo para órdenes de compra')
            ->assertSee('Proveedor_Prueba');
        $this->get(route('provider-payments.courier-movements.compile.purchase-orders.pdf', '2026080001'))
            ->assertOk()->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename=2026080001_4N_FAC_Proveedor_Prueba.pdf');
    }

    public function test_filename_seed_creates_provisional_suppliers_and_service_aliases(): void
    {
        $this->seed(ProviderOcFilenameSeeder::class);
        $this->assertDatabaseHas('providers', ['tax_id' => '77235107-0', 'is_active' => false]);
        $this->assertDatabaseMissing('provider_oc_filenames', ['file_stem' => 'Quincena']);

        $ds = Provider::query()->where('tax_id', '77201525-9')->firstOrFail();
        $this->assertDatabaseHas('provider_oc_filenames', [
            'provider_id' => $ds->id, 'company_code' => '4N',
            'service_scope' => 'Troncal Norte', 'file_stem' => 'DSG_Troncal_Norte',
        ]);
        $this->assertDatabaseHas('provider_oc_filenames', [
            'provider_id' => $ds->id, 'company_code' => '4N',
            'service_scope' => 'Troncal V', 'file_stem' => 'DSG_RUTA_V',
        ]);
    }

    public function test_close_rounds_tax_on_the_whole_purchase_order_and_exports_matching_documents(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create([
            'tenant_id' => $tenant->id,
            'tax_id' => '11111111-1',
            'tax_id_number' => '11111111',
            'tax_id_check_digit' => '1',
            'legal_name' => 'Proveedor de prueba',
            'operational_name' => 'Proveedor Santiago',
            'payment_terms' => 'Quincena',
            'payment_terms_pmcb' => 'Contado',
        ]);
        $provider->ocFilenames()->create(['company_code' => 'PMCB', 'service_scope' => 'General', 'file_stem' => 'ProveedorSantiago']);
        foreach ([1, 2, 3] as $number) {
            $this->payment($tenant->id, 'ROUND-'.$number, 'SI', 1, null, [
                'provider_id' => $provider->id,
                'empresa_mandante' => 'PMCB', 'service_name' => 'Servicio Fijo Courier',
                'comuna_destino' => 'Santiago', 'nombre_operacional' => 'Proveedor Santiago',
                'fecha' => '2026-08-01',
            ]);
        }
        $this->get(route('provider-payments.courier-movements.compile.purchase-orders.pdf', '2026080001'))
            ->assertNotFound();
        $this->get(route('provider-payments.courier-movements.compile.purchase-orders', ['period' => '202608']))
            ->assertOk()->assertSee('$ 4');
        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])
            ->assertSessionHas('status');

        $this->assertSame(1, (int) DB::table('Maestro_Pagos')->sum('valor_impuesto'));
        $this->assertSame(4, (int) DB::table('Maestro_Pagos')->sum('valor_final_total'));
        $this->get(route('provider-payments.courier-movements.compile.purchase-orders', ['period' => '202608']))
            ->assertOk()->assertSee(route('provider-payments.courier-movements.compile.purchase-orders.pdf', '2026080001'), false);

        $pdf = $this->get(route('provider-payments.courier-movements.compile.purchase-orders.pdf', '2026080001'))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('2026080001_PMCB_FAC_ProveedorSantiago.pdf', $pdf->headers->get('content-disposition'));
        $bytes = $pdf->streamedContent();
        $this->assertStringStartsWith('%PDF-1.4', $bytes);
        $this->assertStringContainsString('TRANSPORTE Y DISTRIBUCION PMCB', $bytes);
        $this->assertStringContainsString('ORDEN DE COMPRA  2026080001', $bytes);

        $response = $this->get(route('provider-payments.courier-movements.compile.purchase-orders.excel', '2026080001'))
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('2026080001_PMCB_FAC_ProveedorSantiago.xlsx', $response->headers->get('content-disposition'));
        $path = tempnam(sys_get_temp_dir(), 'oc-excel-');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = IOFactory::load($path);
            $this->assertSame(['Resumen por servicio', 'Base de pagos'], $book->getSheetNames());
            $summary = $book->getSheet(0);
            $this->assertSame('Razón social proveedor: Proveedor de prueba', $summary->getCell('A2')->getValue());
            $this->assertSame('RUT proveedor: 11111111-1', $summary->getCell('A3')->getValue());
            $this->assertSame('Nombre de pila proveedor: Proveedor Santiago', $summary->getCell('A4')->getValue());
            $this->assertSame('Condición de pago: Contado', $summary->getCell('A5')->getValue());
            $this->assertStringContainsString('Período 202608', $summary->getCell('A6')->getValue());
            $this->assertSame(3, $summary->getCell('E9')->getValue());
            $this->assertSame(4, $summary->getCell('E12')->getValue());
            $this->assertSame('ROUND-1', $book->getSheet(1)->getCell('C4')->getValue());
            $this->assertSame('Calle de prueba', $book->getSheet(1)->getCell('G4')->getValue());
            $this->assertSame('Santiago', $book->getSheet(1)->getCell('H4')->getValue());
            $this->assertSame('Comuna', $book->getSheet(1)->getCell('H3')->getValue());
            $this->assertSame('2026-08-01', $book->getSheet(1)->getCell('D4')->getFormattedValue());
            $this->assertIsNumeric($book->getSheet(1)->getCell('D4')->getValue());
            $this->assertSame('S', $book->getSheet(1)->getHighestColumn());
            $this->assertSame('Tipo de pago', $book->getSheet(1)->getCell('E3')->getValue());
            $this->assertSame('Valor base ($)', $book->getSheet(1)->getCell('Q3')->getValue());
            $this->assertSame('Empresa mandante', $book->getSheet(1)->getCell('S3')->getValue());
            $headings = array_map(
                fn (string $column): string => (string) $book->getSheet(1)->getCell($column.'3')->getValue(),
                range('A', 'S')
            );
            foreach (['Proceso', 'Servicio', 'Comuna matriz', 'RUT cliente', 'Razón social cliente', 'Condición de pago', 'ID pago trabajado', 'ID movimiento origen', 'Impuesto', '% impuesto', 'Valor impuesto ($)', 'Valor final ($)'] as $removedHeading) {
                $this->assertNotContains($removedHeading, $headings);
            }
            $this->assertSame(3, array_sum(array_map(
                fn (int $row): int => (int) $book->getSheet(1)->getCell('Q'.$row)->getValue(), [4, 5, 6]
            )));
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_boleta_document_uses_the_sii_whole_peso_rounding_and_the_4n_billing_details(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '7220635-5', 'tax_id_number' => '7220635',
            'tax_id_check_digit' => '5', 'legal_name' => 'Esnedi Beroiza',
            'operational_name' => 'Esnedi (Courier Stgo)', 'payment_terms' => 'Contado',
        ]);
        $provider->ocFilenames()->create(['company_code' => '4N', 'service_scope' => 'General', 'file_stem' => 'EsnediBeroiza']);
        ProviderBankAccount::factory()->create([
            'provider_id' => $provider->id, 'bank_name' => 'Banco de Chile',
            'account_type' => 'Cuenta Corriente', 'account_number' => '1234567890',
        ]);
        $this->payment($tenant->id, 'BOLETA-001', 'SI', 707965, null, [
            'provider_id' => $provider->id, 'razon_social_proveedor' => 'Esnedi Beroiza',
            'rut_proveedor' => '7220635-5', 'tipo_documento' => 'Boleta de Honorarios Tercero',
            'empresa_mandante' => '4N', 'service_name' => 'Servicio Fijo Courier',
        ]);
        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('Maestro_Pagos', [
            'seguimiento_paquete' => 'BOLETA-001', 'valor_impuesto' => 107965,
            'valor_final_total' => 600000,
        ]);
        $pdf = $this->get(route('provider-payments.courier-movements.compile.purchase-orders.pdf', '2026080001'))
            ->assertOk();
        $this->assertStringContainsString('2026080001_4N_BOL_EsnediBeroiza.pdf', $pdf->headers->get('content-disposition'));
        $bytes = $pdf->streamedContent();
        $this->assertStringContainsString('4 NORTES LOGISTICA SPA', $bytes);
        $this->assertStringContainsString('RETENCION 15,25%', $bytes);
        $this->assertStringContainsString('CONDICION DE PAGO', $bytes);
        $this->assertStringContainsString('CONTADO', $bytes);
        $this->assertStringContainsString('Esnedi', $bytes);
        $this->assertStringContainsString('Courier Stgo', $bytes);
        $this->assertStringContainsString('$ 600.000', $bytes);
        $this->assertStringContainsString('1234567890', $bytes);
        $this->assertStringContainsString('/Subtype /Image', $bytes);
    }

    public function test_purchase_order_uses_the_payment_terms_of_its_billing_company(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create([
            'tenant_id' => $tenant->id,
            'tax_id' => '77390761-7', 'tax_id_number' => '77390761', 'tax_id_check_digit' => '7',
            'legal_name' => 'TRANSPORTE BAG SPA',
            'payment_terms' => 'Quincena',
            'payment_terms_pmcb' => 'Contado',
        ]);
        $provider->ocFilenames()->create(['company_code' => '4N', 'service_scope' => 'General', 'file_stem' => 'BAG_4N']);
        $provider->ocFilenames()->create(['company_code' => 'PMCB', 'service_scope' => 'General', 'file_stem' => 'BAG_PMCB']);

        foreach (['4N', 'PMCB'] as $company) {
            $this->payment($tenant->id, 'BAG-'.$company, 'SI', 30000, null, [
                'provider_id' => $provider->id,
                'rut_proveedor' => $provider->tax_id,
                'razon_social_proveedor' => 'TRANSPORTE BAG SPA - CONTADO',
                'empresa_mandante' => $company,
            ]);
        }
        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])
            ->assertSessionHas('status');

        $orders = DB::table('Maestro_Pagos')->pluck('oc', 'empresa_mandante');
        $service = app(PurchaseOrderDocument::class);
        $this->assertSame('Quincena', $service->load($tenant->id, $orders['4N'])['payment_terms']);
        $this->assertSame('Contado', $service->load($tenant->id, $orders['PMCB'])['payment_terms']);
        $this->assertSame('TRANSPORTE BAG SPA', $service->load($tenant->id, $orders['4N'])['proveedor']);
        $this->assertDatabaseHas('Maestro_Pagos', [
            'seguimiento_paquete' => 'BAG-4N', 'razon_social_proveedor' => 'TRANSPORTE BAG SPA - CONTADO',
        ]);
        $this->get(route('provider-payments.courier-movements.compile.purchase-orders', ['period' => '202608']))
            ->assertOk()->assertSee('TRANSPORTE BAG SPA')->assertDontSee('TRANSPORTE BAG SPA - CONTADO');
        $this->get(route('provider-payments.courier-movements.compile', ['period' => '202608']))
            ->assertOk()->assertSee('TRANSPORTE BAG SPA')->assertDontSee('TRANSPORTE BAG SPA - CONTADO');

        $response = $this->get(route('provider-payments.courier-movements.compile.purchase-orders.excel', $orders['4N']))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'bag-oc-');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = IOFactory::load($path);
            $this->assertSame('Razón social proveedor: TRANSPORTE BAG SPA', $book->getSheet(0)->getCell('A2')->getValue());
            $this->assertSame('TRANSPORTE BAG SPA', $book->getSheet(1)->getCell('J4')->getValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_purchase_order_pdf_consolidates_services_without_commune_on_one_page(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '11111111-1', 'tax_id_number' => '11111111',
            'tax_id_check_digit' => '1', 'legal_name' => 'Proveedor de prueba',
        ]);
        $provider->ocFilenames()->create(['company_code' => '4N', 'service_scope' => 'General', 'file_stem' => 'ProveedorPrueba']);
        foreach (range(1, 16) as $number) {
            $this->payment($tenant->id, sprintf('MULTI-%02d', $number), 'SI', 1000, null, [
                'service_name' => sprintf('Servicio %02d', $number), 'comuna_destino' => 'Comuna A',
            ]);
        }
        $this->payment($tenant->id, 'MULTI-17', 'SI', 1000, null, [
            'service_name' => 'Servicio 01', 'comuna_destino' => 'Comuna B',
        ]);
        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])
            ->assertSessionHas('status');

        $document = app(PurchaseOrderDocument::class)->load($tenant->id, '2026080001');
        $this->assertCount(16, $document['groups']);
        $bytes = $this->get(route('provider-payments.courier-movements.compile.purchase-orders.pdf', '2026080001'))
            ->assertOk()->streamedContent();
        $this->assertSame(1, substr_count($bytes, '/Type /Page /Parent'));
        $this->assertStringContainsString('Servicio 01', $bytes);
        $this->assertStringContainsString('Servicio 16', $bytes);
        $this->assertStringNotContainsString('Comuna A', $bytes);
        $this->assertStringNotContainsString('Comuna B', $bytes);
        $this->assertStringContainsString('$ 20.230', $bytes);
    }

    public function test_close_rejects_a_document_without_a_tax_rule(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $this->payment($tenant->id, 'KNOWN-TAX', 'SI', 1000);
        $this->payment($tenant->id, 'UNKNOWN-TAX', 'SI', 2000, null, ['tipo_documento' => 'PENDIENTE']);

        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])
            ->assertSessionHasErrors('period');
        $this->assertDatabaseCount('Maestro_Pagos', 0);
        $this->assertDatabaseCount('Cierres_Pagos', 0);
    }

    public function test_close_assigns_purchase_orders_by_supplier_and_the_confirmed_service_exceptions(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $commonDs = [
            'zona' => 'RM', 'razon_social_proveedor' => 'DS GROUP SPA',
            'rut_proveedor' => '77201525-9', 'empresa_mandante' => '4N',
        ];
        $commonJose = [
            'zona' => 'RM', 'razon_social_proveedor' => 'Jose Ignacio Collio Paillao',
            'rut_proveedor' => '17850968-3', 'empresa_mandante' => '4N',
        ];

        $this->payment($tenant->id, 'OC-DS-GENERAL', 'SI', 100, null, $commonDs);
        $this->payment($tenant->id, 'OC-DS-NORTE', 'SI', 780000, null, $commonDs + [
            'nombre_proceso' => 'Acuerdos', 'tipo_pago' => 'Acuerdos', 'service_name' => 'TRONCAL NORTE (LU, MI Y VI)',
        ]);
        $this->payment($tenant->id, 'OC-DS-V', 'SI', 550000, null, $commonDs + [
            'nombre_proceso' => 'Acuerdos', 'tipo_pago' => 'Acuerdos', 'service_name' => 'Fijo Mensual',
        ]);
        $this->payment($tenant->id, 'OC-DS-OTHER-COMPANY', 'SI', 200, null, array_replace($commonDs, ['empresa_mandante' => 'PMBC']));
        $this->payment($tenant->id, 'OC-JOSE-EARLY', 'SI', 60000, null, $commonJose + [
            'nombre_proceso' => 'Servicios', 'tipo_pago' => 'Servicios', 'fecha' => '2026-08-14',
        ]);
        $this->payment($tenant->id, 'OC-JOSE-LATER', 'SI', 60000, null, $commonJose + [
            'nombre_proceso' => 'Servicios', 'tipo_pago' => 'Servicios', 'fecha' => '2026-08-17',
        ]);
        $this->payment($tenant->id, 'OC-JOSE-VARIABLE', 'SI', 500, null, $commonJose);
        $this->payment($tenant->id, 'OC-REGION', 'SI', 1000, null, [
            'zona' => 'Regiones', 'razon_social_proveedor' => 'Proveedor Regional',
            'rut_proveedor' => '12345678-9', 'empresa_mandante' => '4N',
        ]);
        $this->payment($tenant->id, 'OC-UNPAID', 'NO', 1000, null, $commonDs);

        $this->get(route('provider-payments.courier-movements.compile.purchase-orders', ['period' => '202608']))
            ->assertOk()->assertSee('7 órdenes de compra')->assertSee('Troncal Norte')
            ->assertSee('Troncal V')->assertSee('Servicios hasta 14/08/2026');

        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])
            ->assertSessionHas('status');

        $this->get(route('provider-payments.courier-movements.compile.purchase-orders', ['period' => '202608']))
            ->assertOk()->assertSee('Órdenes definitivas guardadas en Maestro_Pagos.');

        $orders = DB::table('Maestro_Pagos')->pluck('oc', 'seguimiento_paquete')->all();
        $this->assertCount(8, $orders);
        $this->assertSame('2026080001', $orders['OC-DS-GENERAL']);
        $this->assertSame('2026080002', $orders['OC-DS-NORTE']);
        $this->assertSame('2026080003', $orders['OC-DS-V']);
        $this->assertSame('2026080004', $orders['OC-DS-OTHER-COMPANY']);
        $this->assertSame('2026080005', $orders['OC-JOSE-LATER']);
        $this->assertSame($orders['OC-JOSE-LATER'], $orders['OC-JOSE-VARIABLE']);
        $this->assertSame('2026080006', $orders['OC-JOSE-EARLY']);
        $this->assertSame('2026080007', $orders['OC-REGION']);
        $this->assertArrayNotHasKey('OC-UNPAID', $orders);
    }

    public function test_september_close_separates_ds_troncales_and_collio_services_through_the_seventeenth(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $ds = [
            'periodo' => '202609', 'zona' => 'Regiones',
            'razon_social_proveedor' => 'DS GROUP SPA', 'rut_proveedor' => '77201525-9',
            'empresa_mandante' => '4N',
        ];
        $collio = [
            'periodo' => '202609', 'zona' => 'RM',
            'razon_social_proveedor' => 'Jose Ignacio Collio Paillao',
            'rut_proveedor' => '17850968-3', 'empresa_mandante' => '4N',
        ];

        $this->payment($tenant->id, 'SEP-DS-GENERAL', 'SI', 100, null, $ds);
        $this->payment($tenant->id, 'SEP-DS-NORTE', 'SI', 720000, null, $ds + [
            'nombre_proceso' => 'Acuerdos', 'service_name' => 'TRONCAL NORTE (LU, MI Y VI)',
        ]);
        $this->payment($tenant->id, 'SEP-DS-V', 'SI', 550000, null, $ds + [
            'nombre_proceso' => 'Acuerdos', 'service_name' => 'Fijo Mensual',
        ]);
        $this->payment($tenant->id, 'SEP-COLLIO-17', 'SI', 60000, null, $collio + [
            'nombre_proceso' => 'Servicios', 'fecha' => '2026-09-17',
        ]);
        $this->payment($tenant->id, 'SEP-COLLIO-18', 'SI', 60000, null, $collio + [
            'nombre_proceso' => 'Servicios', 'fecha' => '2026-09-18',
        ]);
        $this->payment($tenant->id, 'SEP-COLLIO-LANAS', 'SI', 1000, null, $collio + [
            'nombre_proceso' => 'Lanas', 'fecha' => '2026-09-17',
        ]);

        $this->get(route('provider-payments.courier-movements.compile.purchase-orders', ['period' => '202609']))
            ->assertOk()->assertSee('Servicios hasta 17/09/2026');

        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202609'])
            ->assertSessionHas('status');

        $orders = DB::table('Maestro_Pagos')->pluck('oc', 'seguimiento_paquete')->all();
        $this->assertCount(5, array_unique($orders));
        $this->assertSame('2026090001', $orders['SEP-DS-GENERAL']);
        $this->assertSame('2026090002', $orders['SEP-DS-NORTE']);
        $this->assertSame('2026090003', $orders['SEP-DS-V']);
        $this->assertSame('2026090004', $orders['SEP-COLLIO-18']);
        $this->assertSame($orders['SEP-COLLIO-18'], $orders['SEP-COLLIO-LANAS']);
        $this->assertSame('2026090005', $orders['SEP-COLLIO-17']);
        $this->assertSame('RM', DB::table('Maestro_Pagos')->where('seguimiento_paquete', 'SEP-DS-NORTE')->value('zona'));
    }

    public function test_maestro_blocks_future_raw_imports_and_changes_to_closed_payments(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $paid = $this->payment($tenant->id, 'CLOSE-001', 'SI', 12000);
        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])->assertRedirect();
        $otherTenant = Tenant::factory()->create();

        try {
            DB::table('movimientos_courier')->insert([
                'tenant_id' => $otherTenant->id, 'tracking_number' => ' close-001 ',
                'nombre_proceso' => '202609-Variable', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('El seguimiento cerrado no debe cargarse nuevamente.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Maestro_Pagos', $exception->getMessage());
        }

        try {
            DB::table('Pago_Movimientos_Courier')->where('id', $paid->id)->update(['valor' => 1]);
            $this->fail('Un pago cerrado no debe modificarse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Maestro_Pagos', $exception->getMessage());
        }
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['id' => $paid->id, 'valor' => 12000]);
    }

    public function test_new_csv_import_skips_a_tracking_already_in_maestro(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $this->payment($tenant->id, 'CLOSE-001', 'SI', 12000);
        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])->assertRedirect();
        Storage::fake('local');
        Storage::disk('local')->put('courier-imports/after-close.csv', "Seguimiento paquete,Comerciante,Servicio,Comuna,Peso,Estado\nCLOSE-001,Cliente,Normal,Temuco,1,Entregado\nNEW-001,Cliente,Normal,Temuco,1,Entregado\n");

        $this->withSession(['courier_review' => [
            'stored_path' => 'courier-imports/after-close.csv', 'extension' => 'csv', 'file' => 'after-close.csv',
            'process_type' => 'variables', 'missing_columns' => [], 'groups' => [],
        ]])->post(route('provider-payments.courier-movements.store'), [
            'process_year' => 2026, 'process_month' => 9, 'process_name' => '202609-Variable',
        ])->assertOk()->assertSee('Exportar registros ya pagados en Excel');

        $token = session('paid_tracking_reports')[0];
        $response = $this->get(route('provider-payments.courier-movements.paid-report.download', $token))
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'paid-report-');
        try {
            file_put_contents($path, $response->streamedContent());
            $sheet = IOFactory::load($path)->getActiveSheet();
            $this->assertSame('CLOSE-001', (string) $sheet->getCell('C2')->getValue());
            $this->assertSame('202608', (string) $sheet->getCell('E2')->getValue());
            $this->assertSame('YA PAGADO - NO CARGAR', (string) $sheet->getCell('I2')->getValue());
        } finally {
            unlink($path);
        }

        $this->assertSame(1, CourierMovement::query()->where('tenant_id', $tenant->id)->where('tracking_number', 'CLOSE-001')->count());
        $this->assertDatabaseHas('movimientos_courier', ['tenant_id' => $tenant->id, 'tracking_number' => 'NEW-001']);
    }

    public function test_new_import_cannot_use_the_name_of_a_closed_period(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $this->payment($tenant->id, 'CLOSE-001', 'SI', 12000);
        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])->assertRedirect();
        Storage::fake('local');
        Storage::disk('local')->put('courier-imports/wrong-period.csv', "Seguimiento paquete,Comerciante,Servicio,Comuna,Peso,Estado\nNEW-001,Cliente,Normal,Temuco,1,Entregado\n");

        $this->withSession(['courier_review' => [
            'stored_path' => 'courier-imports/wrong-period.csv', 'extension' => 'csv', 'file' => 'wrong-period.csv',
            'process_type' => 'variables', 'missing_columns' => [], 'groups' => [],
        ]])->post(route('provider-payments.courier-movements.store'), [
            'process_year' => 2026, 'process_month' => 9, 'process_name' => '202608-Variable',
        ])->assertSessionHasErrors('process_name');

        $this->assertDatabaseMissing('movimientos_courier', ['tenant_id' => $tenant->id, 'tracking_number' => 'NEW-001']);
    }

    public function test_monthly_close_blocks_unfinished_special_rows_too(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $this->payment($tenant->id, 'CLOSE-001', 'SI', 12000);
        $special = CourierSpecialPayment::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608-Especiales', 'finalized_at' => null,
        ]);
        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])->assertRedirect();

        $this->get(route('provider-payments.courier-movements.especiales', ['periodo' => '202608-Especiales']))
            ->assertOk()->assertSee('El mes tiene cierre definitivo en Maestro_Pagos.')->assertDontSee('Reabrir período');
        $this->delete(route('provider-payments.courier-movements.especiales.destroy', $special->id))
            ->assertSessionHasErrors('period');
        $this->put(route('provider-payments.courier-movements.especiales.associate-page'), [
            'periodo' => '202608-Especiales',
            'rows' => [['id' => $special->id, 'provider_id' => null, 'client_id' => null, 'service_type_id' => null]],
        ])->assertSessionHasErrors('period');
        $this->assertDatabaseHas('courier_special_payments', ['id' => $special->id, 'finalized_at' => null]);
    }

    public function test_purchase_order_summary_excel_sorts_by_payment_terms_and_reconciles_bank_and_tax_amounts(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $first = Provider::factory()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '11111111-1', 'tax_id_number' => '11111111',
            'tax_id_check_digit' => '1', 'legal_name' => 'Proveedor Uno', 'operational_name' => 'Uno',
            'payment_terms' => 'Quincena', 'payment_terms_pmcb' => 'Contado',
        ]);
        ProviderBankAccount::factory()->create([
            'provider_id' => $first->id, 'account_holder_name' => 'Titular Uno',
            'account_holder_tax_id' => '12345678-9', 'bank_name' => 'Banco de Chile',
            'account_type' => 'Cuenta Corriente', 'account_number' => '0012345',
        ]);
        $second = Provider::factory()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '22222222-2', 'tax_id_number' => '22222222',
            'tax_id_check_digit' => '2', 'legal_name' => 'Proveedor Dos', 'operational_name' => 'Dos',
            'payment_terms' => '30 Días',
        ]);

        $this->payment($tenant->id, 'SUMMARY-4N', 'SI', 100, null, ['provider_id' => $first->id]);
        $this->payment($tenant->id, 'SUMMARY-PMCB', 'SI', 400, null, [
            'provider_id' => $first->id, 'empresa_mandante' => 'PMCB', 'tipo_documento' => 'Boleta de Honorarios',
        ]);
        $this->payment($tenant->id, 'SUMMARY-SECOND', 'SI', 500, null, [
            'provider_id' => $second->id, 'rut_proveedor' => $second->tax_id,
            'razon_social_proveedor' => $second->legal_name, 'tipo_documento' => 'Factura Exenta',
        ]);
        $this->post(route('provider-payments.courier-movements.compile.close'), ['period' => '202608'])
            ->assertSessionHas('status');
        $this->get(route('provider-payments.courier-movements.compile.purchase-orders', ['period' => '202608']))
            ->assertOk()->assertSee('Descargar resumen completo en Excel');
        $response = $this->get(route('provider-payments.courier-movements.compile.purchase-orders.summary-excel', ['period' => '202608']))
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('Resumen_OC_202608.xlsx', $response->headers->get('content-disposition'));

        $path = tempnam(sys_get_temp_dir(), 'oc-summary-');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            $this->assertSame('Resumen de OC', $sheet->getTitle());
            $this->assertSame(['OC', 'Nombre de pila proveedor', 'Empresa mandante', 'Condición de pago',
                'Razón social proveedor', 'RUT proveedor', 'Titular de la cuenta', 'RUT del titular',
                'Banco', 'Tipo de cuenta', 'Número de cuenta', 'Tipo de documento',
                'Valor base', 'Impuesto/Retención', 'Valor final'], array_map(
                    fn (string $column): string => (string) $sheet->getCell($column.'4')->getValue(), range('A', 'O')
                ));
            $this->assertSame(['30 Días', 'Contado', 'Quincena'], array_map(
                fn (int $row): string => (string) $sheet->getCell('D'.$row)->getValue(), [5, 6, 7]
            ));
            $this->assertSame('Dos', $sheet->getCell('B5')->getValue());
            $this->assertSame('', (string) $sheet->getCell('K5')->getValue());
            $this->assertSame('PMCB', $sheet->getCell('C6')->getValue());
            $this->assertSame('Titular Uno', $sheet->getCell('G6')->getValue());
            $this->assertSame('12345678-9', $sheet->getCell('H6')->getValue());
            $this->assertSame('0012345', $sheet->getCell('K6')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('K6')->getDataType());
            $this->assertSame(400, $sheet->getCell('M6')->getValue());
            $this->assertSame(-61, $sheet->getCell('N6')->getValue());
            $this->assertSame(339, $sheet->getCell('O6')->getValue());
            $this->assertSame(100, $sheet->getCell('M7')->getValue());
            $this->assertSame(19, $sheet->getCell('N7')->getValue());
            $this->assertSame(119, $sheet->getCell('O7')->getValue());
            $this->assertSame(1000, $sheet->getCell('M8')->getValue());
            $this->assertSame(-42, $sheet->getCell('N8')->getValue());
            $this->assertSame(958, $sheet->getCell('O8')->getValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    private function payment(int $tenantId, string $tracking, string $condition, int $amount, ?string $paymentTracking = null, array $attributes = []): CourierPaymentMovement
    {
        $movement = CourierMovement::query()->create([
            'tenant_id' => $tenantId, 'tracking_number' => $tracking,
            'nombre_proceso' => '202608-Variable', 'tipo_pago' => 'Variable',
        ]);

        return CourierPaymentMovement::query()->create(array_replace([
            'tenant_id' => $tenantId, 'courier_movement_id' => $movement->id,
            'periodo' => '202608', 'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variable',
            'seguimiento_paquete' => $paymentTracking ?? $tracking, 'peso_final' => 1,
            'direccion' => 'Calle de prueba', 'condicion_pago' => $condition, 'valor' => $amount,
            'zona' => 'RM', 'razon_social_proveedor' => 'Proveedor de prueba',
            'rut_proveedor' => '11111111-1', 'empresa_mandante' => '4N', 'tipo_documento' => 'Factura',
        ], $attributes));
    }
}
