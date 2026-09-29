<?php

namespace Tests\Feature;

use App\Models\Acuerdo;
use App\Models\ApoyoAlza;
use App\Models\Client;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\Provider;
use App\Models\Tenant;
use App\Modules\ProviderPayments\Services\ApoyoAlzaCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\ProviderPaymentsWorkflowTestCase;

class ApoyoAlzaTest extends ProviderPaymentsWorkflowTestCase
{
    use RefreshDatabase;

    public function test_import_uses_the_whole_provider_base_for_each_row_and_does_not_create_payments(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '11111111-1',
            'legal_name' => 'Proveedor Prueba SpA', 'operational_name' => 'Proveedor Prueba',
        ]);
        $this->payment($tenant->id, $provider->tax_id, 'Variable', 'Variable', 100000);
        $this->payment($tenant->id, $provider->tax_id, '202608-Variable', 'Variable', 100000);
        $this->payment($tenant->id, $provider->tax_id, 'Variable', 'Variable', 800000, 'NO');
        $path = $this->workbook([
            ['Proveedor Prueba SpA', '11111111-1', 'Variables', 'Variable', '%', 0.05, null, '4N', 'Agencia A'],
            ['Proveedor Prueba SpA', '11111111-1', 'Variables', 'Variable', '%', 0.05, null, '4N', 'Agencia B'],
        ]);

        try {
            $this->post(route('provider-payments.courier-movements.apoyo-alza.import'), [
                'periodo' => '2026-08', 'file' => new UploadedFile($path, 'apoyo.xlsx', null, null, true),
            ])->assertRedirect(route('provider-payments.courier-movements.apoyo-alza', ['periodo' => '202608']));
            $this->assertSame(2, ApoyoAlza::query()->where('periodo', '202608')->count());
            $this->assertSame(20000, (int) ApoyoAlza::query()->sum('monto_apoyo'));
            $this->assertSame([200000, 200000], ApoyoAlza::query()->orderBy('id')->pluck('monto_base')->all());
            $this->assertSame(3, CourierPaymentMovement::query()->count());
            $this->get(route('provider-payments.courier-movements.apoyo-alza', ['periodo' => '202608']))
                ->assertOk()->assertSee('202608-Apoyo')->assertSee('Agencia A')->assertSee('Agencia B');

            $this->post(route('provider-payments.courier-movements.apoyo-alza.import'), [
                'periodo' => '2026-08', 'file' => new UploadedFile($path, 'apoyo.xlsx', null, null, true),
            ])->assertSessionHas('status');
            $this->assertSame(2, ApoyoAlza::query()->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_agreement_support_waits_for_closure_and_matches_the_service_text(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => '22222222-2']);
        $acuerdo = Acuerdo::query()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608', 'nombre_proceso' => '202608-Acuerdos',
            'proveedor_origen' => 'Proveedor', 'servicio' => 'Servicio Fijo Courier',
            'costo' => 100000, 'provider_id' => $provider->id,
        ]);
        $this->payment($tenant->id, $provider->tax_id, '202608-Acuerdos', 'Servicio Fijo Courier', 100000);
        $this->payment($tenant->id, $provider->tax_id, '202608-Acuerdos', 'Otro servicio', 300000);
        $row = ApoyoAlza::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608', 'provider_id' => $provider->id,
            'proceso_base' => 'Acuerdos', 'servicio_acuerdo' => 'Servicio Fijo Courier',
            'porcentaje' => '0.070000',
        ]);

        app(ApoyoAlzaCalculator::class)->recalculate($tenant->id, '202608');
        $this->assertSame('proceso_abierto', $row->fresh()->estado_calculo);
        $this->assertNull($row->fresh()->monto_apoyo);

        $acuerdo->update(['closed_at' => now()]);
        app(ApoyoAlzaCalculator::class)->recalculate($tenant->id, '202608');
        $this->assertSame('calculado', $row->fresh()->estado_calculo);
        $this->assertSame(100000, $row->fresh()->monto_base);
        $this->assertSame(7000, $row->fresh()->monto_apoyo);
    }

    public function test_five_agreement_routes_use_one_shared_percentage_without_other_services(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => '12538127-8']);
        Acuerdo::query()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608', 'nombre_proceso' => '202608-Acuerdos',
            'proveedor_origen' => 'Claudio Cuevas', 'servicio' => 'Ruta Lunes',
            'costo' => 450000, 'provider_id' => $provider->id, 'closed_at' => now(),
        ]);
        $routes = [
            'Ruta Lunes' => 450000, 'Ruta Martes' => 280000,
            'Ruta Miercoles' => 260000, 'Ruta Jueves' => 440000, 'Ruta Viernes' => 360000,
        ];
        foreach ($routes as $service => $amount) {
            $this->payment($tenant->id, $provider->tax_id, '202608-Acuerdos', $service, $amount);
        }
        $extra = 'Adicional de Servicios';
        $this->payment($tenant->id, $provider->tax_id, '202608-Acuerdos', $extra, 62500);
        $row = ApoyoAlza::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608', 'provider_id' => $provider->id,
            'proceso_base' => 'Acuerdos', 'servicio_acuerdo' => implode(' | ', array_keys($routes)),
            'porcentaje' => '0.019000',
        ]);
        $this->payment($tenant->id, $provider->tax_id, 'Variable', 'Variable', 70300);
        $variable = ApoyoAlza::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608', 'provider_id' => $provider->id,
            'proceso_base' => 'Variables', 'servicio_acuerdo' => 'Variable',
            'porcentaje' => '0.019000',
        ]);

        app(ApoyoAlzaCalculator::class)->recalculate($tenant->id, '202608');
        $this->assertSame('calculado', $row->fresh()->estado_calculo);
        $this->assertSame(5, $row->fresh()->registros_base);
        $this->assertSame(1790000, $row->fresh()->monto_base);
        $this->assertSame(34010, $row->fresh()->monto_apoyo);
        $this->assertSame(1336, $variable->fresh()->monto_apoyo);
        $this->assertSame('0.019000', $variable->fresh()->porcentaje);

        CourierPaymentMovement::query()->where('service_name', 'Ruta Viernes')->delete();
        app(ApoyoAlzaCalculator::class)->recalculate($tenant->id, '202608');
        $this->assertSame('sin_base', $row->fresh()->estado_calculo);
        $this->assertNull($row->fresh()->monto_apoyo);
    }

    public function test_three_visit_services_include_repeated_payments_and_keep_the_original_percentage(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => '77458608-3']);
        Acuerdo::query()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608', 'nombre_proceso' => '202608-Acuerdos',
            'proveedor_origen' => 'De Tentempie SPA', 'servicio' => 'Visita Mensual',
            'costo' => 1200, 'provider_id' => $provider->id, 'closed_at' => now(),
        ]);
        $this->payment($tenant->id, $provider->tax_id, '202608-Acuerdos', 'Visitas Lun a Vie', 352800);
        $this->payment($tenant->id, $provider->tax_id, '202608-Acuerdos', 'Visita Mensual', 1200);
        foreach ([90000, 90000, 90000, 95000] as $amount) {
            $this->payment($tenant->id, $provider->tax_id, '202608-Acuerdos', 'Visita SMU Mi', $amount);
        }
        $this->payment($tenant->id, $provider->tax_id, '202608-Acuerdos', 'Visitas Miercoles', 4800);
        $row = ApoyoAlza::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608', 'provider_id' => $provider->id,
            'proceso_base' => 'Acuerdos',
            'servicio_acuerdo' => 'Visitas Lun a Vie | Visita Mensual | Visita SMU Mi',
            'porcentaje' => '0.040000',
        ]);

        app(ApoyoAlzaCalculator::class)->recalculate($tenant->id, '202608');
        $this->assertSame(6, $row->fresh()->registros_base);
        $this->assertSame(719000, $row->fresh()->monto_base);
        $this->assertSame(28760, $row->fresh()->monto_apoyo);
        $this->assertSame('0.040000', $row->fresh()->porcentaje);
    }

    public function test_deyna_agreement_support_from_september_includes_wednesday_and_every_smu_visit(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => '77458608-3']);
        Acuerdo::query()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202609', 'nombre_proceso' => '202609-Acuerdos',
            'proveedor_origen' => 'De Tentempie SPA', 'servicio' => 'Visita Mensual',
            'costo' => 1200, 'provider_id' => $provider->id, 'closed_at' => now(),
        ]);
        $this->payment($tenant->id, $provider->tax_id, 'Acuerdos', 'Visitas Lun a Vie', 352800, 'SI', '202609');
        $this->payment($tenant->id, $provider->tax_id, 'Acuerdos', 'Visitas Miercoles', 6000, 'SI', '202609');
        $this->payment($tenant->id, $provider->tax_id, 'Acuerdos', 'Visita Mensual', 1200, 'SI', '202609');
        foreach ([90000, 90000, 90000, 95000] as $amount) {
            $this->payment($tenant->id, $provider->tax_id, 'Acuerdos', 'Visita SMU Mi', $amount, 'SI', '202609');
        }
        $this->payment($tenant->id, $provider->tax_id, 'Acuerdos', 'Otro servicio', 50000, 'SI', '202609');
        $path = $this->workbook([
            ['De Tentempie SPA', '77458608-3', 'Acuerdos', 'Servicio Fijo Courier', '%', 0.04, null, '4N', 'Valdivia'],
        ]);
        try {
            $this->post(route('provider-payments.courier-movements.apoyo-alza.import'), [
                'periodo' => '2026-09', 'file' => new UploadedFile($path, 'apoyo.xlsx', null, null, true),
            ])->assertSessionHas('status');
        } finally {
            @unlink($path);
        }
        $row = ApoyoAlza::query()->where('periodo', '202609')->firstOrFail();

        $this->assertSame('Visitas Lun a Vie | Visitas Miercoles | Visita Mensual | Visita SMU Mi', $row->fresh()->servicio_acuerdo);
        $this->assertSame('calculado', $row->fresh()->estado_calculo);
        $this->assertSame(7, $row->fresh()->registros_base);
        $this->assertSame(725000, $row->fresh()->monto_base);
        $this->assertSame(29000, $row->fresh()->monto_apoyo);
        $this->assertSame('0.040000', $row->fresh()->porcentaje);

        $this->post(route('provider-payments.courier-movements.apoyo-alza.update'), [
            'periodo' => '202609', 'rows' => [$row->id => [
                'provider_id' => $provider->id, 'servicio_acuerdo' => 'Servicio Fijo Courier',
                'porcentaje' => 4, 'empresa_mandante' => '4N', 'agencia' => 'Valdivia',
            ]],
        ])->assertSessionHas('status');
        $this->assertSame('Visitas Lun a Vie | Visitas Miercoles | Visita Mensual | Visita SMU Mi', $row->fresh()->servicio_acuerdo);
        $this->assertSame(29000, $row->fresh()->monto_apoyo);
    }

    public function test_claudio_support_from_september_uses_five_routes_and_one_point_nine_percent_for_both_bases(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id, 'tax_id' => '12538127-8']);
        Acuerdo::query()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202609', 'nombre_proceso' => '202609-Acuerdos',
            'proveedor_origen' => 'Claudio Cuevas', 'servicio' => 'Ruta Lunes',
            'costo' => 360000, 'provider_id' => $provider->id, 'closed_at' => now(),
        ]);
        foreach ([
            'Ruta Lunes' => 360000, 'Ruta Martes' => 350000, 'Ruta Miercoles' => 325000,
            'Ruta Jueves' => 440000, 'Ruta Viernes' => 270000,
        ] as $service => $amount) {
            $this->payment($tenant->id, $provider->tax_id, 'Acuerdos', $service, $amount, 'SI', '202609');
        }
        $this->payment($tenant->id, $provider->tax_id, 'Acuerdos', 'Adicional de Servicios', 45000, 'SI', '202609');
        $this->payment($tenant->id, $provider->tax_id, 'Variable', 'Variable', 100000, 'SI', '202609');
        $path = $this->workbook([
            ['Claudio Andres Cuevas Aravena', '12538127-8', 'Acuerdos', 'Servicio Fijo Courier', '%', 0.05, null, '4N', 'Temuco'],
            ['Claudio Andres Cuevas Aravena', '12538127-8', 'Variables', 'Variable', '%', 0.05, null, '4N', 'Temuco'],
        ]);
        try {
            $this->post(route('provider-payments.courier-movements.apoyo-alza.import'), [
                'periodo' => '2026-09', 'file' => new UploadedFile($path, 'apoyo.xlsx', null, null, true),
            ])->assertSessionHas('status');
        } finally {
            @unlink($path);
        }

        $agreement = ApoyoAlza::query()->where('periodo', '202609')->where('proceso_base', 'Acuerdos')->firstOrFail();
        $variable = ApoyoAlza::query()->where('periodo', '202609')->where('proceso_base', 'Variables')->firstOrFail();
        $this->assertSame('Ruta Lunes | Ruta Martes | Ruta Miercoles | Ruta Jueves | Ruta Viernes', $agreement->servicio_acuerdo);
        $this->assertSame(5, $agreement->registros_base);
        $this->assertSame(1745000, $agreement->monto_base);
        $this->assertSame(33155, $agreement->monto_apoyo);
        $this->assertSame('0.019000', $agreement->porcentaje);
        $this->assertSame(100000, $variable->monto_base);
        $this->assertSame(1900, $variable->monto_apoyo);
        $this->assertSame('0.019000', $variable->porcentaje);
    }

    public function test_review_lists_problems_first_then_provider_and_service(): void
    {
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $readyZulu = ApoyoAlza::factory()->create([
            'tenant_id' => $tenantId, 'proveedor_origen' => 'Zulu',
            'proceso_base' => 'Variables', 'servicio_acuerdo' => 'Variable', 'estado_calculo' => 'calculado',
        ]);
        $pendingBeta = ApoyoAlza::factory()->create([
            'tenant_id' => $tenantId, 'proveedor_origen' => 'Beta',
            'proceso_base' => 'Ruta CV', 'servicio_acuerdo' => 'Ruta CV', 'estado_calculo' => 'sin_base',
        ]);
        $pendingAlphaVariable = ApoyoAlza::factory()->create([
            'tenant_id' => $tenantId, 'proveedor_origen' => 'Alpha',
            'proceso_base' => 'Variables', 'servicio_acuerdo' => 'Variable', 'estado_calculo' => 'sin_base',
        ]);
        $readyAlpha = ApoyoAlza::factory()->create([
            'tenant_id' => $tenantId, 'proveedor_origen' => 'Alpha',
            'proceso_base' => 'Acuerdos', 'servicio_acuerdo' => 'Servicio Fijo Courier', 'estado_calculo' => 'calculado',
        ]);
        $pendingAlphaAgreement = ApoyoAlza::factory()->create([
            'tenant_id' => $tenantId, 'proveedor_origen' => 'Alpha',
            'proceso_base' => 'Acuerdos', 'servicio_acuerdo' => 'Servicio Fijo Courier', 'estado_calculo' => 'sin_base',
        ]);

        $this->get(route('provider-payments.courier-movements.apoyo-alza', ['periodo' => '202608']))
            ->assertOk()->assertViewHas('rows', fn ($rows): bool => $rows->pluck('id')->all() === [
                $pendingAlphaAgreement->id, $pendingAlphaVariable->id, $pendingBeta->id,
                $readyAlpha->id, $readyZulu->id,
            ]);
    }

    public function test_closing_creates_linked_alz_payments_and_reopening_only_removes_those_payments(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $client = Client::query()->where('tenant_id', $tenant->id)
            ->where('tax_id', '77346078-7')->firstOrFail();
        $provider = Provider::factory()->create([
            'tenant_id' => $tenant->id, 'tax_id' => '11111111-1', 'operator_type' => 'RM',
            'legal_name' => 'Proveedor Apoyo SpA', 'operational_name' => 'Proveedor Apoyo',
        ]);
        $this->payment($tenant->id, $provider->tax_id, '202608-Variable', 'Variable', 100000);
        $first = ApoyoAlza::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608', 'provider_id' => $provider->id,
            'proceso_base' => 'Variables', 'porcentaje' => '0.050000',
            'agencia' => 'Agencia A', 'fila_origen' => 2,
        ]);
        $second = ApoyoAlza::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608', 'provider_id' => $provider->id,
            'proceso_base' => 'Variables', 'porcentaje' => '0.050000',
            'agencia' => 'Agencia B', 'fila_origen' => 3,
        ]);
        $withoutPayment = ApoyoAlza::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608', 'provider_id' => $provider->id,
            'proceso_base' => 'Ruta CV', 'factor' => 'Dia de Ruta CV', 'porcentaje' => null,
            'monto_dia' => 0, 'agencia' => 'Agencia C', 'fila_origen' => 4,
        ]);

        $this->post(route('provider-payments.courier-movements.apoyo-alza.recalculate'), ['periodo' => '202608'])
            ->assertSessionHas('status');
        $this->get(route('provider-payments.courier-movements.apoyo-alza', ['periodo' => '202608']))
            ->assertOk()->assertSee('2 pagos por $ 10.000')->assertSee('1 apoyos de $ 0 quedarán sin pago')
            ->assertDontSee('disabled>Grabar y cerrar proceso', false);

        $this->post(route('provider-payments.courier-movements.apoyo-alza.close'), ['periodo' => '202608'])
            ->assertSessionHas('status');
        $payments = CourierPaymentMovement::query()->where('periodo', '202608')->where('nombre_proceso', 'Apoyo')
            ->orderBy('apoyo_alza_id')->get();
        $this->assertCount(2, $payments);
        $this->assertSame([$first->id, $second->id], $payments->pluck('apoyo_alza_id')->all());
        $this->assertSame(['ALZ-20260801-0001', 'ALZ-20260801-0002'], $payments->pluck('seguimiento_paquete')->all());
        $this->assertSame([5000, 5000], $payments->pluck('valor')->all());
        $this->assertSame($client->id, $payments->first()->client_id);
        $this->assertSame('77346078-7', $payments->first()->rut_cliente);
        $this->assertSame($client->legal_name, $payments->first()->razon_social_cliente);
        $this->assertSame('RM', $payments->first()->zona);
        $this->assertSame('Apoyo Alza', $payments->first()->tipo_pago);
        $this->assertSame('SI', $payments->first()->condicion_pago);
        $this->assertSame(1, $payments->first()->peso_final);
        $this->assertSame('Apoyo Alza', $payments->first()->direccion);
        $this->assertSame('Agencia A', $payments->first()->comuna_destino);
        $this->assertNull($payments->first()->usuario_entrega);
        $this->assertNotNull($first->fresh()->closed_at);
        $this->assertSame('no_pagar', $withoutPayment->fresh()->estado_calculo);
        $this->assertSame(0, $withoutPayment->fresh()->monto_apoyo);
        $this->assertNotNull($withoutPayment->fresh()->closed_at);
        $this->assertDatabaseMissing('Pago_Movimientos_Courier', ['apoyo_alza_id' => $withoutPayment->id]);

        $this->post(route('provider-payments.courier-movements.apoyo-alza.update'), [
            'periodo' => '202608', 'rows' => [$first->id => ['provider_id' => $provider->id,
                'porcentaje' => 10, 'empresa_mandante' => '4N', 'agencia' => 'Agencia A']],
        ])->assertSessionHasErrors('periodo');
        $this->post(route('provider-payments.courier-movements.apoyo-alza.recalculate'), ['periodo' => '202608'])
            ->assertSessionHasErrors('periodo');
        $this->post(route('provider-payments.courier-movements.apoyo-alza.close'), ['periodo' => '202608'])
            ->assertSessionHasErrors('periodo');

        config()->set('provider-payments.process_deletion_key', 'test-master');
        $this->post(route('provider-payments.courier-movements.apoyo-alza.reopen'), [
            'periodo' => '202608', 'password' => 'incorrecta',
        ])->assertSessionHasErrors('password');
        $this->assertSame(2, CourierPaymentMovement::query()->where('periodo', '202608')->where('nombre_proceso', 'Apoyo')->count());
        $this->delete(route('provider-payments.movements.processes.destroy'), [
            'process_name' => '202608-Variable', 'password' => 'test-master',
        ])->assertSessionHasErrors('process_name');
        $this->post(route('provider-payments.courier-movements.apoyo-alza.reopen'), [
            'periodo' => '202608', 'password' => 'test-master',
        ])->assertSessionHas('status');
        $this->assertSame(0, CourierPaymentMovement::query()->where('periodo', '202608')->where('nombre_proceso', 'Apoyo')->count());
        $this->assertSame(0, CourierMovement::query()->where('nombre_proceso', '202608-Apoyo')->count());
        $this->assertSame(1, CourierPaymentMovement::query()->where('periodo', '202608')->where('nombre_proceso', 'Variable')->count());
        $this->assertNull($first->fresh()->closed_at);
        $this->assertNull($withoutPayment->fresh()->closed_at);

        $this->post(route('provider-payments.courier-movements.apoyo-alza.close'), ['periodo' => '202608'])
            ->assertSessionHas('status');
        $this->delete(route('provider-payments.movements.processes.destroy'), [
            'process_name' => '202608-Apoyo', 'password' => 'test-master',
        ])->assertSessionHas('status');
        $this->assertSame(0, CourierPaymentMovement::query()->where('periodo', '202608')->where('nombre_proceso', 'Apoyo')->count());
        $this->assertSame(1, CourierPaymentMovement::query()->where('periodo', '202608')->where('nombre_proceso', 'Variable')->count());
    }

    public function test_closing_requires_all_rows_to_have_a_valid_calculation(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::factory()->create(['tenant_id' => $tenant->id, 'operator_type' => 'Regiones']);
        ApoyoAlza::factory()->create([
            'tenant_id' => $tenant->id, 'periodo' => '202608', 'provider_id' => $provider->id,
            'proceso_base' => 'Variables', 'fila_origen' => 2,
        ]);

        $this->post(route('provider-payments.courier-movements.apoyo-alza.close'), ['periodo' => '202608'])
            ->assertSessionHasErrors('periodo');
        $this->assertSame(0, CourierPaymentMovement::query()->where('periodo', '202608')->where('nombre_proceso', 'Apoyo')->count());
        $this->assertNull(ApoyoAlza::query()->first()->closed_at);
    }

    private function payment(int $tenantId, string $rut, string $process, string $service, ?int $amount, string $condition = 'SI', string $period = '202608'): void
    {
        $movement = CourierMovement::query()->create(['tenant_id' => $tenantId, 'tracking_number' => fake()->unique()->bothify('T########')]);
        CourierPaymentMovement::query()->create([
            'tenant_id' => $tenantId, 'courier_movement_id' => $movement->id,
            'periodo' => $period, 'nombre_proceso' => $process, 'tipo_pago' => $process,
            'seguimiento_paquete' => $movement->tracking_number, 'peso_final' => 1,
            'rut_proveedor' => $rut, 'service_name' => $service,
            'valor' => $amount, 'condicion_pago' => $condition,
        ]);
    }

    private function workbook(array $rows): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([
            ['Razon Social Cliente', 'Rut Cliente', 'Proceso', 'Servicio de Acuerdo', 'Factor', 'Porcentaje', 'Monto', 'Empresa Mandante', 'AGENCIA'],
            ...$rows,
        ]);
        $path = tempnam(sys_get_temp_dir(), 'apoyo-').'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }
}
