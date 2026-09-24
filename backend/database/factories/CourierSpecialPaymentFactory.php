<?php

namespace Database\Factories;

use App\Models\CourierSpecialPayment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourierSpecialPayment>
 */
class CourierSpecialPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-2 months', 'now');

        return [
            'tenant_id' => Tenant::factory(),
            'periodo' => $date->format('Ym').'-Especiales',
            'fecha' => $date,
            'usuario_ingresa' => fake()->name(),
            'autoriza' => fake()->name(),
            'agente' => fake()->name(),
            'zona_tipo' => 'REG',
            'codigo_seguimiento' => fake()->unique()->bothify('4N############-###'),
            'localidad' => fake()->city(),
            'cliente' => fake()->company(),
            'descripcion' => fake()->sentence(),
            'monto' => fake()->numberBetween(1000, 50000),
            'archivo_origen' => 'prueba.xlsx',
            'hash_archivo' => hash('sha256', fake()->uuid()),
            'fila_origen' => 2,
        ];
    }
}
