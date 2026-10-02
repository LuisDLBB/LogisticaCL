<?php

namespace Database\Seeders;

use App\Models\Provider;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CalamaProviderTransitionSeeder extends Seeder
{
    private const COMMUNES = ['Calama', 'San Pedro De Atacama', 'Sierra Gorda', 'Tocopilla'];

    public function run(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $rules = $this->rules();

        DB::transaction(function () use ($tenant, $rules): void {
            $victor = Provider::query()->where('tenant_id', $tenant->id)->where('tax_id', '13013180-8')->firstOrFail();
            $marcelo = Provider::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'tax_id' => '13172671-6'],
                [
                    'tax_id_number' => '13172671',
                    'tax_id_check_digit' => '6',
                    'legal_name' => 'Marcelo Alejandro Avendaño Tapia',
                    'operational_name' => 'Marcelo Avendaño (Calama)',
                    'operator_type' => 'Regiones',
                    'tax_document_type' => 'Factura',
                    'is_active' => true,
                ],
            );

            DB::table('PPR_coverages')->where('tenant_id', $tenant->id)
                ->whereIn('commune_name', self::COMMUNES)
                ->update([
                    'provider_id' => $marcelo->id,
                    'provider_tax_id' => $marcelo->tax_id,
                    'provider_name_source' => $marcelo->operational_name,
                    'matrix_commune_name' => $marcelo->operational_name,
                    'updated_at' => now(),
                ]);

            $clients = DB::table('MBA_clients')->where('tenant_id', $tenant->id)->pluck('id', 'tax_id');
            $services = DB::table('PPR_service_types')->pluck('id', 'service_code');
            foreach ($rules as $rule) {
                foreach ([$victor, $marcelo] as $provider) {
                    $identity = [
                        'tenant_id' => $tenant->id,
                        'provider_tax_id' => $provider->tax_id,
                        'client_tax_id' => $rule['RutCliente'],
                        'service_code' => (int) $rule['IDServicio'],
                        'merchant_name' => $rule['Comerciante'],
                    ];
                    if ($provider->is($victor) && DB::table('PPR_llave_centro_costos')->where($identity)->exists()) {
                        continue;
                    }
                    $values = [
                        'provider_id' => $provider->id,
                        'client_id' => $clients->get($rule['RutCliente']),
                        'service_type_id' => $services->get((int) $rule['IDServicio']),
                        'agent_name' => $provider->operational_name,
                        'service_name' => $rule['Servicio'],
                        'key_code' => implode('/', [$provider->tax_id, $rule['RutCliente'], $rule['IDServicio']]),
                        'key_text' => $provider->operational_name.$rule['Comerciante'].$rule['Servicio'],
                        'payment_status' => $rule['Pagar'],
                        'cost_center_code' => $rule['CentroCosto'] === '' ? null : (int) $rule['CentroCosto'],
                        'is_active' => $rule['Activo'] === '1',
                        'updated_at' => now(),
                    ];
                    DB::table('PPR_llave_centro_costos')->updateOrInsert($identity, $values + ['created_at' => now()]);
                }
            }
        });
    }

    private function rules(): array
    {
        $file = fopen(database_path('data/calama_provider_keys.tsv'), 'rb');
        if ($file === false) {
            throw new RuntimeException('No se pudo leer el respaldo de las llaves de Calama.');
        }

        try {
            $headers = fgetcsv($file, 0, "\t");
            $rules = [];
            while (($values = fgetcsv($file, 0, "\t")) !== false) {
                $rules[] = array_combine($headers, $values);
            }
        } finally {
            fclose($file);
        }

        if (count($rules) !== 88) {
            throw new RuntimeException('El respaldo de Calama debe contener 88 reglas de pago.');
        }

        return $rules;
    }
}
