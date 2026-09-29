<?php

namespace Database\Factories;

use App\Models\ApoyoAlza;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApoyoAlza>
 */
class ApoyoAlzaFactory extends Factory
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
            'periodo' => '202608',
            'nombre_proceso' => '202608-Apoyo',
            'proveedor_origen' => fake()->company(),
            'proceso_base' => 'Variables',
            'servicio_acuerdo' => 'Variable',
            'factor' => '%',
            'porcentaje' => '0.070000',
            'empresa_mandante' => '4N',
            'agencia' => fake()->city(),
            'fila_origen' => fake()->unique()->numberBetween(2, 50000),
        ];
    }
}
