<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OperationsTransportSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = DB::table('MBA_tenants')->where('code', '4N')->value('id');
        if ($tenantId === null) {
            return;
        }

        $source = json_decode(
            file_get_contents(database_path('data/operations_transport_routes_20261002.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (count($source['trunks']) !== 8 || count($source['posts']) !== 26 || count($source['agencies']) !== 34) {
            throw new RuntimeException('El catálogo de transporte de Operaciones está incompleto.');
        }

        DB::transaction(function () use ($tenantId, $source): void {
            $now = now();
            $drivers = [];
            foreach ($source['trunks'] as $trunk) {
                $this->addDriver($drivers, $trunk[7], $trunk[8]);
            }
            foreach ($source['posts'] as $post) {
                $this->addDriver($drivers, $post[4], $post[5]);
            }

            $users = DB::table('MBA_users')->whereIn('tax_id', array_keys($drivers))->pluck('id', 'tax_id');
            foreach ($drivers as $rut => $name) {
                DB::table('Ope_Choferes')->insertOrIgnore([
                    'tenant_id' => $tenantId,
                    'user_id' => $users->get($rut),
                    'rut' => $rut,
                    'name' => $name,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $driverIds = DB::table('Ope_Choferes')->where('tenant_id', $tenantId)->pluck('id', 'rut');
            $vehicleIds = DB::table('MBA_vehicles')->where('tenant_id', $tenantId)->pluck('id', 'plate');
            foreach ($source['trunks'] as [$code, $name, $originAddress, $originCommune, $destinationAddress, $destinationCommune, $plate, $rut]) {
                DB::table('Ope_Troncales')->insertOrIgnore([
                    'tenant_id' => $tenantId,
                    'trunk_code' => $code,
                    'name' => $name,
                    'origin_address' => $originAddress,
                    'origin_commune' => $originCommune,
                    'destination_address' => $destinationAddress,
                    'destination_commune' => $destinationCommune,
                    'plate' => $plate,
                    'vehicle_id' => $plate === null ? null : $vehicleIds->get($plate),
                    'driver_id' => $rut === null ? null : $driverIds->get($rut),
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($source['posts'] as [$code, $name, $isActive, $plate, $rut]) {
                DB::table('Ope_Postas')->insertOrIgnore([
                    'tenant_id' => $tenantId,
                    'post_code' => $code,
                    'name' => $name,
                    'plate' => $plate,
                    'vehicle_id' => $plate === null ? null : $vehicleIds->get($plate),
                    'driver_id' => $rut === null ? null : $driverIds->get($rut),
                    'is_active' => $isActive,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $trunkIds = DB::table('Ope_Troncales')->where('tenant_id', $tenantId)->pluck('id', 'trunk_code');
            $postIds = DB::table('Ope_Postas')->where('tenant_id', $tenantId)->pluck('id', 'post_code');
            foreach ($source['agencies'] as [$code, $name, $address, $commune, $trunkCode, $postCode, $secondPostCode]) {
                if (! $trunkIds->has($trunkCode) || ! $postIds->has($postCode) || ($secondPostCode !== null && ! $postIds->has($secondPostCode))) {
                    throw new RuntimeException("La agencia {$code} referencia un troncal o posta inexistente.");
                }

                DB::table('Ope_Agencias')->insertOrIgnore([
                    'tenant_id' => $tenantId,
                    'agency_code' => $code,
                    'name' => $name,
                    'address' => $address,
                    'commune' => $commune,
                    'trunk_id' => $trunkIds->get($trunkCode),
                    'post_id' => $postIds->get($postCode),
                    'second_post_id' => $secondPostCode === null ? null : $postIds->get($secondPostCode),
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    private function addDriver(array &$drivers, ?string $rut, ?string $name): void
    {
        if ($rut === null) {
            return;
        }

        if ($name === null || (isset($drivers[$rut]) && $drivers[$rut] !== $name)) {
            throw new RuntimeException("El chofer {$rut} tiene nombres distintos en el catálogo de transporte.");
        }

        $drivers[$rut] = $name;
    }
}
