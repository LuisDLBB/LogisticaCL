<?php

namespace Database\Factories;

use App\Models\Coverage;
use App\Models\Provider;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coverage>
 */
class CoverageFactory extends Factory
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
            'provider_id' => Provider::factory(),
            'commune_name' => fake()->city(),
            'matrix_commune_name' => fake()->city(),
            'provider_tax_id' => fake()->numerify('########-#'),
            'provider_name_source' => fake()->company(),
            'zone' => fake()->randomElement(['RM', 'Regiones']),
            'return_payment_applies' => false,
            'return_value' => '1000.00',
            'delivery_frequency' => 'LUNES A VIERNES',
            'delivery_type' => 'Entrega normal',
            'region_code' => fake()->numberBetween(1, 16),
            'route_code' => fake()->bothify('###-Ruta'),
            'consideration_code' => fake()->numberBetween(1, 2),
            'base_commune_name' => fake()->city(),
            'trunk_name' => fake()->words(3, true),
            'post_name' => fake()->words(3, true),
            'trunk_delivery_order' => fake()->numberBetween(0, 12),
            'is_active' => true,
        ];
    }
}
