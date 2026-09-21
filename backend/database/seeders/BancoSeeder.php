<?php

namespace Database\Seeders;

use App\Models\Banco;
use Illuminate\Database\Seeder;

class BancoSeeder extends Seeder
{
    public function run(): void
    {
        $banks = [
            [1, 'Banco Chile', 1, 'Banco de Chile', 'Banco Edwards, CrediChile'],
            [2, 'Internacional', 9, 'Banco Internacional', 'Banco Internacional'],
            [3, 'Banco Estado', 12, 'Banco del Estado de Chile', 'BancoEstado, CuentaRUT'],
            [4, 'Scotiabank', 14, 'Scotiabank Chile', 'Scotiabank Azul'],
            [5, 'BCI', 16, 'Banco de Crédito e Inversiones', 'BCI, MACH, TBanc'],
            [6, 'Bice', 28, 'Banco BICE', 'Banco BICE'],
            [7, 'HSBC', 31, 'HSBC Bank (Chile)', 'HSBC'],
            [8, 'Santander', 37, 'Banco Santander-Chile', 'Santander, Banefe'],
            [9, 'Itau', 39, 'Banco Itaú Chile', 'Itaú, ex Corpbanca'],
            [10, 'Security', 49, 'Banco Security', 'Banco Security'],
            [11, 'Falabella', 51, 'Banco Falabella', 'Banco Falabella'],
            [12, 'Ripley', 53, 'Banco Ripley', 'Banco Ripley'],
            [13, 'Consorcio', 55, 'Banco Consorcio', 'Banco Consorcio'],
            [14, 'BTG', 56, 'Banco BTG Pactual Chile', 'BTG Pactual'],
            [15, 'CCB', 60, 'China Construction Bank, Agencia en Chile', 'CCB Chile'],
            [16, 'Bank China', 61, 'Bank of China, Agencia en Chile', 'Bank of China'],
            [17, 'Tenpo', 621, 'Tenpo Payments S.A.', 'Tenpo'],
            [18, 'Mercadopago', 622, 'Mercado Pago Emisora de Tarjetas S.A.', 'Mercado Pago'],
            [19, 'Prex', 623, 'Prex Chile S.A.', 'Prex'],
            [20, 'Los Andes', 625, 'Tapp (Caja Los Andes)', 'Tapp'],
            [21, 'Los Heroes', 619, 'Los Héroes Prepago S.A.', 'Prepago Los Héroes'],
            [22, 'Coopeuch', 671, 'Coopeuch', 'Cooperativa de Ahorro y Crédito Coopeuch (Coopeuch)'],
            [23, 'Orientacoop', 672, 'Oriencoop', 'Cooperativa de Ahorro y Crédito Oriencoop (Oriencoop)'],
            [24, 'Detacoop', 673, 'Detacoop', 'Cooperativa de Ahorro y Crédito Detacoop (Detacoop)'],
            [25, 'Ahorrocoop', 674, 'Ahorrocoop', 'Cooperativa de Ahorro y Crédito Ahorrocoop (Ahorrocoop)'],
            [26, 'Lautaro', 677, 'Lautaro Rosas', 'Cooperativa de Ahorro y Crédito Lautaro Rosas'],
        ];

        foreach ($banks as [$bankId, $bank, $sbifCode, $financialInstitutionName, $associatedBrands]) {
            Banco::query()->updateOrCreate(
                ['id_banco' => $bankId],
                [
                    'banco' => $bank,
                    'codigo_sbif' => $sbifCode,
                    'nombre_entidad_financiera' => $financialInstitutionName,
                    'marcas_productos_asociados' => $associatedBrands,
                    'is_active' => true,
                ],
            );
        }
    }
}
