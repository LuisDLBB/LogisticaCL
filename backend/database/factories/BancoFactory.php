<?php

namespace Database\Factories;

use App\Models\Banco;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Banco>
 */
class BancoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id_banco' => fake()->unique()->numberBetween(100, 999),
            'banco' => fake()->unique()->company(),
            'codigo_sbif' => fake()->unique()->numberBetween(1, 999),
            'nombre_entidad_financiera' => fake()->company(),
            'marcas_productos_asociados' => fake()->words(3, true),
            'is_active' => true,
        ];
    }
}
