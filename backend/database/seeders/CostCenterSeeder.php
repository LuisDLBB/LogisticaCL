<?php

namespace Database\Seeders;

use App\Models\CostCenter;
use Illuminate\Database\Seeder;

class CostCenterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $costCenters = [
            0 => ['Sin Costo', 0], 1 => ['RM/RMP', 115], 2 => ['STANDART REGIONES', 125],
            3 => ['TALAGANTE/MELIPILLA', 80], 4 => ['LANA RM ECOMMERCE, RETAIL Y MAYORISTA', 0],
            5 => ['LANA REGIONES ECOMMERCE Y SAC', 0], 6 => ['LANA RETAIL REGIONES', 0],
            7 => ['RETIRO Y RETORNOS REGIONES NORMAL', 0], 8 => ['Temuco', 75],
            9 => ['RETORNO VALDIVIA', 0],
            10 => ['CLIENTES FIJOS NUEVOS PROVEEDORES (POSTMANCARGO (IQUIQUE), CANEO, FIGUEROA, VALENCIA Y ROBLEDO)', 120],
            11 => ['CLIENTES VARIABLES NUEVOS PROVEEDORES (POSTMANCARGO (IQUIQUE), CANEO, FIGUEROA, VALENCIA Y ROBLEDO)', 120],
            12 => ['TARIFA CELIA LEON (4,5% de tarifa 2)', 129],
            13 => ['CLIENTES FIJOS NUEVOS PROVEEDORES - POSTMANCARGO (ALTO HOSPICIO)', 120],
            14 => ['CLIENTES VARIABLES NUEVOS PROVEEDORES - POSTMANCARGO (ALTO HOSPICIO)', 120],
            15 => ['REVISTA AMBIENTES', 0], 16 => ['TARIFA SAN FERNANDO, PUNTA ARENAS Y BALMACEDA 20%', 150],
        ];

        foreach ($costCenters as $costCenterCode => [$dispatchGuideDetail, $additionalKiloValue]) {
            CostCenter::query()->updateOrCreate(
                ['cost_center_code' => $costCenterCode],
                ['dispatch_guide_detail' => $dispatchGuideDetail, 'additional_kilo_value' => $additionalKiloValue, 'is_active' => true],
            );
        }
    }
}
