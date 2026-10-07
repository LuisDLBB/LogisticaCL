<?php

namespace Tests\Feature;

use App\Models\CostCenter;
use App\Models\CostCenterWeightRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\ProviderPaymentsWorkflowTestCase;

class CostCenterWeightRateMaintainerTest extends ProviderPaymentsWorkflowTestCase
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
            ->assertSee('Crear nuevo centro de costo')->assertSee('Agregar tarifas de 1 a 20 kg')
            ->assertSee('name="values[1]"', false)->assertSee('name="values[20]"', false);

        $this->put(route('provider-payments.maintainers.tarifas-cc.update', $northRate), ['value' => 1500, 'is_active' => false])
            ->assertRedirect(route('provider-payments.maintainers.tarifas-cc', ['center' => 901]));
        $this->assertDatabaseHas('PPR_cost_center_weight_rates', ['id' => $northRate->id, 'value' => 1500, 'is_active' => false]);

        $values = array_fill_keys(range(1, 20), 1800);
        $values[2] = 1500;
        $this->post(route('provider-payments.maintainers.tarifas-cc.store'), ['cost_center_code' => 901, 'values' => $values])
            ->assertRedirect(route('provider-payments.maintainers.tarifas-cc', ['center' => 901]));
        $this->assertDatabaseHas('PPR_cost_center_weight_rates', ['cost_center_code' => 901, 'final_weight' => 3, 'value' => 1800]);
        $this->assertDatabaseHas('PPR_cost_center_weight_rates', ['id' => $northRate->id, 'value' => 1500, 'is_active' => false]);
        $this->assertSame(20, CostCenterWeightRate::query()->where('cost_center_code', 901)->count());
        $values[3] = 1900;
        $this->post(route('provider-payments.maintainers.tarifas-cc.store'), ['cost_center_code' => 901, 'values' => $values])->assertRedirect();
        $this->assertDatabaseHas('PPR_cost_center_weight_rates', ['cost_center_code' => 901, 'final_weight' => 3, 'value' => 1900]);
        $this->assertSame(20, CostCenterWeightRate::query()->where('cost_center_code', 901)->count());
        $this->assertDatabaseHas('PPR_cost_center_weight_rates', ['cost_center_code' => 902, 'final_weight' => 2, 'value' => 2250]);
        unset($values[10]);
        $this->post(route('provider-payments.maintainers.tarifas-cc.store'), ['cost_center_code' => 901, 'values' => $values])
            ->assertSessionHasErrors('values.10');
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
