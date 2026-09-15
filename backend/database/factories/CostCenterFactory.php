<?php

namespace Database\Factories;

use App\Models\CostCenter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CostCenter>
 */
class CostCenterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cost_center_code' => fake()->unique()->numberBetween(100, 999),
            'dispatch_guide_detail' => fake()->sentence(),
            'additional_kilo_value' => fake()->numberBetween(0, 200),
            'is_active' => true,
        ];
    }
}
