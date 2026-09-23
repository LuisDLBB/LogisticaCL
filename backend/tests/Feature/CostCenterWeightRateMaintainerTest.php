<?php

namespace Tests\Feature;

use App\Models\CostCenter;
use App\Models\CostCenterWeightRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CostCenterWeightRateMaintainerTest extends TestCase
{
    use RefreshDatabase;

    public function test_rates_can_be_filtered_by_cost_center_and_maintained(): void
    {
        CostCenter::create(['cost_center_code' => 901, 'dispatch_guide_detail' => 'Centro Norte', 'additional_kilo_value' => 100, 'is_active' => true]);
        CostCenter::create(['cost_center_code' => 902, 'dispatch_guide_detail' => 'Centro Sur', 'additional_kilo_value' => 200, 'is_active' => true]);
        $northRate = CostCenterWeightRate::create(['cost_center_code' => 901, 'final_weight' => 2, 'value' => 1250, 'is_active' => true]);
        CostCenterWeightRate::create(['cost_center_code' => 902, 'final_weight' => 2, 'value' => 2250, 'is_active' => true]);

        $this->get(route('provider-payments.maintainers.tarifas-cc', ['center' => 901]))
            ->assertOk()->assertSee('Tarifas CC')->assertSee('Centro Norte')->assertSee('$1.250')
            ->assertDontSee('Centro Sur</td>', false)->assertDontSee('$2.250')
            ->assertSee('Crear nuevo centro de costo')->assertSee('Agregar tarifa por kilo');

        $this->put(route('provider-payments.maintainers.tarifas-cc.update', $northRate), ['value' => 1500, 'is_active' => false])
            ->assertRedirect(route('provider-payments.maintainers.tarifas-cc', ['center' => 901]));
        $this->assertDatabaseHas('cost_center_weight_rates', ['id' => $northRate->id, 'value' => 1500, 'is_active' => false]);

        $this->post(route('provider-payments.maintainers.tarifas-cc.store'), ['cost_center_code' => 901, 'final_weight' => 3, 'value' => 1800])
            ->assertRedirect(route('provider-payments.maintainers.tarifas-cc', ['center' => 901]));
        $this->assertDatabaseHas('cost_center_weight_rates', ['cost_center_code' => 901, 'final_weight' => 3, 'value' => 1800]);
        $this->post(route('provider-payments.maintainers.tarifas-cc.store'), ['cost_center_code' => 901, 'final_weight' => 3, 'value' => 1900])
            ->assertSessionHasErrors('final_weight');
    }

    public function test_new_cost_center_from_tariffs_returns_to_its_empty_rate_list(): void
    {
        $this->post(route('provider-payments.maintainers.centro-de-costos.store'), [
            'dispatch_guide_detail' => 'Centro Nuevo de Tarifas',
            'additional_kilo_value' => 350,
            'return_to' => 'tarifas-cc',
        ])->assertRedirect();

        $center = CostCenter::query()->where('dispatch_guide_detail', 'Centro Nuevo de Tarifas')->firstOrFail();
        $this->get(route('provider-payments.maintainers.tarifas-cc', ['center' => $center->cost_center_code]))
            ->assertOk()->assertSee('Centro Nuevo de Tarifas')->assertSee('No hay tarifas para el centro de costo seleccionado.');
    }
}
