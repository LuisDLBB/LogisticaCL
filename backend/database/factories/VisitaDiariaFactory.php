<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\VisitaDiaria;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VisitaDiaria> */
class VisitaDiariaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::query()->where('code', '4N')->firstOrFail()->id,
            'periodo' => '202608', 'nombre_proceso' => '202608-Visitas', 'visit_key' => fake()->uuid(),
            'local' => 'Cruz Verde', 'nombre_local' => 'Local 100', 'direccion' => 'Calle 100',
            'comuna' => 'Santiago', 'frecuencia' => 'Miercoles', 'estatus_origen' => 'Activo',
            'valor_dia' => 1000, 'dias' => [5, 12, 19, 26], 'total_mensual' => 4000,
        ];
    }
}
