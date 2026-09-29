<?php

namespace Database\Seeders;

use App\Models\Provider;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use RuntimeException;

class ProviderOcFilenameSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $lines = file(database_path('data/purchase_order_filenames.tsv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || $lines === []) {
            throw new RuntimeException('No se encontró el listado de nombres de archivos de OC.');
        }

        $headers = str_getcsv(array_shift($lines), "\t");
        foreach ($lines as $line) {
            $values = str_getcsv($line, "\t");
            $source = array_combine($headers, array_pad($values, count($headers), ''));
            if ($source === false) {
                throw new RuntimeException('Una fila del listado de nombres de OC no tiene sus columnas.');
            }

            $rut = preg_replace('/[^0-9K]/', '', strtoupper((string) $source['RUT']));
            $number = substr($rut, 0, -1);
            $digit = substr($rut, -1);
            $company = strtoupper(trim((string) $source['EMPRESA']));
            $companyCode = str_contains($company, 'PMCB') || str_contains($company, 'PMBC') ? 'PMCB' : '4N';
            if (! str_starts_with($company, '4N') && $companyCode !== 'PMCB') {
                throw new RuntimeException("Empresa mandante desconocida para el RUT {$rut}.");
            }

            $type = strtoupper(trim((string) $source['TIPO']));
            $documentType = match ($type) {
                'FACTURA' => 'Factura',
                'FACTURA EXENTA' => 'Factura Exenta',
                'BOLETA' => 'Boleta de Honorarios',
                default => throw new RuntimeException("Tipo de documento desconocido para el RUT {$rut}."),
            };
            $provider = Provider::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'tax_id_number' => $number],
                [
                    'tax_id' => $number.'-'.$digit,
                    'tax_id_check_digit' => $digit,
                    'legal_name' => trim((string) $source['PROVEEDOR']),
                    'operator_type' => 'Courier',
                    'tax_document_type' => $documentType,
                    'is_active' => false,
                ]
            );

            $stem = trim((string) $source['NOMBRE_ARCHIVO']);
            if ($rut === '772351070' && $stem === 'Quincena') {
                continue;
            }
            if (! preg_match('/^[A-Za-z0-9_-]+$/', $stem)) {
                throw new RuntimeException("Nombre de archivo inválido para el RUT {$rut}.");
            }

            $scope = match ($stem) {
                'DSG_Troncal_Norte' => 'Troncal Norte',
                'DSG_RUTA_V' => 'Troncal V',
                'JoseCollio_2' => 'Servicios hasta 14/08/2026',
                default => 'General',
            };
            $provider->ocFilenames()->firstOrCreate(
                ['company_code' => $companyCode, 'service_scope' => $scope],
                ['file_stem' => $stem]
            );
            if ($stem === 'JoseCollio_2') {
                $provider->ocFilenames()->firstOrCreate(
                    ['company_code' => $companyCode, 'service_scope' => 'Servicios hasta 17/09/2026'],
                    ['file_stem' => $stem]
                );
            }
        }
    }
}
