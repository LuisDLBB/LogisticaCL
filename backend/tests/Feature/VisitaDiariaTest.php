<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\Coverage;
use App\Models\Provider;
use App\Models\Tenant;
use App\Models\VisitaDiaria;
use App\Modules\ProviderPayments\Services\CourierPaymentAssigner;
use App\Modules\ProviderPayments\Services\VisitaDiariaManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\ProviderPaymentsWorkflowTestCase;

class VisitaDiariaTest extends ProviderPaymentsWorkflowTestCase
{
    use RefreshDatabase;

    public function test_excel_import_and_next_month_generation_calculate_visit_days_without_payments(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => '11111111-1']);
        $client = Client::query()->create(['tenant_id' => $tenant->id, 'tax_id' => '22222222-2',
            'tax_id_number' => '22222222', 'tax_id_check_digit' => '2', 'commercial_name' => 'Cliente Prueba',
            'legal_name' => 'Cliente Legal']);
        $file = $this->workbook();

        $this->post(route('provider-payments.courier-movements.visitas.import'), [
            'periodo_month' => '2026-08',
            'file' => new UploadedFile($file, 'Base_Visitas.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertSessionHas('status');
        $first = VisitaDiaria::query()->where('nombre_local', 'Local 100')->firstOrFail();
        $second = VisitaDiaria::query()->where('nombre_local', 'Local 200')->firstOrFail();
        $this->assertSame($provider->id, $first->provider_id);
        $this->assertSame($client->id, $first->client_id);
        $this->assertSame([15, 31], $first->dias);
        $this->assertSame(2000, $first->total_mensual);
        $this->assertSame('Cerrado', $second->estatus_origen);
        $this->assertSame('', $second->direccion);
        $this->assertSame(21000, $second->total_mensual);
        $this->assertSame(0, CourierPaymentMovement::query()->count());
        $this->post(route('provider-payments.courier-movements.visitas.close'), ['periodo' => '202608'])
            ->assertSessionHasErrors('periodo');
        $this->assertSame(0, CourierPaymentMovement::query()->count());

        $this->get(route('provider-payments.courier-movements.visitas', ['periodo' => '202608', 'q' => 'Cliente Prueba Local 100']))
            ->assertOk()->assertSee('Local 100')->assertDontSee('Local 200');
        $this->post(route('provider-payments.courier-movements.visitas.generate'), ['periodo_month' => '2026-09'])
            ->assertSessionHas('status');
        $generated = VisitaDiaria::query()->where('periodo', '202609')->where('visit_key', $first->visit_key)->firstOrFail();
        $this->assertSame([15, 30], $generated->dias);
        $this->assertSame(2000, $generated->total_mensual);
        $generatedWeekdays = VisitaDiaria::query()->where('periodo', '202609')->where('visit_key', $second->visit_key)->firstOrFail();
        $this->assertNotContains(18, $generatedWeekdays->dias);
        $this->assertCount(21, $generatedWeekdays->dias);
        $this->assertSame(21000, $generatedWeekdays->total_mensual);
        $this->assertSame(0, CourierPaymentMovement::query()->count());
        unlink($file);
    }

    public function test_september_18_is_excluded_only_from_monday_to_friday_visits(): void
    {
        $manager = app(VisitaDiariaManager::class);

        $this->assertNotContains(18, $manager->suggestDays('202609', 'Lunes A Viernes'));
        $this->assertContains(18, $manager->suggestDays('202609', 'Lunes - Miercoles - Viernes'));
        $this->assertContains(18, $manager->suggestDays('202608', 'Lunes A Viernes'));
    }

    public function test_close_creates_vdc_payment_and_reopen_removes_only_its_movements(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => '11111111-1',
            'operational_name' => 'Proveedor Prueba']);
        $client = Client::query()->create(['tenant_id' => $tenant->id, 'tax_id' => '22222222-2',
            'tax_id_number' => '22222222', 'tax_id_check_digit' => '2', 'commercial_name' => 'Cliente Prueba',
            'source_merchant_name' => 'Cliente Pila', 'legal_name' => 'Cliente Legal']);
        Coverage::factory()->create(['tenant_id' => $tenant->id, 'provider_id' => $provider->id, 'zone' => 'Regiones']);
        $visit = VisitaDiaria::factory()->create(['tenant_id' => $tenant->id, 'provider_id' => $provider->id,
            'client_id' => $client->id, 'zona' => 'Regiones', 'estatus_origen' => 'Cerrado',
            'dias' => [15, 31], 'valor_dia' => 1500, 'total_mensual' => 3000]);
        CourierMovement::query()->create(['tenant_id' => $tenant->id, 'tracking_number' => 'VDC-20260801-0007']);

        $this->post(route('provider-payments.courier-movements.visitas.close'), ['periodo' => '202608'])
            ->assertSessionHas('status');
        $payment = CourierPaymentMovement::query()->where('visita_diaria_id', $visit->id)->firstOrFail();
        $this->assertSame('VDC-20260801-0008', $payment->seguimiento_paquete);
        $this->assertSame('Visitas', $payment->nombre_proceso);
        $this->assertSame('Visitas Diarias', $payment->tipo_pago);
        $this->assertSame(3000, $payment->valor);
        $this->assertSame(2, $payment->peso_final);
        $this->assertSame('SI', $payment->condicion_pago);
        $this->assertSame('Cliente Legal', $payment->razon_social_cliente);
        $this->assertSame('Proveedor Prueba', $payment->nombre_operacional);
        $this->assertNotNull($visit->fresh()->closed_at);
        app(CourierPaymentAssigner::class)->assign($tenant->id, '202608');
        $this->assertSame(3000, $payment->fresh()->valor);
        $this->assertSame('SI', $payment->fresh()->condicion_pago);
        $this->post(route('provider-payments.courier-movements.visitas.close'), ['periodo' => '202608'])
            ->assertSessionHasErrors('periodo');
        $this->assertSame(1, CourierPaymentMovement::query()->count());

        config(['provider-payments.process_deletion_key' => 'secret']);
        $this->post(route('provider-payments.courier-movements.visitas.reopen'), ['periodo' => '202608', 'password' => 'secret'])
            ->assertSessionHas('status');
        $this->assertNull($visit->fresh()->closed_at);
        $this->assertSame(0, CourierPaymentMovement::query()->count());
        $this->assertSame(1, CourierMovement::query()->count());
    }

    private function workbook(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([['NuevoAgente', 'Local', 'Nombre del Local', 'Direccion', 'Comuna', 'Frecuencia',
            'SLA Operador desde RM (Paq)', 'SLA Cliente desde RM (Paq)', 'SLA desde Locales a CD',
            'Estatus', 'Razón social proveedor', 'Nombre de pila proveedor', 'RUT proveedor', 'Valor x Dia',
            'Cliente', 'Rut Cliente', 'Comerciante (Pila)'],
            ['Operador Prueba', 'Cruz Verde', 'Local 100', 'Calle 100', 'Santiago', 'Quincenal', '', '', '',
                'Activo', 'Proveedor Legal', 'Proveedor Prueba', '11111111-1', 1000, 'Cliente Legal', '22222222-2', 'Cliente Prueba'],
            ['Operador Prueba', 'Cruz Verde', 'Local 200', '', 'Santiago', 'Lunes A Viernes', '', '', '',
                'Cerrado', 'Proveedor Legal', 'Proveedor Prueba', '11111111-1', 1000, 'Cliente Legal', '22222222-2', 'Cliente Prueba']]);
        $path = tempnam(sys_get_temp_dir(), 'visitas_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
