<?php

namespace Database\Factories;

use App\Models\CostCenterKey;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CostCenterKey>
 */
class CostCenterKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'provider_tax_id' => fake()->numerify('########-#'),
            'agent_name' => fake()->company(),
            'client_tax_id' => fake()->numerify('########-#'),
            'merchant_name' => fake()->company(),
            'service_code' => fake()->numberBetween(1, 34),
            'service_name' => 'Servicio Standar',
            'key_code' => fake()->bothify('########-#/########-#/##'),
            'payment_status' => fake()->randomElement(['SI', 'NO', 'REVISAR']),
            'cost_center_code' => fake()->numberBetween(0, 20),
            'is_active' => true,
        ];
    }
}
