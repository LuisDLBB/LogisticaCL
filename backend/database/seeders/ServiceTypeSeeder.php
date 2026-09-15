<?php

namespace Database\Seeders;

use App\Models\ServiceType;
use Illuminate\Database\Seeder;

class ServiceTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $serviceTypes = [
            1 => 'Abastecimiento de insumos', 2 => 'Abastecimiento Uniformes', 3 => 'Catalogo Publicitario',
            4 => 'Correspondencia diaria', 5 => 'Correspondencia diaria (Valijas)', 6 => 'Material Publicitario',
            7 => 'Retiro en ruta', 8 => 'Retornos', 9 => 'Servicio Sameday', 10 => 'Servicio Spot (Cotizacion)',
            11 => 'Servicio Standar', 12 => 'Servicio Standar (Alvi)', 13 => 'Servicio Standar (Compra Agil)',
            14 => 'Servicio Standar (Condenast)', 15 => 'Servicio Standar (Correspondencia)',
            16 => 'Servicio Standar (Ecommerce)', 17 => 'Servicio Standar (Fucoa)',
            18 => 'Servicio Standar (GMPI)', 19 => 'Servicio Standar (Kit de ingreso)',
            20 => 'Servicio Standar (Lanzado)', 21 => 'Servicio Standar (Mayorista)',
            22 => 'Servicio Standar (Of. Central)', 23 => 'Servicio Standar (Proveedores)',
            24 => 'Servicio Standar (Providencia)', 25 => 'Servicio Standar (R. Carabineros de chile)',
            26 => 'Servicio Standar (R. Los Heroes)', 27 => 'Servicio Standar (R. Tiendas)',
            28 => 'Servicio Standar (Retail)', 29 => 'Servicio Standar (Retorno)',
            30 => 'Servicio Standar (SAC)', 31 => 'Servicio Standar (Stgo Alonso)',
            32 => 'Servicio Standar (Tecnologia)', 33 => 'Servicio Standar (V. Trabajadores)',
            34 => 'Servicio Standar (Valijas)',
        ];

        foreach ($serviceTypes as $serviceCode => $name) {
            ServiceType::query()->updateOrCreate(
                ['service_code' => $serviceCode],
                ['name' => $name, 'is_active' => true],
            );
        }
    }
}
