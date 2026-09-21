<?php

namespace Database\Factories;

use App\Models\TipoCuentaBancaria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TipoCuentaBancaria>
 */
class TipoCuentaBancariaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id_tipo_cuenta' => fake()->unique()->numberBetween(100, 999),
            'tipo_cuenta' => fake()->unique()->words(2, true),
        ];
    }
}
