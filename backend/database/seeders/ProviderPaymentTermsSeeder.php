<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProviderPaymentTermsSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = DB::table('MBA_tenants')->where('code', '4N')->value('id');
        if ($tenantId === null) {
            throw new RuntimeException('No existe la empresa 4N para asociar condiciones de pago.');
        }

        $termsByRut = [
            '77201525-9' => 'Quincena',
            '7220635-5' => 'Contado',
            '10869353-3' => 'Quincena',
            '77656334-K' => 'Quincena',
            '17662544-9' => '1Semana',
            '78350442-1' => '1Semana',
            '76716142-5' => 'Quincena',
            '78429685-7' => 'Quincena',
            '76147222-4' => 'Quincena',
            '8960481-8' => 'Quincena',
            '11189365-9' => 'Quincena',
            '10124367-2' => '30 Días',
            '76556632-0' => '30 Días',
            '77300326-2' => '30 Días',
            '9846947-8' => '30 Días',
            '77301587-2' => '30 Días',
            '8906841-K' => '30 Días',
            '76064767-5' => '30 Días',
            '76528088-5' => '30 Días',
            '77145818-1' => '30 Días',
            '76052551-0' => '45 Días',
            '76115557-1' => '30 Días',
            '77458608-3' => '30 Días',
            '13590206-3' => '30 Días',
            '7481733-5' => '30 Días',
            '77417055-3' => 'Quincena',
            '16945660-7' => 'Quincena',
            '78269187-2' => '30 Días',
            '77946757-0' => '30 Días',
            '78138313-9' => '30 Días',
            '11866495-7' => 'Quincena',
            '77845965-5' => 'Quincena',
            '99595520-2' => 'Quincena',
            '15538591-K' => 'Quincena',
            '12388671-2' => 'Quincena',
            '78464442-1' => 'Quincena',
            '12269012-1' => 'Quincena',
            '18765018-6' => 'Quincena',
            '6889211-2' => 'Quincena',
            '9707165-9' => 'Quincena',
            '12538127-8' => 'Contado',
            '78480951-K' => 'Quincena',
            '17850968-3' => 'Quincena',
            '10320311-2' => 'Quincena',
            '78206404-5' => 'Quincena',
            '15536672-9' => 'Quincena',
            '10629309-0' => 'Contado',
            '15474387-1' => 'Quincena',
            '78207289-7' => 'Quincena',
            '11942383-K' => '30 Días',
            '17657150-0' => '30 Días',
            '19488954-2' => '30 Días',
            '13013180-8' => '30 Días',
            '77390761-7' => 'Quincena',
        ];

        foreach ($termsByRut as $rut => $terms) {
            $normalisedRut = preg_replace('/[^0-9K]/', '', strtoupper($rut));
            $number = substr($normalisedRut, 0, -1);
            $provider = DB::table('MBA_providers')->where('tenant_id', $tenantId)
                ->where('tax_id_number', $number)->first(['id', 'payment_terms', 'payment_terms_pmcb']);
            if ($provider === null) {
                throw new RuntimeException("No existe el proveedor {$rut} para registrar su condición de pago.");
            }
            $changes = [];
            if (blank($provider->payment_terms)) {
                $changes['payment_terms'] = $terms;
            }
            if ($rut === '77390761-7' && blank($provider->payment_terms_pmcb)) {
                $changes['payment_terms_pmcb'] = 'Contado';
            }
            if ($changes !== []) {
                DB::table('MBA_providers')->where('id', $provider->id)->update($changes + ['updated_at' => now()]);
            }
        }
    }
}
