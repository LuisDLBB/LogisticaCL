<?php

namespace Database\Seeders;

use App\Models\TipoEnvio;
use Illuminate\Database\Seeder;

class TipoEnvioSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $shippingTypes = [
            [
                'tipo_envio' => 'B2C',
                'glosa' => 'Business to Business',
                'detalle' => 'Venta al consumidor final con foco en volumen',
                'ejemplo' => 'Conversión, logística, servicio y devoluciones',
            ],
            [
                'tipo_envio' => 'B2B',
                'glosa' => 'Business to Consumer',
                'detalle' => 'Venta entre empresas con condiciones comerciales',
                'ejemplo' => 'Catálogo, stock, precios por cliente y procesos de compra',
            ],
            [
                'tipo_envio' => 'D2C',
                'glosa' => 'Direct to Consumer',
                'detalle' => 'Venta directa de marca a consumidor',
                'ejemplo' => 'Operación completa y capacidad de fidelización',
            ],
        ];

        foreach ($shippingTypes as $shippingType) {
            TipoEnvio::query()->updateOrCreate(
                ['tipo_envio' => $shippingType['tipo_envio']],
                $shippingType,
            );
        }
    }
}
