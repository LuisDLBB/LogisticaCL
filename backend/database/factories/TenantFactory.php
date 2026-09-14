<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'code' => fake()->unique()->bothify('TENANT-###'),
            'name' => fake()->company(),
            'legal_name' => fake()->company(),
            'tax_id' => fake()->numerify('########-#'),
            'timezone' => 'America/Santiago',
            'is_active' => true,
        ];
    }
}
