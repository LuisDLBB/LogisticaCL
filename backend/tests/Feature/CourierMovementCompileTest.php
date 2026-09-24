<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CostCenter;
use App\Models\CostCenterKey;
use App\Models\CostCenterWeightRate;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\CourierStatus;
use App\Models\Coverage;
use App\Models\Provider;
use App\Models\ServiceType;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CourierMovementCompileTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['courier_movement_id' => $movement->id,
            'rut_proveedor' => '0-0', 'razon_social_proveedor' => 'Envio externo', 'condicion_pago' => 'NO', 'valor' => null]);

        DB::table('Pago_Movimientos_Courier')->where('courier_movement_id', $movement->id)
            ->update(['condicion_pago' => 'SI', 'valor' => 100]);
        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202607'])->assertRedirect();
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['courier_movement_id' => $movement->id,
            'rut_proveedor' => '0-0', 'razon_social_proveedor' => 'Envio externo', 'condicion_pago' => 'NO', 'valor' => null]);

        DB::table('Pago_Movimientos_Courier')->where('courier_movement_id', $movement->id)
            ->update(['condicion_pago' => 'SI', 'valor' => 100]);
        $this->post(route('provider-payments.courier-movements.compile.store'), ['period' => '202607', 'processes' => ['Variable']])->assertRedirect();
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['courier_movement_id' => $movement->id,
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
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['comuna_matriz' => 'Postman Cargo (Iquique)', 'condicion_pago' => 'SI', 'valor' => 100]);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['comuna_matriz' => 'Postman Cargo (Alto Hospicio)', 'condicion_pago' => 'SI', 'valor' => 200]);
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
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => 'lana_pendiente', 'peso_final' => 1, 'condicion_pago' => 'SI', 'valor' => 100]);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => 'lana_no', 'peso_final' => 1, 'condicion_pago' => 'NO', 'valor' => null]);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => 'variable', 'peso_final' => 1, 'condicion_pago' => 'SI', 'valor' => 100]);
        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202607'])
            ->assertRedirect()->assertSessionHas('status', fn (string $status): bool => str_contains($status, '0 Lanas sin peso ajustadas a 1 kg'));
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
            $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => $tracking, 'condicion_pago' => 'SI', 'valor' => 1294]);
        }
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => 'no', 'condicion_pago' => 'NO', 'valor' => null]);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => 'protegido', 'condicion_pago' => 'NO', 'valor' => null]);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => 'sin_proveedor', 'condicion_pago' => 'SI', 'valor' => 800]);
        foreach (['ambiguo', 'sin_cobertura'] as $tracking) {
            $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => $tracking, 'condicion_pago' => null, 'valor' => null]);
        }
        Coverage::query()->where('commune_name', 'Viña del Mar')->update(['return_value' => 1500]);
        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202607'])->assertRedirect();
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => 'pesado', 'condicion_pago' => 'SI', 'valor' => 1500]);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => 'protegido', 'condicion_pago' => 'NO', 'valor' => null]);
        $this->assertDatabaseCount('Pago_Movimientos_Courier', 7);
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
        DB::table('Pago_Movimientos_Courier')->where('seguimiento_paquete', 'veinte')->update(['peso_final' => 1]);
        DB::table('movimientos_courier')->where('tracking_number', '4N202607010001-111')->update(['peso_final' => 1]);

        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607']))->assertOk()->assertSee('Asignar Pagos');
        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202607'])
            ->assertRedirect()->assertSessionHas('status');
        foreach (['uno' => 100, 'veinte' => 1000, 'veintitres' => 1150] as $tracking => $value) {
            $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => $tracking, 'condicion_pago' => 'SI', 'valor' => $value]);
        }
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => 'no', 'condicion_pago' => 'NO', 'valor' => null]);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => 'veinte', 'peso_final' => 20, 'condicion_pago' => 'SI', 'valor' => 1000]);
        $this->assertDatabaseHas('movimientos_courier', ['tracking_number' => '4N202607010001-111', 'peso_final' => 20]);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => 'protegido', 'condicion_pago' => 'NO', 'valor' => null]);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => 'sin_llave', 'condicion_pago' => null, 'valor' => null]);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => 'sin_tarifa', 'condicion_pago' => null, 'valor' => null]);
        CostCenterWeightRate::query()->where('cost_center_code', 98)->where('final_weight', 20)->update(['value' => 2000]);
        $this->post(route('provider-payments.courier-movements.compile.payments.assign'), ['period' => '202607'])->assertRedirect();
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => 'veinte', 'condicion_pago' => 'SI', 'valor' => 2000]);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => 'veintitres', 'condicion_pago' => 'SI', 'valor' => 2150]);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => 'protegido', 'condicion_pago' => 'NO', 'valor' => null]);
        $this->assertDatabaseCount('Pago_Movimientos_Courier', 7);
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
            ->assertSee('Generar 1 llave faltante del período');
        $this->post(route('provider-payments.courier-movements.compile.keys.generate'), ['period' => '202607'])
            ->assertRedirect(route('provider-payments.courier-movements.compile.keys.review', ['period' => '202607']));
        $this->assertDatabaseHas('llave_centro_costos', ['provider_id' => $missing->id, 'client_id' => $client->id, 'service_type_id' => $service->id, 'agent_name' => 'Operador Sin Llave', 'cost_center_code' => 0, 'payment_status' => 'NO', 'is_active' => false]);
        $this->post(route('provider-payments.courier-movements.compile.keys.generate'), ['period' => '202607'])->assertRedirect();
        $this->assertSame(1, CostCenterKey::query()->where('provider_id', $missing->id)->count());
        $draft = CostCenterKey::query()->where('provider_id', $missing->id)->firstOrFail();
        $this->get(route('provider-payments.courier-movements.compile.keys.review', ['period' => '202607']))
            ->assertOk()->assertSee('Guardar cambios de esta página')->assertSee('Inactiva');
        $this->post(route('provider-payments.courier-movements.compile.keys.save'), [
            'period' => '202607', 'rows' => [['id' => $draft->id, 'cost_center_code' => 88, 'payment_status' => 'SI', 'is_active' => 1]],
        ])->assertRedirect(route('provider-payments.courier-movements.compile.keys.review', ['period' => '202607', 'provider' => '', 'page' => 1]));
        $this->assertDatabaseHas('llave_centro_costos', ['id' => $draft->id, 'cost_center_code' => 88, 'payment_status' => 'SI', 'is_active' => true]);
        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607']))
            ->assertOk()->assertSee('Revisar Inconsistencias Llave CC (0)');
        $this->post(route('provider-payments.courier-movements.compile.keys.save'), [
            'period' => '202607', 'rows' => [['id' => $draft->id, 'cost_center_code' => 88, 'payment_status' => 'NO', 'is_active' => 0]],
        ])->assertRedirect();
        $this->assertDatabaseHas('llave_centro_costos', ['id' => $draft->id, 'payment_status' => 'NO', 'is_active' => false]);
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
            $movement = CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => $tracking, 'nombre_proceso' => '202607-Variable']);
            CourierPaymentMovement::create(['tenant_id' => $tenant->id, 'courier_movement_id' => $movement->id, 'periodo' => '202607', 'nombre_proceso' => 'Variable', 'tipo_pago' => 'Variable', 'seguimiento_paquete' => $tracking, 'peso_final' => 1, 'zona' => $zone, 'comuna_matriz' => $matrix, 'comerciante_pila' => $merchant, 'rut_cliente' => $rut, 'razon_social_cliente' => $legal, 'razon_social_proveedor' => 'Proveedor '.$legal, 'nombre_operacional' => $operational, 'tipo_documento' => $document, 'nombre_repartidor' => $courierName, 'empresa_mandante' => $company, 'condicion_pago' => $zone === 'RM' ? 'NO' : null]);
        }
        CourierPaymentMovement::query()->where('seguimiento_paquete', '4N202607010001-111')->update(['valor' => 1234567]);

        foreach (['zone' => 'RM', 'matrix' => '4N RM', 'client' => 'Cliente Alfa', 'payment_condition' => 'NO', 'provider_legal_name' => 'Proveedor Alfa SPA', 'operational_name' => 'Operador Alfa', 'document_type' => 'Factura', 'courier_name' => 'Repartidor Alfa', 'company' => '4N'] as $filter => $value) {
            $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607', $filter => $value]))
                ->assertOk()->assertSee('Registros trabajados (1)')->assertSee('4N202607010001-111')->assertDontSee('4N202607010002-222')
                ->assertSee('<select name="'.$filter.'">', false);
        }
        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607', 'zone' => 'RM', 'client' => 'Cliente Beta']))
            ->assertOk()->assertSee('Registros trabajados (0)');
        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607', 'zone' => 'RM']))
            ->assertOk()->assertSee('$ 1.234.567')->assertSee('<option value="Cliente Alfa"', false)
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

    public function test_rm_and_temuco_provider_button_updates_only_exact_courier_assignments(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        Provider::create(['tenant_id' => $tenant->id, 'tax_id' => '77346078-7', 'tax_id_number' => '77346078', 'tax_id_check_digit' => '7', 'legal_name' => '4N', 'operational_name' => '4N RM', 'operator_type' => 'Courier']);
        Provider::create(['tenant_id' => $tenant->id, 'tax_id' => '78350442-1', 'tax_id_number' => '78350442', 'tax_id_check_digit' => '1', 'legal_name' => 'Nuevo proveedor SPA', 'operational_name' => 'Claudio Operacional', 'operator_type' => 'Courier', 'tax_document_type' => 'Factura']);
        DB::table('Proveedores_usuarios_4N')->insert([
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

        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['periodo' => '202607', 'nombre_repartidor' => 'Claudio González', 'rut_proveedor' => '78350442-1', 'razon_social_proveedor' => 'Nuevo proveedor SPA', 'nombre_operacional' => 'Claudio Operacional', 'tipo_documento' => 'Factura']);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['periodo' => '202607', 'nombre_repartidor' => '4N-Demo', 'rut_proveedor' => '77346078-7']);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['periodo' => '202608', 'nombre_repartidor' => 'Claudio González', 'rut_proveedor' => '77346078-7']);
        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202607']))
            ->assertOk()->assertSee('Proveedores RM y Temuco (0)')->assertSee('1 con N/A')
            ->assertSee('No quedan registros con asignación válida para actualizar.');
        $this->assertDatabaseCount('movimientos_courier', 3);
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

        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['periodo' => '202607', 'estado_envio' => 'Anulado', 'condicion_pago' => 'NO']);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['periodo' => '202607', 'estado_envio' => 'Entregado', 'condicion_pago' => null]);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['periodo' => '202608', 'estado_envio' => 'Anulado', 'condicion_pago' => null]);
        $this->post(route('provider-payments.courier-movements.compile.store'), ['period' => '202607', 'processes' => ['Variable']])->assertRedirect();
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['periodo' => '202607', 'estado_envio' => 'Anulado', 'condicion_pago' => 'NO']);
        $this->assertDatabaseCount('Pago_Movimientos_Courier', 3);
        $this->assertDatabaseCount('movimientos_courier', 3);
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

        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['periodo' => '202607', 'razon_social_proveedor' => '4 Nortes Logistica SPA', 'condicion_pago' => 'NO']);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['periodo' => '202607', 'razon_social_proveedor' => 'Otro proveedor SPA', 'condicion_pago' => null]);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['periodo' => '202608', 'razon_social_proveedor' => '4 Nortes Logistica SPA', 'condicion_pago' => null]);
        $this->assertDatabaseCount('Pago_Movimientos_Courier', 3);
        $this->assertDatabaseCount('movimientos_courier', 3);
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

        $this->assertDatabaseCount('Pago_Movimientos_Courier', 2);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['courier_movement_id' => $matched->id, 'zona' => 'Z1', 'comuna_matriz' => 'Valparaíso', 'periodo' => '202608', 'nombre_proceso' => 'Variable', 'seguimiento_paquete' => '4N202608050001-111', 'peso_final' => 8, 'rut_cliente' => '11111111-1', 'rut_proveedor' => '22222222-2', 'empresa_mandante' => '4N']);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['seguimiento_paquete' => '4N202608050003-333', 'zona' => 'RM', 'comuna_matriz' => '4N RM', 'rut_proveedor' => '22222222-2', 'peso_final' => 7]);
        $this->assertSame('Calle 1', CourierPaymentMovement::query()->where('courier_movement_id', $matched->id)->firstOrFail()->direccion);
        $this->assertSame(8, $matched->fresh()->peso_real);
        $this->post(route('provider-payments.courier-movements.compile.store'), ['period' => '202608', 'processes' => ['Variable']])->assertRedirect();
        $this->assertDatabaseCount('Pago_Movimientos_Courier', 2);
        $this->post(route('provider-payments.courier-movements.compile.store'), ['period' => '202608', 'processes' => ['Lanas']])->assertRedirect();
        $this->get(route('provider-payments.courier-movements.compile', ['period' => '202608']))
            ->assertOk()->assertSee('202608-Variable')->assertSee('202608-Lanas')->assertSee('Eliminar proceso');
        $this->delete(route('provider-payments.courier-movements.compile.destroy'), ['period' => '202608', 'process' => 'Variable'])->assertRedirect();
        $this->assertDatabaseCount('Pago_Movimientos_Courier', 1);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['nombre_proceso' => 'Lanas', 'periodo' => '202608']);
        $this->assertDatabaseCount('movimientos_courier', 3);
    }
}
