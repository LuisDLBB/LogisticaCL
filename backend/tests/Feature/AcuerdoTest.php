<?php

namespace Tests\Feature;

use App\Models\Acuerdo;
use App\Models\AcuerdoCalendarDay;
use App\Models\AcuerdoServiceRule;
use App\Models\Client;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\Coverage;
use App\Models\Provider;
use App\Models\Tenant;
use App\Modules\ProviderPayments\Services\AcuerdoManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\ProviderPaymentsWorkflowTestCase;

class AcuerdoTest extends ProviderPaymentsWorkflowTestCase
{
    use RefreshDatabase;

    public function test_generating_september_agreement_changes_calama_provider_without_touching_august(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $victor = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => '13013180-8',
            'operational_name' => 'Victor Robledo (Calama)']);
        $marcelo = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => '13172671-6',
            'operational_name' => 'Marcelo Avendaño (Calama)']);
        Acuerdo::query()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608', 'nombre_proceso' => '202608-Acuerdos',
            'proveedor_origen' => 'Víctor Robledo', 'rut_proveedor_origen' => $victor->tax_id,
            'provider_id' => $victor->id, 'agencia' => 'Calama', 'servicio' => 'Servicio Fijo Courier',
            'costo' => 300000, 'factor' => 1, 'empresa_mandante' => '4N',
        ]);
        AcuerdoServiceRule::query()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608',
            'servicio' => 'Servicio Fijo Courier', 'modo' => 'fijo', 'cantidad_fija' => 1,
        ]);

        app(AcuerdoManager::class)->generate($tenant->id, '202609');

        $this->assertSame($victor->id, Acuerdo::query()->where('periodo', '202608')->value('provider_id'));
        $new = Acuerdo::query()->where('periodo', '202609')->firstOrFail();
        $this->assertSame($marcelo->id, $new->provider_id);
        $this->assertSame($victor->tax_id, $new->rut_proveedor_origen);
        $this->assertDatabaseCount('Maestro_Pagos', 0);
    }

    public function test_close_validates_masters_and_grabs_agreement_amount_without_recalculation(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $client = Client::query()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '22222222-2', 'tax_id_number' => '22222222',
            'tax_id_check_digit' => '2', 'source_merchant_name' => 'Cliente Pila',
            'commercial_name' => 'Cliente Comercial', 'legal_name' => 'Cliente Legal SpA',
        ]);
        $provider = Provider::factory()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '11111111-1',
            'legal_name' => 'Proveedor Legal SpA', 'operational_name' => 'Proveedor Operativo',
            'operator_type' => 'Otro',
        ]);
        $row = Acuerdo::query()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608', 'nombre_proceso' => '202608-Acuerdos',
            'proveedor_origen' => 'Nombre en planilla', 'provider_id' => $provider->id,
            'client_id' => $client->id, 'agencia' => 'Santiago', 'marca' => 'Marca prueba',
            'servicio' => 'Servicio fijo', 'costo' => 1500, 'cantidad' => 4, 'total' => 6000,
            'empresa_mandante' => 'Mandante de prueba',
        ]);

        $this->post(route('provider-payments.courier-movements.acuerdos.close'), ['periodo' => '202608'])
            ->assertSessionHasErrors('periodo');
        $this->assertSame(0, CourierPaymentMovement::query()->count());
        $this->get(route('provider-payments.courier-movements.acuerdos', ['periodo' => '202608', 'estado' => 'pendientes']))
            ->assertOk()->assertViewHas('rows', fn ($rows): bool => $rows->total() === 1);

        Coverage::factory()->create(['tenant_id' => $tenant->id, 'provider_id' => $provider->id, 'zone' => 'Regiones']);
        $this->get(route('provider-payments.courier-movements.acuerdos', ['periodo' => '202608', 'estado' => 'completos']))
            ->assertOk()->assertViewHas('rows', fn ($rows): bool => $rows->total() === 1);
        CourierMovement::query()->create(['tenant_id' => $tenant->id, 'tracking_number' => 'ACO-20260801-0007']);
        $this->post(route('provider-payments.courier-movements.acuerdos.close'), ['periodo' => '202608'])
            ->assertSessionHas('status');
        $payment = CourierPaymentMovement::query()->where('acuerdo_id', $row->id)->firstOrFail();
        $this->assertSame('ACO-20260801-0008', $payment->seguimiento_paquete);
        $this->assertSame('Acuerdos', $payment->nombre_proceso);
        $this->assertSame('Regiones', $payment->zona);
        $this->assertSame(6000, $payment->valor);
        $this->assertSame(4, $payment->peso_final);
        $this->assertSame('2026-08-01', $payment->fecha->toDateString());
        $this->assertSame('Marca prueba / Santiago', $payment->direccion);
        $this->assertSame('Cliente Pila', $payment->comerciante_pila);
        $this->assertSame('Cliente Legal SpA', $payment->razon_social_cliente);
        $this->assertSame('Proveedor Legal SpA', $payment->razon_social_proveedor);
        $this->assertSame('Proveedor Operativo', $payment->nombre_operacional);
        $this->assertSame('Mandante de prueba', $payment->empresa_mandante);
        $this->assertSame('SI', $payment->condicion_pago);
        $this->assertNull($payment->nombre_repartidor);
        $this->assertNull($payment->usuario_entrega);
        $this->assertNotNull($row->fresh()->closed_at);
        $this->assertSame('Acuerdos', CourierMovement::query()->findOrFail($payment->courier_movement_id)->source_system);

        $this->post(route('provider-payments.courier-movements.acuerdos.close'), ['periodo' => '202608'])
            ->assertSessionHasErrors('periodo');
        $this->post(route('provider-payments.courier-movements.acuerdos.update'), [
            'periodo' => '202608', 'rows' => [$row->id => [
                'provider_id' => $provider->id, 'client_id' => $client->id,
                'empresa_mandante' => 'Otra empresa', 'costo' => 1500,
                'inasistencias' => 0, 'adicionales' => 0, 'factor' => 1,
            ]],
        ])->assertSessionHasErrors('periodo');
        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202608'])
            ->assertRedirect();
        $this->assertSame(6000, $payment->fresh()->valor);
        $this->get(route('provider-payments.movements.index', ['period' => '202608', 'process' => 'Acuerdos']))
            ->assertOk()->assertSee('Proveedor Operativo')->assertDontSee('Sin proveedor asociado');
    }

    public function test_master_key_reopens_only_agreements_and_allows_reclosing(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $client = Client::query()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '33333333-3', 'tax_id_number' => '33333333',
            'tax_id_check_digit' => '3', 'commercial_name' => 'Cliente', 'legal_name' => 'Cliente SpA',
        ]);
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id, 'operator_type' => 'RM']);
        $row = Acuerdo::query()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608', 'nombre_proceso' => '202608-Acuerdos',
            'proveedor_origen' => 'Proveedor', 'provider_id' => $provider->id,
            'client_id' => $client->id, 'agencia' => 'Santiago', 'marca' => 'Marca',
            'servicio' => 'Servicio fijo', 'costo' => 1000, 'cantidad' => 2, 'total' => 2000,
            'empresa_mandante' => '4N',
        ]);
        $otherMovement = CourierMovement::query()->create([
            'tenant_id' => $tenant->id, 'tracking_number' => '4N202608010001-111',
            'nombre_proceso' => '202608-Variable',
        ]);
        CourierPaymentMovement::query()->create([
            'tenant_id' => $tenant->id, 'courier_movement_id' => $otherMovement->id,
            'periodo' => '202608', 'nombre_proceso' => '202608-Variable', 'tipo_pago' => 'Variable',
            'peso_final' => 1,
        ]);
        $this->post(route('provider-payments.courier-movements.acuerdos.close'), ['periodo' => '202608'])
            ->assertSessionHas('status');
        $this->assertSame('RM', CourierPaymentMovement::query()->where('acuerdo_id', $row->id)->value('zona'));

        config()->set('provider-payments.process_deletion_key', 'test-master-key');
        $this->post(route('provider-payments.courier-movements.acuerdos.reopen'), [
            'periodo' => '202608', 'password' => 'wrong',
        ])->assertSessionHasErrors('password');
        $this->assertNotNull($row->fresh()->closed_at);
        $this->delete(route('provider-payments.movements.processes.destroy'), [
            'process_name' => '202608-Acuerdos', 'password' => 'test-master-key',
        ])->assertRedirect(route('provider-payments.courier-movements.acuerdos', ['periodo' => '202608']));
        $this->assertNull($row->fresh()->closed_at);
        $this->assertSame(0, CourierPaymentMovement::query()->whereNotNull('acuerdo_id')->count());
        $this->assertSame(0, CourierMovement::query()->where('source_system', 'Acuerdos')->count());
        $this->assertSame(1, CourierPaymentMovement::query()->where('tipo_pago', 'Variable')->count());
        $this->assertSame(1, CourierMovement::query()->where('nombre_proceso', '202608-Variable')->count());

        $row->update(['total' => 3000]);
        $this->post(route('provider-payments.courier-movements.acuerdos.close'), ['periodo' => '202608'])
            ->assertSessionHas('status');
        $this->assertSame(3000, CourierPaymentMovement::query()->where('acuerdo_id', $row->id)->value('valor'));
    }

    public function test_import_recalculation_and_next_month_generation(): void
    {
        $path = $this->workbook();
        try {
            $this->post(route('provider-payments.courier-movements.acuerdos.import'), [
                'file' => new UploadedFile($path, 'Base_Acuerdos.xlsx', null, null, true),
            ])->assertRedirect()->assertSessionHas('status');
            $agreement = Acuerdo::query()->firstOrFail();
            $this->assertSame('202608-Acuerdos', $agreement->nombre_proceso);
            $this->assertSame(21, $agreement->dias_calendario);
            $this->assertSame(22, $agreement->cantidad);
            $this->assertSame(44000, $agreement->total);
            $this->assertFalse(AcuerdoServiceRule::query()->where('servicio', 'Apoyo Alza')->exists());
            $this->assertFalse(AcuerdoServiceRule::query()->where('servicio', 'Agencia apoyo Alza')->exists());
            $this->get(route('provider-payments.courier-movements.acuerdos', ['periodo' => '202608']))
                ->assertOk()->assertSee('44.000')->assertSee('Proveedor origen')
                ->assertSee('list="agreement-mandantes"', false);
            $this->get(route('provider-payments.courier-movements.acuerdos', ['periodo' => '202608', 'mandante' => '4N']))
                ->assertOk()->assertViewHas('mandantes', fn ($mandantes): bool => $mandantes->contains('4N'))
                ->assertViewHas('rows', fn ($rows): bool => $rows->total() === 1);
            $this->get(route('provider-payments.courier-movements.acuerdos', ['periodo' => '202608', 'mandante' => 'PMCB']))
                ->assertOk()->assertViewHas('rows', fn ($rows): bool => $rows->total() === 0);

            $this->post(route('provider-payments.courier-movements.acuerdos.calendar'), [
                'periodo' => '202608', 'feriados' => ['2026-08-03'],
            ])->assertRedirect();
            $this->assertSame(42000, $agreement->fresh()->total);
            $this->assertTrue(AcuerdoCalendarDay::query()->where('periodo', '202608')->where('fecha', '2026-08-03')->firstOrFail()->bloqueado);
            $this->post(route('provider-payments.courier-movements.acuerdos.calendar'), [
                'periodo' => '202608', 'feriados' => [],
            ])->assertSessionHasErrors('feriados');
            $this->assertSame(42000, $agreement->fresh()->total);
            $this->post(route('provider-payments.courier-movements.acuerdos.calendar.unlock'), [
                'periodo' => '202608',
            ])->assertRedirect();
            $this->assertFalse(AcuerdoCalendarDay::query()->where('periodo', '202608')->where('fecha', '2026-08-03')->firstOrFail()->bloqueado);

            $this->post(route('provider-payments.courier-movements.acuerdos.generate'), [
                'periodo' => '2026-09',
            ])->assertRedirect()->assertSessionHas('status');
            $next = Acuerdo::query()->where('periodo', '202609')->firstOrFail();
            $this->assertSame(0, $next->adicionales);
            $this->assertSame(0, $next->inasistencias);
            $this->assertSame(22, $next->cantidad);
            $this->assertSame(44000, $next->total);

            $response = $this->post(route('provider-payments.courier-movements.acuerdos.update'), [
                'periodo' => '202609', 'rows' => [$next->id => [
                    'provider_id' => null, 'client_id' => null, 'inasistencias' => 1,
                    'adicionales' => 2, 'costo' => 1200, 'factor' => 2, 'empresa_mandante' => 'PMCB',
                ]], 'mandante' => '4N',
            ]);
            $response->assertRedirect();
            $this->assertStringContainsString('mandante=4N', $response->headers->get('Location'));
            $this->assertSame(55200, $next->fresh()->total);
            $this->assertSame('PMCB', $next->fresh()->empresa_mandante);
            $this->get(route('provider-payments.courier-movements.acuerdos', ['periodo' => '202609', 'mandante' => 'PMCB']))
                ->assertOk()->assertViewHas('rows', fn ($rows): bool => $rows->total() === 1);

            $this->post(route('provider-payments.courier-movements.acuerdos.import'), [
                'file' => new UploadedFile($path, 'Base_Acuerdos.xlsx', null, null, true),
            ])->assertRedirect();
            $this->assertSame(2, Acuerdo::query()->count());
        } finally {
            @unlink($path);
        }
    }

    private function workbook(): string
    {
        $book = new Spreadsheet;
        $calendar = $book->getActiveSheet();
        $calendar->setTitle('Calendario');
        $calendar->setCellValue('A3', ExcelDate::PHPToExcel(new \DateTimeImmutable('2026-08-01')));
        $calendar->setCellValue('H3', 'Servicio semanal');
        $calendar->setCellValue('I3', '=F3+F4+F5+F6+F7');
        $calendar->setCellValue('H4', 'Apoyo Alza');
        $calendar->setCellValue('I4', '=F3+F4+F5');
        $calendar->setCellValue('H5', 'Agencia apoyo Alza');
        $calendar->setCellValue('I5', 1);
        $base = $book->createSheet();
        $base->setTitle('Base Acuerdos');
        $base->fromArray([
            'PROVEEDOR', 'RUT', 'AGENCIA', 'TipoServicio', 'Marca', 'Servicio', 'Costo',
            'Q calendario', 'Q inasistencia', 'Q Adicionales', 'Cantidad', 'GlosaFactor',
            'Factor', 'Total', 'Razon Social cliente', 'Comerciante Pila', 'RutCliente',
            'NombreComercial', 'Empresa Mandante',
        ], null, 'A1');
        $base->fromArray([
            'Proveedor origen', '11111111-1', 'RM', 'FIJO', 'Marca', 'Servicio semanal', 1000,
            null, 0, 1, null, null, 2, null, 'Cliente origen', 'Cliente', '22222222-2', null, '4N',
        ], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'agreements-');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }
}
