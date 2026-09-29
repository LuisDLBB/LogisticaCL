<?php

namespace Database\Seeders;

use App\Models\CostCenterKey;
use App\Models\Provider;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ClaudioCuevasPaymentKeysSeeder extends Seeder
{
    private const SOURCE_RUT = '77346078-7';

    private const TARGET_RUT = '12538127-8';

    private const SOURCE_AGENT = '4N Temuco';

    private const RULES = [
        ['89807200-2', 7],  // Cruz Verde: Retiro en ruta
        ['89807200-2', 11], // Cruz Verde: Servicio Standar
        ['81826800-9', 31], // Caja Los Andes: Servicio Standar (Stgo Alonso)
        ['90106000-2', 11], // Sistemas Gráficos Quilicura: Servicio Standar
    ];

    public function run(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $provider = Provider::query()->where('tenant_id', $tenant->id)
            ->where('tax_id', self::TARGET_RUT)->firstOrFail();

        DB::transaction(function () use ($tenant, $provider): void {
            foreach (self::RULES as [$clientRut, $serviceCode]) {
                $identity = [
                    'tenant_id' => $tenant->id,
                    'provider_tax_id' => self::TARGET_RUT,
                    'client_tax_id' => $clientRut,
                    'service_code' => $serviceCode,
                ];
                if (CostCenterKey::query()->where($identity)->exists()) {
                    continue;
                }

                $source = CostCenterKey::query()->where('tenant_id', $tenant->id)
                    ->where('provider_tax_id', self::SOURCE_RUT)
                    ->where('agent_name', self::SOURCE_AGENT)
                    ->where('client_tax_id', $clientRut)
                    ->where('service_code', $serviceCode)
                    ->where('is_active', true)->get();
                if ($source->count() !== 1) {
                    throw new RuntimeException("Se esperaba una regla única de 4N Temuco para {$clientRut}/{$serviceCode}.");
                }

                $rule = $source->first();
                CostCenterKey::query()->create([
                    ...$identity,
                    'provider_id' => $provider->id,
                    'client_id' => $rule->client_id,
                    'service_type_id' => $rule->service_type_id,
                    'agent_name' => $provider->operational_name,
                    'merchant_name' => $rule->merchant_name,
                    'service_name' => $rule->service_name,
                    'key_code' => implode('/', [self::TARGET_RUT, $clientRut, $serviceCode]),
                    'key_text' => $provider->operational_name.$rule->merchant_name.$rule->service_name,
                    'payment_status' => $rule->payment_status,
                    'cost_center_code' => $rule->cost_center_code,
                    'is_active' => true,
                ]);
            }
        });
    }
}
