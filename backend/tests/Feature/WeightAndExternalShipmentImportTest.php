<?php

namespace Tests\Feature;

use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\RealWeight;
use App\Models\Tenant;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class WeightAndExternalShipmentImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_real_weight_excel_preserves_source_client_and_uses_courier_identity(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $tracking = '4N202607017133-616';
        CourierMovement::create([
            'tenant_id' => $tenant->id, 'tracking_number' => $tracking,
            'nombre_proceso' => '202607-Variable', 'merchant_name' => 'Revesderecho',
            'service_name' => 'Servicio Standar',
        ]);
        $path = $this->workbook(
            ['Fecha', 'Seguimiento_Paquete', 'Codigo_seguimiento', 'Peso_Real', 'Cliente', 'Operario', 'Observacion', 'GuiaCliente'],
            [['01-07-2026', $tracking, '4N202607017133', 13, 'Revesderecho Retail', 'JP.Soza', '', 'N/A']],
        );

        try {
            $this->post(route('provider-payments.maintainers.pesos.reales.import'), [
                'file' => new UploadedFile($path, 'Peso_Real.xlsx', null, null, true),
            ])->assertRedirect()->assertSessionHas('status');
            $this->assertDatabaseHas('peso_real', [
                'seguimiento_paquete' => $tracking, 'peso_real' => 13, 'fecha_proceso' => '2026-07-01',
                'cliente_origen' => 'Revesderecho Retail', 'comerciante' => 'Revesderecho',
                'servicio' => 'Servicio Standar', 'operario' => 'JP.Soza', 'guia_cliente' => 'N/A',
            ]);
            $this->get(route('provider-payments.maintainers.pesos.reales', ['period' => '2026-07']))
                ->assertOk()->assertSee('JP.Soza')->assertSee('Revesderecho Retail');
        } finally {
            unlink($path);
        }
    }

    public function test_real_weight_excel_accepts_native_excel_dates(): void
    {
        $tracking = '4N202609010001-001';
        $path = $this->workbook(
            ['Fecha', 'Seguimiento_Paquete', 'Codigo_seguimiento', 'Peso_Real', 'Cliente', 'Operario', 'Observacion', 'GuiaCliente'],
            [[ExcelDate::PHPToExcel(new DateTimeImmutable('2026-09-01')), $tracking, '4N202609010001', 'XL', 'Cliente', 'Operario', '', 'N/A']],
            true,
        );

        try {
            $this->post(route('provider-payments.maintainers.pesos.reales.import'),
                ['file' => new UploadedFile($path, 'pesos.xlsx', null, null, true)])->assertSessionHas('status');
            $this->assertDatabaseHas('peso_real', ['seguimiento_paquete' => $tracking,
                'fecha_proceso' => '2026-09-01', 'talla' => 'XL', 'peso_real' => 30]);
        } finally {
            unlink($path);
        }
    }

    public function test_real_weight_csv_reports_invalid_fields_and_uses_newest_duplicate(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'peso-real-csv-');
        $header = 'Fecha;Seguimiento_Paquete;Codigo_seguimiento;Peso_Real;Cliente;Operario;Observacion;GuiaCliente';
        $rows = [
            '01-09-2026;4N202609010001-001;4N202609010001;S;Cliente;Operario;;N/A',
            '02-09-2026;4N202609010001-001;4N202609010001;M;Cliente;Operario;;N/A',
            '20/8/20206;4N202609010002-002;4N202609010002;1;Cliente;Operario;;N/A',
            '01-09-2026;#VALUE!;#VALUE!;2;Cliente;Operario;;N/A',
            '01-09-2026;4N202609010003-003;4N202609010003;Sin peso;Cliente;Operario;;N/A',
        ];
        file_put_contents($path, "\xEF\xBB\xBF".$header."\n".implode("\n", $rows));

        try {
            $this->post(route('provider-payments.maintainers.pesos.reales.import'),
                ['file' => new UploadedFile($path, 'pesos.csv', 'text/csv', null, true)])
                ->assertSessionHas('status', fn (string $status): bool => str_contains($status, '2 nuevos') && str_contains($status, '2 filas con errores omitidas'))
                ->assertSessionHas('import_issues');
            $this->assertDatabaseHas('peso_real', ['seguimiento_paquete' => '4N202609010001-001',
                'fecha_proceso' => '2026-09-02', 'talla' => 'M', 'peso_real' => 6]);
            $this->assertDatabaseHas('peso_real', ['seguimiento_paquete' => '4N202609010003-003',
                'talla' => 'Error-Sin peso', 'peso_real' => null]);
            $this->assertDatabaseMissing('peso_real', ['seguimiento_paquete' => '4N202609010002-002']);
            $this->get(route('provider-payments.maintainers.pesos.reales', ['period' => '2026-09']))
                ->assertOk()->assertSee('Revisar formato de fecha')->assertSee('20/8/20206')
                ->assertSee('#VALUE!')->assertSee('Texto no reconocido');
            $this->get(route('provider-payments.maintainers.pesos.reales', ['period' => '2026-09']))
                ->assertOk()->assertSee('20/8/20206');
        } finally {
            unlink($path);
        }
    }

    public function test_real_weight_csv_with_only_bad_dates_shows_consolidated_error_without_saving(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'peso-real-csv-');
        file_put_contents($path, "Fecha;Seguimiento_Paquete;Codigo_seguimiento;Peso_Real;Cliente;Operario;Observacion;GuiaCliente\n"
            ."20/8/20206;4N202608200001-001;4N202608200001;S;Cliente;Operario;;N/A\n"
            ."20/8/20206;4N202608200002-002;4N202608200002;M;Cliente;Operario;;N/A\n");

        try {
            $this->post(route('provider-payments.maintainers.pesos.reales.import'),
                ['file' => new UploadedFile($path, 'pesos.csv', 'text/csv', null, true)])
                ->assertSessionHas('status', fn (string $status): bool => str_contains($status, '2 filas con errores omitidas'));
            $this->assertDatabaseCount('peso_real', 0);
            $this->get(route('provider-payments.maintainers.pesos.reales'))
                ->assertOk()->assertSee('20/8/20206')->assertSee('Revisar formato de fecha')->assertSee('2', false);
        } finally {
            unlink($path);
        }
    }

    public function test_real_weight_import_converts_sizes_truncates_decimals_and_flags_unknown_text(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $values = ['XS' => 1, 's' => 4, 'M' => 6, 'L' => 10, 'XL' => 30, 'XXL' => 36,
            '11,9' => 11, '8.5' => 8, '' => null, 'Sin peso' => null];
        $rows = [];
        foreach ($values as $raw => $expected) {
            $tracking = '4N20260901'.str_pad((string) count($rows), 4, '0', STR_PAD_LEFT);
            $rows[] = ['01-09-2026', $tracking, $tracking, $raw, 'Cliente', 'Operario', '', 'N/A'];
        }
        $path = $this->workbook(
            ['Fecha', 'Seguimiento_Paquete', 'Codigo_seguimiento', 'Peso_Real', 'Cliente', 'Operario', 'Observacion', 'GuiaCliente'],
            $rows,
        );

        try {
            $this->post(route('provider-payments.maintainers.pesos.reales.import'),
                ['file' => new UploadedFile($path, 'pesos.xlsx', null, null, true)])->assertSessionHas('status');

            foreach (array_values($values) as $index => $expected) {
                $this->assertDatabaseHas('peso_real', [
                    'tenant_id' => $tenant->id,
                    'seguimiento_paquete' => $rows[$index][1],
                    'peso_real' => $expected,
                    'talla' => $index < 6 ? strtoupper((string) $rows[$index][3]) : ($index === 9 ? 'Error-Sin peso' : null),
                ]);
            }
            $this->get(route('provider-payments.maintainers.pesos.reales', ['period' => '2026-09']))
                ->assertOk()->assertSee('Error-Sin peso')->assertSee('XXL');
        } finally {
            unlink($path);
        }
    }

    public function test_unknown_weight_replaces_open_weight_without_turning_missing_value_into_zero(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $tracking = '4N202609010001';
        CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => $tracking,
            'nombre_proceso' => '202609-Variable', 'peso_real' => 7, 'peso_transformado' => 12]);
        $path = $this->workbook(
            ['Fecha', 'Seguimiento_Paquete', 'Codigo_seguimiento', 'Peso_Real', 'Cliente', 'Operario', 'Observacion', 'GuiaCliente'],
            [['01-09-2026', $tracking, $tracking, 'Pendiente', 'Cliente', 'Operario', '', 'N/A']],
        );

        try {
            $this->post(route('provider-payments.maintainers.pesos.reales.import'),
                ['file' => new UploadedFile($path, 'pesos.xlsx', null, null, true)])->assertSessionHas('status');
            $this->post(route('provider-payments.maintainers.pesos.reales.sync'))->assertSessionHas('status');
            $this->assertDatabaseHas('peso_real', ['seguimiento_paquete' => $tracking,
                'talla' => 'Error-Pendiente', 'peso_real' => null]);
            $this->assertDatabaseHas('movimientos_courier', ['tracking_number' => $tracking,
                'peso_real' => null, 'peso_final' => 12]);
        } finally {
            unlink($path);
        }
    }

    public function test_real_weight_excel_updates_open_rows_but_keeps_closed_period_intact(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $tracking = '4N202607017133-616';
        $headers = ['Fecha', 'Seguimiento_Paquete', 'Codigo_seguimiento', 'Peso_Real', 'Cliente', 'Operario', 'Observacion', 'GuiaCliente'];
        $first = $this->workbook($headers, [['01-07-2026', $tracking, '4N202607017133', 13, 'Referencia', 'JP.Soza', '', 'N/A']]);
        $second = $this->workbook($headers, [['01-07-2026', $tracking, '4N202607017133', 15, 'Referencia', 'Operario 2', 'Corregido', 'N/A']]);

        try {
            $this->post(route('provider-payments.maintainers.pesos.reales.import'), ['file' => new UploadedFile($first, 'peso.xlsx', null, null, true)])->assertSessionHas('status');
            $this->post(route('provider-payments.maintainers.pesos.reales.import'), ['file' => new UploadedFile($second, 'peso.xlsx', null, null, true)])->assertSessionHas('status');
            $this->assertDatabaseHas('peso_real', ['seguimiento_paquete' => $tracking, 'peso_real' => 15, 'operario' => 'Operario 2']);
            DB::table('Cierres_Pagos')->insert(['tenant_id' => $tenant->id, 'periodo' => '202607', 'registros' => 0, 'total' => 0, 'closed_at' => now()]);
            $this->post(route('provider-payments.maintainers.pesos.reales.import'), ['file' => new UploadedFile($first, 'peso.xlsx', null, null, true)])->assertSessionHas('status', fn (string $status): bool => str_contains($status, '1 de períodos cerrados'));
            $this->assertDatabaseHas('peso_real', ['seguimiento_paquete' => $tracking, 'peso_real' => 15]);
        } finally {
            unlink($first);
            unlink($second);
        }
    }

    public function test_real_weight_from_august_can_update_an_open_september_process(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $tracking = '4N202608017133-616';
        CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => $tracking,
            'nombre_proceso' => '202609-Variable', 'peso_real' => 2, 'peso_transformado' => 4]);
        DB::table('Cierres_Pagos')->insert(['tenant_id' => $tenant->id, 'periodo' => '202608',
            'registros' => 0, 'total' => 0, 'closed_at' => now()]);
        $path = $this->workbook(
            ['Fecha', 'Seguimiento_Paquete', 'Codigo_seguimiento', 'Peso_Real', 'Cliente', 'Operario', 'Observacion', 'GuiaCliente'],
            [['01-08-2026', $tracking, '4N202608017133', 13, 'Referencia', 'JP.Soza', '', 'N/A']],
        );

        try {
            $this->post(route('provider-payments.maintainers.pesos.reales.import'),
                ['file' => new UploadedFile($path, 'peso.xlsx', null, null, true)])
                ->assertSessionHas('status', fn (string $status): bool => str_contains($status, '1 nuevos'));
            $this->post(route('provider-payments.maintainers.pesos.reales.sync'))->assertRedirect();
            $this->assertDatabaseHas('movimientos_courier', ['tracking_number' => $tracking,
                'nombre_proceso' => '202609-Variable', 'peso_real' => 13, 'peso_final' => 13]);
        } finally {
            unlink($path);
        }
    }

    public function test_paid_tracking_cannot_be_reloaded_as_real_weight_or_external_shipment(): void
    {
        Storage::fake('local');
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $tracking = '4N202608030001-999';
        DB::table('Maestro_Pagos')->insert([
            'seguimiento_paquete' => $tracking, 'pago_movimiento_id' => 999,
            'tenant_id' => $tenant->id, 'courier_movement_id' => 999,
            'tipo_pago' => 'Variable', 'nombre_proceso' => 'Variable', 'periodo' => '202608',
            'peso_final' => 1, 'condicion_pago' => 'SI', 'valor' => 1000,
            'razon_social_proveedor' => 'Proveedor pagado', 'oc' => '2026080001', 'closed_at' => now(),
        ]);
        $weight = $this->workbook(
            ['Fecha', 'Seguimiento_Paquete', 'Codigo_seguimiento', 'Peso_Real', 'Cliente', 'Operario', 'Observacion', 'GuiaCliente'],
            [['03-08-2026', $tracking, '4N202608030001', 12, 'Cliente', 'Operario', '', 'N/A']],
        );
        $external = $this->workbook(
            ['Fecha', 'ID', 'OS Blue', 'Localidad Destino', 'Punto entrega', 'Cliente', 'Observacion'],
            [['03-08-2026', $tracking, '123', 'Quemchi', 'Domicilio', 'Cliente', '']],
        );

        try {
            $this->post(route('provider-payments.maintainers.pesos.reales.import'), ['file' => new UploadedFile($weight, 'peso.xlsx', null, null, true)])
                ->assertSessionHas('status', fn (string $status): bool => str_contains($status, '1 ya pagados'));
            $this->post(route('provider-payments.courier-movements.externos.import'), ['file' => new UploadedFile($external, 'externos.xlsx', null, null, true)])
                ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'ya pagados: 1'))
                ->assertSessionHas('paid_report_token');
            $token = session('paid_report_token');
            $this->assertNotEmpty($token);
            Storage::disk('local')->assertExists('paid-external-shipment-reports/'.$token.'.json');
            $download = $this->get(route('provider-payments.courier-movements.externos.paid-report', $token))
                ->assertOk()->assertDownload('Envios_Externos_Ya_Pagados_'.$token.'.xlsx');
            $reportPath = tempnam(sys_get_temp_dir(), 'paid-externals-');
            file_put_contents($reportPath, $download->streamedContent());
            $sheet = IOFactory::load($reportPath)->getActiveSheet();
            $this->assertSame($tracking, $sheet->getCell('D2')->getFormattedValue());
            $this->assertSame('Proveedor pagado', $sheet->getCell('J2')->getFormattedValue());
            $this->assertEquals(1000, $sheet->getCell('L2')->getValue());
            unlink($reportPath);
            $this->get(route('provider-payments.courier-movements.externos'))
                ->assertOk()->assertSee('1 envíos externos ya figuran en Maestro Pagos');
            $historical = $this->get(route('provider-payments.courier-movements.externos.paid-existing-report'))
                ->assertOk()->assertDownload('Envios_Externos_Ya_Pagados_Historico.xlsx');
            $reportPath = tempnam(sys_get_temp_dir(), 'paid-externals-history-');
            file_put_contents($reportPath, $historical->streamedContent());
            $historicalSheet = IOFactory::load($reportPath)->getActiveSheet();
            $this->assertSame($tracking, $historicalSheet->getCell('D2')->getFormattedValue());
            unlink($reportPath);
            $this->assertDatabaseMissing('peso_real', ['seguimiento_paquete' => $tracking]);
            $this->assertDatabaseHas('envios_externos', ['tracking_number' => $tracking, 'external_order_number' => '123']);
        } finally {
            unlink($weight);
            unlink($external);
        }
    }

    public function test_real_weight_sync_skips_movements_from_closed_periods(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $tracking = '4N202608050001-111';
        RealWeight::create(['tenant_id' => $tenant->id, 'seguimiento_paquete' => $tracking,
            'codigo_seguimiento' => '4N202608050001', 'fecha_proceso' => '2026-08-05',
            'peso_real' => 8, 'comerciante' => 'Cliente', 'servicio' => 'Servicio']);
        CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => $tracking,
            'nombre_proceso' => '202608-Variable', 'peso_real' => 3, 'peso_transformado' => 5]);
        DB::table('Cierres_Pagos')->insert(['tenant_id' => $tenant->id, 'periodo' => '202608',
            'registros' => 0, 'total' => 0, 'closed_at' => now()]);

        $this->post(route('provider-payments.maintainers.pesos.reales.sync'))->assertSessionHas('status',
            fn (string $status): bool => str_contains($status, '1 registros pagados o de períodos cerrados quedaron intactos'));
        $this->assertDatabaseHas('movimientos_courier', ['tracking_number' => $tracking, 'peso_real' => 3, 'peso_final' => 3]);
    }

    public function test_external_shipment_excel_excludes_existing_and_future_payments(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $existingTracking = '4N202607245212-865';
        $futureTracking = '4N202607264940-636';
        $movement = CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => $existingTracking, 'nombre_proceso' => '202609-Variable']);
        CourierPaymentMovement::create([
            'tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id,
            'periodo' => '202609', 'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variables',
            'seguimiento_paquete' => $existingTracking, 'peso_final' => 1,
            'condicion_pago' => 'SI', 'valor' => 1500,
        ]);
        DB::table('Cierres_Pagos')->insert(['tenant_id' => $tenant->id, 'periodo' => '202608',
            'registros' => 0, 'total' => 0, 'closed_at' => now()]);
        $path = $this->workbook(
            ['Fecha', 'ID', 'OS Blue', 'Localidad Destino', 'Punto entrega', 'Cliente', 'Observacion'],
            [
                ['03-08-2026', $existingTracking, '2362906361', 'Quemchi', 'Domicilio', 'RD ECOMM', 'sale desde Castro'],
                ['03-08-2026', $futureTracking, '2362914271', 'Futaleufu', 'Domicilio', 'RD ECOMM', ''],
            ],
        );

        try {
            $this->post(route('provider-payments.courier-movements.externos.import'), [
                'file' => new UploadedFile($path, 'Envios_Externos.xlsx', null, null, true),
            ])->assertRedirect()->assertSessionHas('status',
                fn (string $status): bool => str_contains($status, 'con período cerrado: 0'));
            $this->assertDatabaseHas('envios_externos', [
                'tracking_number' => $existingTracking, 'external_order_number' => '2362906361',
                'observacion' => 'sale desde Castro', 'exclude_provider_payment' => true,
            ]);
            $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => $existingTracking, 'condicion_pago' => 'NO', 'valor' => 0]);
            CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => $futureTracking, 'nombre_proceso' => '202609-Variable', 'tipo_pago' => 'Variables']);
            $this->post(route('provider-payments.courier-movements.compile.store'), [
                'period' => '202609', 'processes' => ['Variable'],
            ])->assertRedirect();
            $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => $futureTracking, 'condicion_pago' => 'NO', 'valor' => 0]);
            $this->get(route('provider-payments.courier-movements.externos', ['period' => '2026-08']))
                ->assertOk()->assertSee('Quemchi')->assertSee('Futaleufu');
        } finally {
            unlink($path);
        }
    }

    public function test_external_shipment_reupload_identifies_unchanged_rows_and_accepts_native_excel_dates(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $tracking = '4N202609030001-001';
        $path = $this->workbook(
            ['Fecha', 'ID', 'OS Blue', 'Localidad Destino', 'Punto entrega', 'Cliente', 'Observacion'],
            [[ExcelDate::PHPToExcel(new DateTimeImmutable('2026-09-03')), $tracking, '2362906361', 'Quemchi', 'Domicilio', 'RD ECOMM', 'Nota']],
            true,
        );

        try {
            $this->post(route('provider-payments.courier-movements.externos.import'), [
                'file' => new UploadedFile($path, 'Base-Externos.xlsx', null, null, true),
            ])->assertSessionHas('status', fn (string $status): bool => str_contains($status, '1 nuevos'));

            $movement = CourierMovement::create(['tenant_id' => $tenant->id,
                'tracking_number' => $tracking, 'nombre_proceso' => '202609-Variable']);
            CourierPaymentMovement::create(['tenant_id' => $tenant->id,
                'courier_movement_id' => $movement->id, 'seguimiento_paquete' => $tracking,
                'periodo' => '202609', 'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variables',
                'peso_final' => 1, 'condicion_pago' => 'SI', 'valor' => 1500]);

            $this->post(route('provider-payments.courier-movements.externos.import'), [
                'file' => new UploadedFile($path, 'Base-Externos.xlsx', null, null, true),
            ])->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'ya estaba cargada')
                && str_contains($status, '1 envíos coinciden')
                && str_contains($status, 'Pagos activos marcados NO y Valor $ 0: 1'));

            $this->assertDatabaseCount('envios_externos', 1);
            $this->assertDatabaseHas('envios_externos', ['tracking_number' => $tracking, 'fecha' => '2026-09-03']);
            $this->assertDatabaseHas('Pago_Movimientos_Courier', [
                'seguimiento_paquete' => $tracking, 'condicion_pago' => 'NO', 'valor' => 0,
            ]);
        } finally {
            unlink($path);
        }
    }

    public function test_external_shipment_import_saves_valid_rows_and_reports_every_problematic_row(): void
    {
        Storage::fake('local');
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $tracking = '4N202609030001-001';
        $validTracking = '4N202609030002-002';
        $movement = CourierMovement::create(['tenant_id' => $tenant->id,
            'tracking_number' => $validTracking, 'nombre_proceso' => '202609-Variable']);
        CourierPaymentMovement::create(['tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id,
            'seguimiento_paquete' => $validTracking, 'periodo' => '202609',
            'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variables',
            'peso_final' => 1, 'condicion_pago' => 'SI', 'valor' => 1500]);
        $path = $this->workbook(
            ['Fecha', 'ID', 'OS Blue', 'Localidad Destino', 'Punto entrega', 'Cliente', 'Observacion'],
            [
                ['03-09-2026', $tracking, '123', 'Quemchi', 'Domicilio', 'Cliente', ''],
                ['03-09-2026', $tracking, '124', 'Futaleufu', 'Domicilio', 'Cliente', ''],
                ['03-09-2026', '', '125', 'Pica', 'Domicilio', 'Cliente', ''],
                ['03-09-2026', $validTracking, '126', 'Santiago', 'Domicilio', 'Cliente', ''],
                ['fecha incorrecta', '4N202609030003-003', '127', 'Pica', 'Domicilio', 'Cliente', ''],
            ],
        );

        try {
            $this->post(route('provider-payments.courier-movements.externos.import'), [
                'file' => new UploadedFile($path, 'Base-Externos.xlsx', null, null, true),
            ])->assertSessionHas('status', fn (string $status): bool => str_contains($status, '1 nuevos')
                && str_contains($status, '4 filas con problemas quedaron pendientes'))
                ->assertSessionHas('external_issue_report_token');
            $this->assertDatabaseCount('envios_externos', 1);
            $this->assertDatabaseHas('envios_externos', ['tracking_number' => $validTracking]);
            $this->assertDatabaseMissing('envios_externos', ['tracking_number' => $tracking]);
            $this->assertDatabaseHas('Pago_Movimientos_Courier', [
                'seguimiento_paquete' => $validTracking, 'condicion_pago' => 'NO', 'valor' => 0,
            ]);
            $token = session('external_issue_report_token');
            Storage::disk('local')->assertExists('external-shipment-issue-reports/'.$token.'.json');
            $response = $this->get(route('provider-payments.courier-movements.externos.issues', $token))
                ->assertOk()->assertDownload('Envios_Externos_Pendientes_'.$token.'.xlsx');
            $reportPath = tempnam(sys_get_temp_dir(), 'externos-issues-');
            file_put_contents($reportPath, $response->streamedContent());
            $sheet = IOFactory::load($reportPath)->getActiveSheet();
            $this->assertSame(5, $sheet->getHighestRow());
            $this->assertStringContainsString('ID repetido', $sheet->getCell('C2')->getFormattedValue());
            $this->assertStringContainsString('Falta ID', $sheet->getCell('C4')->getFormattedValue());
            $this->assertStringContainsString('Fecha inválida', $sheet->getCell('C5')->getFormattedValue());
            unlink($reportPath);
        } finally {
            unlink($path);
        }
    }

    public function test_external_reference_can_be_loaded_for_closed_period_without_changing_payment(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $tracking = '4N202607264940-636';
        $movement = CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => $tracking, 'nombre_proceso' => '202608-Variable']);
        CourierPaymentMovement::create([
            'tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id,
            'periodo' => '202608', 'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variables',
            'seguimiento_paquete' => $tracking, 'peso_final' => 1, 'condicion_pago' => 'NO',
        ]);
        DB::table('Cierres_Pagos')->insert(['tenant_id' => $tenant->id, 'periodo' => '202608',
            'registros' => 0, 'total' => 0, 'closed_at' => now()]);
        $path = $this->workbook(
            ['Fecha', 'ID', 'OS Blue', 'Localidad Destino', 'Punto entrega', 'Cliente', 'Observacion'],
            [['03-08-2026', $tracking, '2362914271', 'Futaleufu', 'Domicilio', 'RD ECOMM', 'Nota histórica']],
        );

        try {
            $this->post(route('provider-payments.courier-movements.externos.import'), ['file' => new UploadedFile($path, 'externos.xlsx', null, null, true)])
                ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'con período cerrado: 1'));
            $this->assertDatabaseHas('envios_externos', ['tracking_number' => $tracking, 'observacion' => 'Nota histórica']);
            $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => $tracking, 'condicion_pago' => 'NO', 'valor' => null]);
        } finally {
            unlink($path);
        }
    }

    /** @param list<string> $headers
     * @param  list<list<mixed>>  $rows
     */
    private function workbook(array $headers, array $rows, bool $excelDate = false): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');
        if ($excelDate) {
            $sheet->getStyle('A2:A'.($sheet->getHighestRow()))->getNumberFormat()->setFormatCode('dd-mm-yyyy');
        }
        $path = tempnam(sys_get_temp_dir(), 'upload-xlsx-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
