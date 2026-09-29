<?php

namespace Database\Factories;

use App\Models\RutaCvFrequency;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RutaCvFrequency>
 */
class RutaCvFrequencyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::query()->where('code', '4N')->firstOrFail()->id,
            'name' => 'Ruta Lu a Vi',
            'name_key' => fake()->unique()->slug(),
            'weekdays' => [1, 2, 3, 4, 5],
        ];
    }
}
