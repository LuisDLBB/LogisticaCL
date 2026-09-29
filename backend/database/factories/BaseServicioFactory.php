<?php

namespace Database\Factories;

use App\Models\BaseServicio;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BaseServicio>
 */
class BaseServicioFactory extends Factory
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
            'periodo_origen' => 'AGOSTO 2026 - SERVICIOS',
            'nombre_proceso' => '202608-Servicios',
            'zona' => 'RM',
            'tipo_pago' => 'Servicios',
            'fecha_carga' => '2026-08-01',
            'direccion' => 'Servicios - Transporte',
            'comuna_destino' => 'Santiago',
            'cliente_origen' => 'Cruz Verde',
            'servicio' => 'Servicios 4N',
            'peso' => 1,
            'estado_envio' => 'Entregado',
            'valor_final' => 10000,
            'operador' => '4N RM',
            'usuario' => 'Repartidor',
            'transportista' => 'Proveedor',
            'razon_social_proveedor_origen' => 'Proveedor SpA',
            'empresa' => '4N',
            'archivo_origen' => 'servicios.xlsx',
            'hash_archivo' => hash('sha256', fake()->uuid()),
            'fila_origen' => 2,
        ];
    }
}
