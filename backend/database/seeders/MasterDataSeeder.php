<?php

namespace Database\Seeders;

use App\Models\ProviderBankAccount;
use App\Modules\ProviderPayments\Services\ProviderZone;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MasterDataSeeder extends Seeder
{
    private int $tenantId;

    public function run(): void
    {
        $this->tenantId = (int) DB::table('tenants')->where('code', '4N')->value('id');
        if (! $this->tenantId) {
            return;
        }

        $this->call([ServiceTypeSeeder::class, CostCenterSeeder::class, CourierStatusSeeder::class]);
        $this->users();
        $this->vehicles();
        $this->providers();
        $this->coverages();
        $this->costCenterKeys();
        $this->clientServices();
        $this->weightRates();
        $this->weightTransformations();
        $this->externalShipments();
    }

    private function users(): void
    {
        $userIds = DB::table('tenant_users')->where('tenant_id', $this->tenantId)->pluck('user_id');
        DB::table('tenant_users')->where('tenant_id', $this->tenantId)->delete();
        DB::table('users')->whereIn('id', $userIds)->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('tenant_users')->whereColumn('tenant_users.user_id', 'users.id');
        })->delete();

        foreach ($this->rows('initial_users.tsv') as $row) {
            $sourceEmail = trim($row['Correo corporativo'] ?? '');
            $email = filter_var($sourceEmail, FILTER_VALIDATE_EMAIL)
                ? strtolower($sourceEmail)
                : strtolower(trim($row['Rut'])).'@usuarios.4n.local';
            $id = DB::table('users')->updateOrInsert(['email' => $email], [
                'name' => $row['Nombre completo'], 'tax_id' => $row['Rut'], 'username' => $row['Usuario'],
                'password' => Hash::make((string) $row['Clave Provisoria']), 'area' => $row['Área'],
                'profile_name' => $row['Perfil'], 'license_type' => $row['Tipo Licencia'],
                'locality_name' => $row['Localidad'], 'driver_license_expires_at' => $this->date($row['Vencimiento Licencia conducir']),
                'phone' => $row['Numero Telefono'], 'updated_at' => now(), 'created_at' => now(),
            ]);
            $userId = DB::table('users')->where('email', $email)->value('id');
            DB::table('tenant_users')->updateOrInsert(['tenant_id' => $this->tenantId, 'user_id' => $userId], [
                'rut_empresa' => $row['RutEmpresa'], 'role_code' => Str::slug($row['Perfil'] ?: 'operator', '_'),
                'is_active' => true, 'updated_at' => now(), 'created_at' => now(),
            ]);
        }
    }

    private function vehicles(): void
    {
        foreach ($this->rows('initial_vehicles.tsv') as $row) {
            $company = trim($row['Empresa'] ?? '');
            DB::table('vehicles')->updateOrInsert(['tenant_id' => $this->tenantId, 'plate' => $row['Patente']], [
                'rut_empresa' => $company === 'PMCB' ? '77639015-1' : '77346078-7',
                'internal_code' => $row['Patente-Consumos'] ?: $row['Patente'], 'vehicle_type' => $row['Tipo'] ?: 'Sin tipo',
                'ownership_type' => 'Propio', 'operational_status' => $row['Estado'] ?: 'Activa', 'brand' => $row['Marca'],
                'model' => $row['Modelo'], 'manufacture_year' => $this->integer($row['Año']), 'color' => $row['Color'],
                'max_weight_kg' => $this->weightKg($row['PesoMax']), 'max_pallets' => $this->integer($row['Max Pallet']),
                'insurance_expires_at' => $this->date($row['SOAP']), 'circulation_permit_expires_at' => $this->date($row['PERMISO CIRCULACION']),
                'technical_inspection_expires_at' => $this->date($row['REVISION TECNICA']), 'gas_certificate_expires_at' => $this->date($row['GASES']),
                'document_link' => $row['Link Documentos'], 'company_source' => $company, 'destination_name' => $row['Destino'],
                'capacity_m3' => $this->number($row['Capacidad m3']), 'capacity_m2' => $this->number($row['Capacidad m2']),
                'height_cm' => $this->number($row['Alto CM']), 'length_cm' => $this->number($row['Largo CM']), 'width_cm' => $this->number($row['Ancho CM']),
                'notes' => $row['Resumen'], 'is_active' => true, 'updated_at' => now(), 'created_at' => now(),
            ]);
        }
    }

    private function providers(): void
    {
        foreach ($this->rows('initial_providers.tsv') as $row) {
            [$number, $dv] = $this->rutParts($row['Rut_Proveedor']);
            $existingProvider = DB::table('providers')->where('tenant_id', $this->tenantId)
                ->where('tax_id_number', $number)->first(['id', 'contact_email']);
            DB::table('providers')->updateOrInsert(['tenant_id' => $this->tenantId, 'tax_id_number' => $number], [
                'tax_id' => strtoupper($row['Rut_Proveedor']), 'tax_id_check_digit' => $dv, 'legal_name' => $row['Razon_Social'],
                'operational_name' => $row['Operador'], 'operator_type' => $row['TipoOperador'], 'tax_document_type' => $row['Tipo_Documento'],
                'commercial_address' => $row['DireccionComercial'], 'commercial_commune_name' => $row['ComunaComercial'],
                'contact_name' => $row['NombreContacto'], 'contact_phone' => $row['TelefonoContacto'],
                'contact_email' => $existingProvider?->contact_email ?? $row['CorreoContacto'],
                'is_active' => true, 'updated_at' => now(), 'created_at' => now(),
            ]);
            $providerId = $existingProvider?->id ?? DB::table('providers')->where('tenant_id', $this->tenantId)->where('tax_id_number', $number)->value('id');
            if (trim($row['Banco'] ?? '') !== '' && ! DB::table('provider_bank_accounts')->where('provider_id', $providerId)->exists()) {
                ProviderBankAccount::query()->create([
                    'provider_id' => $providerId, 'account_number' => $row['Nro_Cuenta'],
                    'account_holder_name' => $row['Titular_Banco'], 'account_holder_tax_id' => $row['RUT_Titular_Banco'],
                    'bank_name' => $row['Banco'], 'account_type' => $row['Tipo_Cuenta'], 'is_primary' => true,
                    'is_active' => true,
                ]);
            }
        }
    }

    private function coverages(): void
    {
        foreach ($this->rows('initial_coverages.tsv') as $row) {
            $providerId = DB::table('providers')->where('tenant_id', $this->tenantId)->where('tax_id', strtoupper($row['Rut_Proveedor']))->value('id');
            DB::table('coverages')->updateOrInsert(['tenant_id' => $this->tenantId, 'commune_name' => $row['Comuna'], 'route_code' => $row['Ruta']], [
                'provider_id' => $providerId, 'matrix_commune_name' => $row['ComunaMatriz'], 'provider_tax_id' => strtoupper($row['Rut_Proveedor']),
                'provider_name_source' => $row['NombreProveedor'], 'zone' => ProviderZone::resolve($row['Rut_Proveedor'], $providerId, $row['Zona']), 'return_payment_applies' => strtoupper($row['PAGAR RETORNO']) === 'SI',
                'return_value' => $this->number($row['VALOR/RETORNO']), 'delivery_frequency' => $row['Frecuencia'], 'delivery_type' => $row['TipoEntrega'],
                'region_code' => $this->integer($row['Region']), 'consideration_code' => $this->integer($row['Considerar']),
                'aerial_commune_name' => $row['ComunaAereo'], 'aerial_route_code' => $row['ruta aerea'], 'base_commune_name' => $row['ComunaBase'],
                'trunk_name' => $row['Troncal'], 'post_name' => $row['Posta'], 'trunk_delivery_order' => $this->integer($row['OrdenEntregaTroncal']),
                'is_active' => true, 'updated_at' => now(), 'created_at' => now(),
            ]);
        }
    }

    private function costCenterKeys(): void
    {
        DB::table('llave_centro_costos')->where('tenant_id', $this->tenantId)->delete();
        $records = [];
        foreach ($this->rows('initial_cost_center_keys.tsv') as $row) {
            $providerId = DB::table('providers')->where('tenant_id', $this->tenantId)->where('tax_id', strtoupper($row['RutProveedor']))->value('id');
            $clientId = DB::table('clients')->where('tenant_id', $this->tenantId)->where('tax_id', strtoupper($row['RutCliente']))->value('id');
            $serviceId = DB::table('service_types')->where('service_code', $this->integer($row['IDServicio']))->value('id');
            $records[] = [
                'tenant_id' => $this->tenantId, 'key_code' => $row['Llave'],
                'provider_id' => $providerId, 'client_id' => $clientId, 'service_type_id' => $serviceId,
                'provider_tax_id' => strtoupper($row['RutProveedor']), 'agent_name' => $row['Agente'], 'client_tax_id' => strtoupper($row['RutCliente']),
                'merchant_name' => $row['Comerciante'], 'service_code' => $this->integer($row['IDServicio']), 'service_name' => $row['Servicio'],
                'key_text' => $row['Llave texto'], 'payment_status' => $row['Pagar'], 'cost_center_code' => $this->integer($row['CentroCosto']),
                'is_active' => true, 'updated_at' => now(), 'created_at' => now(),
            ];
        }
        foreach (array_chunk($records, 300) as $chunk) {
            DB::table('llave_centro_costos')->insert($chunk);
        }
    }

    private function weightRates(): void
    {
        DB::table('cost_center_weight_rates')->delete();
        $records = [];
        foreach ($this->rows('initial_weight_rates.tsv') as $row) {
            $records[] = [
                'cost_center_code' => $this->integer($row['CentroCosto']), 'final_weight' => $this->integer($row['PesoFinal']),
                'value' => $this->integer($row['Valor']) ?? 0, 'is_active' => true, 'updated_at' => now(), 'created_at' => now(),
            ];
        }
        foreach (array_chunk($records, 300) as $chunk) {
            DB::table('cost_center_weight_rates')->insert($chunk);
        }
    }

    private function clientServices(): void
    {
        $clientIds = DB::table('clients')->where('tenant_id', $this->tenantId)->pluck('id');
        DB::table('client_service_type')->whereIn('client_id', $clientIds)->delete();

        $relations = DB::table('llave_centro_costos')
            ->where('tenant_id', $this->tenantId)
            ->whereNotNull('client_id')
            ->whereNotNull('service_type_id')
            ->select(['client_id', 'service_type_id'])
            ->distinct()
            ->get()
            ->map(fn ($row): array => [
                'client_id' => $row->client_id,
                'service_type_id' => $row->service_type_id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

        foreach (array_chunk($relations, 300) as $chunk) {
            DB::table('client_service_type')->insert($chunk);
        }
    }

    private function externalShipments(): void
    {
        DB::table('envios_externos')->where('tenant_id', $this->tenantId)->delete();
        $records = [];
        foreach ($this->rows('initial_external_shipments.tsv') as $row) {
            $tracking = trim($row['Seguimiento']);
            if ($tracking === '') {
                continue;
            }
            $clientId = DB::table('clients')->where('tenant_id', $this->tenantId)->whereRaw('LOWER(source_merchant_name) = ?', [mb_strtolower($row['Cliente'])])->value('id');
            $records[$tracking] = [
                'tenant_id' => $this->tenantId, 'tracking_number' => $tracking,
                'client_id' => $clientId, 'fecha' => $this->date($row['Fecha']), 'external_order_number' => $row['OS Blue'],
                'external_courier_name' => 'Blue', 'destination_locality_name' => $row['Localidad Destino'], 'delivery_point' => $row['Punto entrega'],
                'client_name_source' => $row['Cliente'], 'exclude_provider_payment' => true, 'updated_at' => now(), 'created_at' => now(),
            ];
        }
        foreach (array_chunk(array_values($records), 300) as $chunk) {
            DB::table('envios_externos')->insert($chunk);
        }
    }

    private function weightTransformations(): void
    {
        DB::table('weight_transformations')->where('tenant_id', $this->tenantId)->delete();
        $records = [];
        foreach ($this->rows('initial_weight_transformations.tsv') as $row) {
            $source = trim($row['Peso']);
            $records[] = [
                'tenant_id' => $this->tenantId,
                'source_weight' => $source,
                'comparison_key' => $this->weightKey($source),
                'transformed_weight' => $this->integer($row['Peso Transformado']) ?? 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($records, 300) as $chunk) {
            DB::table('weight_transformations')->insert($chunk);
        }
    }

    private function rows(string $file): array
    {
        $lines = file(database_path('data/'.$file), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if ($lines === []) {
            return [];
        }
        $headers = str_getcsv(array_shift($lines), "\t");

        return array_values(array_filter(array_map(function (string $line) use ($headers): ?array {
            $values = str_getcsv($line, "\t");
            $values = array_pad($values, count($headers), '');

            return count($values) >= count($headers) ? array_combine($headers, array_slice($values, 0, count($headers))) : null;
        }, $lines)));
    }

    private function rutParts(string $rut): array
    {
        $parts = explode('-', strtoupper(trim($rut)));

        return [preg_replace('/\D/', '', $parts[0]) ?: '0', $parts[1] ?? '0'];
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric(str_replace(',', '.', (string) $value)) ? (int) round((float) str_replace(',', '.', (string) $value)) : null;
    }

    private function number(mixed $value): ?float
    {
        $value = str_replace(',', '.', trim((string) $value));

        return is_numeric($value) ? (float) $value : null;
    }

    private function weightKg(mixed $value): ?int
    {
        $number = $this->number(preg_replace('/[^0-9,.]/', '', (string) $value));

        return $number === null ? null : (int) round($number * 1000);
    }

    private function weightKey(string $value): string
    {
        if (preg_match('/-?\d+(?:[.,]\d+)?/', $value, $matches)) {
            return rtrim(rtrim(number_format((float) str_replace(',', '.', $matches[0]), 6, '.', ''), '0'), '.');
        }

        return mb_strtolower(trim($value));
    }

    private function date(mixed $value): ?string
    {
        try {
            $value = trim((string) $value);

            return $value === '' || strcasecmp($value, 'No Aplica') === 0 || strtolower($value) === 'nan' ? null : Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
