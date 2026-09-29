<?php

namespace Database\Factories;

use App\Models\RutaCv;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RutaCv>
 */
class RutaCvFactory extends Factory
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
            'periodo' => '202608',
            'route_key' => fake()->uuid(),
            'zona' => 'RM',
            'frecuencia' => 'Ruta Lu a Vi',
            'facturador' => fake()->name(),
            'usuario' => fake()->name(),
            'detalle_ruta' => fake()->city(),
            'comuna' => fake()->city(),
            'producto' => 'Ruta de Cruz Verde',
            'valor' => 40000,
            'dias' => [3, 4, 5],
            'total_mensual' => 120000,
        ];
    }
}
