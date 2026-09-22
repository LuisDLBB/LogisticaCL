<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\Coverage;
use App\Models\Provider;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourierMovementCompileTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_processes_are_compiled_without_changing_source_weights(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $client = Client::create(['tenant_id' => $tenant->id, 'tax_id' => '11111111-1', 'tax_id_number' => '11111111', 'tax_id_check_digit' => '1', 'commercial_name' => 'Comerciante', 'source_merchant_name' => 'Pila', 'legal_name' => 'Cliente SA']);
        $provider = Provider::create(['tenant_id' => $tenant->id, 'tax_id' => '22222222-2', 'tax_id_number' => '22222222', 'tax_id_check_digit' => '2', 'legal_name' => 'Proveedor SA', 'operational_name' => 'Repartidor Norte', 'operator_type' => 'Courier', 'tax_document_type' => 'Factura']);
        Coverage::create(['tenant_id' => $tenant->id, 'provider_id' => $provider->id, 'commune_name' => 'Viña del Mar', 'zone' => 'Z1']);
        $matched = CourierMovement::create(['tenant_id' => $tenant->id, 'client_id' => $client->id, 'tracking_number' => '4N202608050001-111', 'tracking_code' => '4N202608050001', 'nombre_proceso' => '202608-Variable', 'tipo_pago' => 'Variable', 'fecha' => '2026-08-05', 'recipient_address' => 'Calle 1', 'destination_commune_name' => 'Vina del Mar', 'peso_real' => 8, 'peso_transformado' => 5, 'status' => 'Entregado']);
        CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => '4N202608050002-222', 'nombre_proceso' => '202608-Lanas', 'peso_transformado' => 7]);
        CourierMovement::create(['tenant_id' => $tenant->id, 'tracking_number' => '4N202608050003-333', 'nombre_proceso' => '202608-Variable', 'peso_transformado' => 7]);

        $this->get(route('provider-payments.courier-movements.compile'))->assertOk()->assertSee('Trabajar Registros de Courier');
        $this->get(route('provider-payments.courier-movements.compile.work', ['period' => '202608']))->assertOk()->assertSee('Variable')->assertSee('Lanas');
        $this->post(route('provider-payments.courier-movements.compile.store'), ['period' => '202608', 'processes' => ['Variable']])->assertRedirect();

        $this->assertDatabaseCount('Pago_Movimientos_Courier', 2);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['courier_movement_id' => $matched->id, 'zona' => 'Z1', 'periodo' => '202608', 'nombre_proceso' => 'Variable', 'peso_final' => 5, 'rut_cliente' => '11111111-1', 'rut_proveedor' => '22222222-2', 'empresa_mandante' => '4N']);
        $this->assertDatabaseHas('Pago_Movimientos_Courier', ['codigo_seguimiento' => null, 'peso_final' => 1]);
        $this->assertSame('Calle 1', CourierPaymentMovement::query()->where('courier_movement_id', $matched->id)->firstOrFail()->direccion);
        $this->assertSame(8, $matched->fresh()->peso_real);
        $this->post(route('provider-payments.courier-movements.compile.store'), ['period' => '202608', 'processes' => ['Variable']])->assertRedirect();
        $this->assertDatabaseCount('Pago_Movimientos_Courier', 2);
    }
}
