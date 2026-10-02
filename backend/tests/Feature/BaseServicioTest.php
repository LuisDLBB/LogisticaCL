<?php

namespace Tests\Feature;

use App\Models\BaseServicio;
use App\Models\Client;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\Provider;
use App\Models\ServiceType;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\ProviderPaymentsWorkflowTestCase;

class BaseServicioTest extends ProviderPaymentsWorkflowTestCase
{
    use RefreshDatabase;

    public function test_excel_import_keeps_duplicate_rows_flags_ambiguous_client_and_allows_review(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $firstClient = Client::query()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '11111111-1', 'tax_id_number' => '11111111', 'tax_id_check_digit' => '1',
            'commercial_name' => 'Cruz Verde', 'legal_name' => 'Farmacias Cruz Verde Spa',
        ]);
        Client::query()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '22222222-2', 'tax_id_number' => '22222222', 'tax_id_check_digit' => '2',
            'commercial_name' => 'Cruz Verde', 'legal_name' => 'Otro cliente con el mismo nombre',
        ]);
        $provider = Provider::factory()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '77390761-7', 'operational_name' => 'Transportes BAG',
            'legal_name' => 'TRANSPORTE BAG SPA - CONTADO',
        ]);
        $source = $this->row(['Rut' => ' 77390761-7', 'Transportista' => 'Bag Quincena']);
        $path = $this->workbook([$source, $source]);

        try {
            $this->post(route('provider-payments.courier-movements.servicios.store'), [
                'file' => new UploadedFile($path, 'Base_Servicios.xlsx', null, null, true),
            ])->assertRedirect()->assertSessionHas('status');
            $this->assertSame(2, BaseServicio::query()->count());
            $this->assertDatabaseHas('PPR_Base_Servicios', [
                'periodo' => '202608', 'nombre_proceso' => '202608-Servicios', 'fecha_carga' => '2026-07-27',
                'provider_id' => $provider->id, 'rut_proveedor' => $provider->tax_id, 'client_id' => null,
            ]);
            $this->get(route('provider-payments.courier-movements.servicios', ['periodo' => '202608']))
                ->assertOk()->assertSee('2 filas encontradas')->assertSee('Selecciona cliente');

            $ids = BaseServicio::query()->orderBy('id')->pluck('id')->all();
            $this->put(route('provider-payments.courier-movements.servicios.associate-page'), [
                'rows' => [
                    $ids[0] => ['client_id' => $firstClient->id, 'provider_id' => $provider->id],
                    $ids[1] => ['client_id' => $firstClient->id, 'provider_id' => $provider->id],
                ],
                'return_periodo' => '202608', 'return_estado' => 'pendientes',
            ])->assertRedirect()->assertSessionHas('status');
            $this->assertSame(2, BaseServicio::query()->where('client_id', $firstClient->id)->count());
            $this->assertSame($firstClient->tax_id, BaseServicio::query()->firstOrFail()->rut_cliente);
            $this->assertSame($provider->legal_name, BaseServicio::query()->firstOrFail()->razon_social_proveedor);
            $this->get(route('provider-payments.courier-movements.servicios', ['periodo' => '202608', 'estado' => 'pendientes']))
                ->assertOk()->assertSee('0 filas encontradas');

            $this->post(route('provider-payments.courier-movements.servicios.store'), [
                'file' => new UploadedFile($path, 'Base_Servicios.xlsx', null, null, true),
            ])->assertSessionHas('status');
            $this->assertSame(2, BaseServicio::query()->count());
            $this->assertSame(2, BaseServicio::query()->where('client_id', $firstClient->id)->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_csv_import_matches_unique_client_and_provider_and_preserves_period_from_file(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $client = Client::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'tax_id_number' => '89807200'],
            ['tax_id' => '89807200-2', 'tax_id_check_digit' => '2',
                'commercial_name' => 'Cruz Verde', 'legal_name' => 'Farmacias Cruz Verde Spa'],
        );
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => '78464442-1']);
        $path = tempnam(sys_get_temp_dir(), 'services-csv-');
        $handle = fopen($path, 'wb');
        fputcsv($handle, $this->headers(), ';');
        fputcsv($handle, $this->row(['Periodo' => 'SEPTIEMBRE 2026 - SERVICIOS', 'Rut' => '78464442-1']), ';');
        fclose($handle);

        try {
            $this->post(route('provider-payments.courier-movements.servicios.store'), [
                'file' => new UploadedFile($path, 'Base_Servicios.csv', 'text/csv', null, true),
            ])->assertRedirect();
            $this->assertDatabaseHas('PPR_Base_Servicios', [
                'periodo' => '202609', 'nombre_proceso' => '202609-Servicios', 'fecha_carga' => '2026-07-27',
                'client_id' => $client->id, 'provider_id' => $provider->id,
            ]);
        } finally {
            @unlink($path);
        }
    }

    public function test_september_calama_service_shows_marcelo_as_associated_provider_and_preserves_excel_origin(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $victor = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => '13013180-8',
            'legal_name' => 'Víctor Robledo', 'operational_name' => 'Victor Robledo (Calama)']);
        $marcelo = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => '13172671-6',
            'legal_name' => 'Marcelo Avendaño', 'operational_name' => 'Marcelo Avendaño (Calama)']);
        $path = $this->workbook([$this->row([
            'Periodo' => 'SEPTIEMBRE 2026 - SERVICIOS', 'Comuna Destino' => 'Calama',
            'Rut' => $victor->tax_id, 'Razon Social' => $victor->legal_name,
        ])]);

        try {
            $this->post(route('provider-payments.courier-movements.servicios.store'), [
                'file' => new UploadedFile($path, 'Base_Servicios.xlsx', null, null, true),
            ])->assertSessionHas('status');
            $row = BaseServicio::query()->where('periodo', '202609')->firstOrFail();
            $this->assertSame($victor->tax_id, $row->rut_proveedor_origen);
            $this->assertSame($marcelo->id, $row->provider_id);
            $this->assertSame($marcelo->tax_id, $row->rut_proveedor);
            $this->get(route('provider-payments.courier-movements.servicios', ['periodo' => '202609']))
                ->assertOk()->assertSee($victor->tax_id)->assertSee($marcelo->operational_name);
            $this->assertDatabaseCount('PPR_Maestro_Pagos', 0);
        } finally {
            @unlink($path);
        }
    }

    public function test_import_accepts_rut_proveedor_header_and_fills_blank_transportista_from_provider(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '78464442-1',
            'legal_name' => 'GRC LOGÍSTICA Y DISTRIBUCIÓN SPA',
            'operational_name' => 'GRC Logística',
        ]);
        $headers = $this->headers();
        $headers[20] = 'Rut proveedor';
        $path = $this->workbook([$this->row([
            'Periodo' => 'SEPTIEMBRE 2026 - SERVICIOS',
            'Rut' => $provider->tax_id, 'Razon Social' => $provider->legal_name,
            'Transportista' => null,
        ])], $headers);

        try {
            $this->post(route('provider-payments.courier-movements.servicios.store'), [
                'file' => new UploadedFile($path, 'Base_Servicios.xlsx', null, null, true),
            ])->assertRedirect()->assertSessionHas('status');
            $row = BaseServicio::query()->where('periodo', '202609')->firstOrFail();
            $this->assertSame($provider->id, $row->provider_id);
            $this->assertSame('GRC Logística', $row->transportista);
            $this->assertSame($provider->tax_id, $row->rut_proveedor_origen);
        } finally {
            @unlink($path);
        }
    }

    public function test_invalid_format_imports_no_rows(): void
    {
        $path = $this->workbook([$this->row()], ['Zona', 'Otra columna']);
        try {
            $this->post(route('provider-payments.courier-movements.servicios.store'), [
                'file' => new UploadedFile($path, 'incorrecto.xlsx', null, null, true),
            ])->assertSessionHasErrors('file');
            $this->assertSame(0, BaseServicio::query()->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_close_requires_associations_and_copies_fixed_services_payments(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $client = Client::query()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '11111111-1', 'tax_id_number' => '11111111',
            'tax_id_check_digit' => '1', 'source_merchant_name' => 'Cliente Pila',
            'commercial_name' => 'Cliente Servicios', 'legal_name' => 'Cliente Servicios SpA',
        ]);
        $provider = Provider::factory()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '22222222-2', 'legal_name' => 'Proveedor Servicios SpA',
            'operational_name' => 'Proveedor Servicios', 'tax_document_type' => 'Factura',
        ]);
        $first = BaseServicio::factory()->create([
            'tenant_id' => $tenant->id, 'client_id' => $client->id, 'provider_id' => $provider->id,
            'fecha_carga' => '2026-07-27', 'fila_origen' => 2, 'usuario' => 'Usuario Servicios',
            'empresa' => 'PMCB', 'peso' => 3, 'valor_final' => 12000,
        ]);
        $second = BaseServicio::factory()->create([
            'tenant_id' => $tenant->id, 'client_id' => null, 'provider_id' => $provider->id,
            'fecha_carga' => '2026-07-27', 'fila_origen' => 3, 'valor_final' => 15000,
        ]);
        CourierMovement::query()->create(['tenant_id' => $tenant->id, 'tracking_number' => 'SVC-20260727-0007']);

        $this->post(route('provider-payments.courier-movements.servicios.close'), ['periodo' => '202608'])
            ->assertSessionHasErrors('periodo');
        $this->assertSame(0, CourierPaymentMovement::query()->count());
        $this->assertNull($first->fresh()->closed_at);

        $this->put(route('provider-payments.courier-movements.servicios.associate-page'), [
            'rows' => [$second->id => ['client_id' => $client->id, 'provider_id' => $provider->id]],
            'return_periodo' => '202608', 'return_estado' => 'pendientes',
        ])->assertSessionHas('status');
        $this->post(route('provider-payments.courier-movements.servicios.close'), ['periodo' => '202608'])
            ->assertSessionHas('status');

        $this->assertNotNull($first->fresh()->closed_at);
        $this->assertNotNull($second->fresh()->closed_at);
        $service = ServiceType::query()->where('service_code', 0)->firstOrFail();
        $this->assertDatabaseHas('PPR_Pago_Movimientos_Courier', [
            'base_servicio_id' => $first->id, 'periodo' => '202608', 'nombre_proceso' => 'Servicios',
            'tipo_pago' => 'Servicios', 'seguimiento_paquete' => 'SVC-20260727-0008',
            'zona' => 'RM', 'comuna_matriz' => null, 'comuna_destino' => 'Santiago', 'comerciante_pila' => 'Cliente Pila',
            'client_id' => $client->id, 'rut_cliente' => $client->tax_id, 'razon_social_cliente' => $client->legal_name,
            'service_type_id' => $service->id, 'service_code' => 0, 'service_name' => $service->name,
            'peso_final' => 3, 'estado_envio' => 'Entregado', 'condicion_pago' => 'SI',
            'provider_id' => $provider->id, 'rut_proveedor' => $provider->tax_id,
            'razon_social_proveedor' => $provider->legal_name, 'nombre_operacional' => $provider->operational_name,
            'nombre_repartidor' => 'Usuario Servicios', 'usuario_entrega' => 'Usuario Servicios',
            'empresa_mandante' => 'PMCB', 'valor' => 12000,
        ]);
        $payment = CourierPaymentMovement::query()->where('base_servicio_id', $first->id)->firstOrFail();
        $this->assertSame('2026-07-27', $payment->fecha->toDateString());
        $this->assertSame('Servicios - Transporte', $payment->direccion);
        $this->assertSame('SVC-20260727-0009', CourierPaymentMovement::query()->where('base_servicio_id', $second->id)->value('seguimiento_paquete'));
        $this->assertSame('Servicios', CourierMovement::query()->findOrFail($payment->courier_movement_id)->source_system);
        $this->get(route('provider-payments.courier-movements.servicios', ['periodo' => '202608', 'estado' => 'todos']))
            ->assertOk()->assertSee('Período 202608 cerrado')->assertDontSee('Guardar asociaciones de esta página');
        $this->get(route('provider-payments.movements.index', ['period' => '202608', 'process' => 'Servicios']))
            ->assertOk()->assertSee('Proveedor Servicios')->assertDontSee('Sin proveedor asociado');
        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202608'])->assertRedirect();
        $this->assertSame(12000, $payment->fresh()->valor);
    }

    public function test_closed_period_rejects_changes_and_master_key_reopens_only_its_payments(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $client = Client::query()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '33333333-3', 'tax_id_number' => '33333333',
            'tax_id_check_digit' => '3', 'commercial_name' => 'Cliente Servicios',
            'legal_name' => 'Cliente Servicios SpA',
        ]);
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id]);
        $row = BaseServicio::factory()->create([
            'tenant_id' => $tenant->id, 'client_id' => $client->id, 'provider_id' => $provider->id,
        ]);
        $unrelated = CourierMovement::query()->create([
            'tenant_id' => $tenant->id, 'tracking_number' => '4N202608010001-111',
            'nombre_proceso' => '202608-Variable',
        ]);
        CourierPaymentMovement::query()->create([
            'tenant_id' => $tenant->id, 'courier_movement_id' => $unrelated->id,
            'periodo' => '202608', 'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variable', 'peso_final' => 1,
        ]);
        $this->post(route('provider-payments.courier-movements.servicios.close'), ['periodo' => '202608'])
            ->assertSessionHas('status');
        $this->put(route('provider-payments.courier-movements.servicios.associate-page'), [
            'rows' => [$row->id => ['client_id' => null, 'provider_id' => $provider->id]],
            'return_periodo' => '202608',
        ])->assertSessionHasErrors('rows');
        $this->assertSame($client->id, $row->fresh()->client_id);

        $path = $this->workbook([$this->row()]);
        try {
            $this->post(route('provider-payments.courier-movements.servicios.store'), [
                'file' => new UploadedFile($path, 'Base_Servicios.xlsx', null, null, true),
            ])->assertSessionHasErrors('file');
            $this->assertSame(1, BaseServicio::query()->count());
        } finally {
            @unlink($path);
        }

        config()->set('provider-payments.process_deletion_key', 'test-master-key');
        $this->post(route('provider-payments.courier-movements.servicios.reopen'), [
            'periodo' => '202608', 'password' => 'incorrecta',
        ])->assertSessionHasErrors('password');
        $this->assertNotNull($row->fresh()->closed_at);
        $this->post(route('provider-payments.courier-movements.servicios.reopen'), [
            'periodo' => '202608', 'password' => 'test-master-key',
        ])->assertSessionHas('status');
        $this->assertNull($row->fresh()->closed_at);
        $this->assertSame(0, CourierPaymentMovement::query()->whereNotNull('base_servicio_id')->count());
        $this->assertSame(0, CourierMovement::query()->where('source_system', 'Servicios')->count());
        $this->assertSame(1, CourierPaymentMovement::query()->where('tipo_pago', 'Variable')->count());
        $this->assertSame(1, CourierMovement::query()->where('nombre_proceso', '202608-Variable')->count());

        $this->put(route('provider-payments.courier-movements.servicios.associate-page'), [
            'rows' => [$row->id => [
                'client_id' => $client->id, 'provider_id' => $provider->id,
                'valor_final' => 18000, 'direccion' => 'Dirección corregida',
            ]],
            'return_periodo' => '202608',
        ])->assertSessionHas('status');
        $this->assertSame('Dirección corregida', $row->fresh()->direccion);
        $this->post(route('provider-payments.courier-movements.servicios.close'), ['periodo' => '202608'])
            ->assertSessionHas('status');
        $this->assertSame(18000, CourierPaymentMovement::query()->where('base_servicio_id', $row->id)->value('valor'));
        $this->assertSame('Dirección corregida', CourierPaymentMovement::query()->where('base_servicio_id', $row->id)->firstOrFail()->direccion);
        $this->delete(route('provider-payments.movements.processes.destroy'), [
            'process_name' => '202608-Servicios', 'password' => 'test-master-key',
        ])->assertRedirect(route('provider-payments.courier-movements.servicios', ['periodo' => '202608', 'estado' => 'todos']));
        $this->assertNull($row->fresh()->closed_at);
        $this->assertSame(1, CourierPaymentMovement::query()->where('tipo_pago', 'Variable')->count());
    }

    /** @return list<string> */
    private function headers(): array
    {
        return ['Zona', 'tipo de Pago', 'Seguimiento paquete', 'Fecha Carga', 'Dirección', 'Numero destino',
            'Depto destino', 'Comuna Destino', 'Razón Social Cliente', 'Rut Cliente', 'SERVICIO', 'Peso',
            'estado del envio', 'Valor final', 'Operador', 'Usuario', 'Periodo', 'Usuario2',
            'Transportista', 'Razon Social', 'Rut', 'Empresa'];
    }

    /** @param array<string, mixed> $overrides
     * @return list<mixed>
     */
    private function row(array $overrides = []): array
    {
        $values = ['RM', 'Servicios', null, '2026-07-27', 'Servicios - Transporte', null, null,
            'Santiago', 'Cruz Verde', null, 'Servicios 4N', 1, 'Entregado', 10000,
            '4N RM', 'Repartidor', 'AGOSTO 2026 - SERVICIOS', 'Repartidor', 'Bag', 'Bag SpA', null, '4N'];
        foreach ($overrides as $name => $value) {
            $values[array_search($name, $this->headers(), true)] = $value;
        }

        return $values;
    }

    /** @param list<list<mixed>> $rows
     * @param  list<string>|null  $headers
     */
    private function workbook(array $rows, ?array $headers = null): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers ?? $this->headers(), null, 'A1');
        $sheet->fromArray($rows, null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'services-xlsx-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
