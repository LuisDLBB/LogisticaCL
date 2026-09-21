<?php

namespace Database\Factories;

use App\Models\TipoEnvio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TipoEnvio>
 */
class TipoEnvioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tipo_envio' => fake()->unique()->bothify('T##'),
            'glosa' => fake()->sentence(3),
            'detalle' => fake()->sentence(),
            'ejemplo' => fake()->sentence(),
        ];
    }
}
