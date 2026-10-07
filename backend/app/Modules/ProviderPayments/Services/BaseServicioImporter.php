<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\BaseServicio;
use App\Models\Client;
use App\Models\MaestroPago;
use App\Models\Provider;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class BaseServicioImporter
{
    public function __construct(private readonly CalamaProviderTransition $transition) {}

    private const HEADERS = [
        'zona', 'tipodepago', 'seguimientopaquete', 'fechacarga', 'direccion', 'numerodestino',
        'deptodestino', 'comunadestino', 'razonsocialcliente', 'rutcliente', 'servicio', 'peso',
        'estadodelenvio', 'valorfinal', 'operador', 'usuario', 'periodo', 'usuario2',
        'transportista', 'razonsocial', 'rut', 'empresa',
    ];

    /** @return array{imported: int, existing: int, pending: int, periods: list<string>} */
    public function import(string $path, string $filename, int $tenantId): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $sourceRows = $extension === 'csv' ? $this->csvRows($path) : $this->excelRows($path);
        $headers = array_shift($sourceRows);
        $normalizedHeaders = $headers === null ? [] : array_slice(array_map($this->normalizeHeader(...), $headers), 0, 22);
        if (($normalizedHeaders[20] ?? null) === 'rutproveedor') {
            $normalizedHeaders[20] = 'rut';
        }
        if ($normalizedHeaders !== self::HEADERS) {
            throw ValidationException::withMessages(['file' => 'Las columnas no coinciden con Base_Servicios. Usa el formato de la planilla adjunta.']);
        }

        $providers = Provider::query()->where('tenant_id', $tenantId)->get();
        $clients = Client::query()->where('tenant_id', $tenantId)->get();
        $hash = hash_file('sha256', $path);
        $prepared = [];
        $pending = 0;
        $periods = [];
        foreach ($sourceRows as $line => $values) {
            if (collect($values)->every(fn (mixed $value): bool => trim((string) ($value ?? '')) === '')) {
                continue;
            }
            $rowNumber = $line + 2;
            $values = array_pad($values, 22, null);
            foreach ([0 => 'Zona', 1 => 'Tipo de Pago', 3 => 'Fecha Carga', 4 => 'Dirección', 7 => 'Comuna Destino',
                8 => 'Razón Social Cliente', 10 => 'Servicio', 12 => 'Estado del envío', 14 => 'Operador',
                15 => 'Usuario', 16 => 'Periodo', 19 => 'Razón Social', 21 => 'Empresa'] as $index => $label) {
                if ($this->text($values[$index]) === null) {
                    throw ValidationException::withMessages(['file' => "Falta {$label} en la fila {$rowNumber}."]);
                }
            }
            if ($this->normalize((string) $values[1]) !== 'servicios') {
                throw ValidationException::withMessages(['file' => "La fila {$rowNumber} no corresponde al proceso Servicios."]);
            }
            $period = $this->period((string) $values[16], $rowNumber);
            MonthlyPaymentClosingService::assertOpen($tenantId, $period);
            $tracking = $this->text($values[2]);
            if ($tracking !== null && MaestroPago::query()->whereKey(MaestroPago::trackingKey($tracking))->exists()) {
                throw ValidationException::withMessages(['file' => "El seguimiento {$tracking} de la fila {$rowNumber} ya está cerrado en Maestro_Pagos."]);
            }
            $amount = $this->number($values[13], $rowNumber, 'Valor final');
            if ($amount < 0 || floor($amount) !== $amount) {
                throw ValidationException::withMessages(['file' => "Valor final inválido en la fila {$rowNumber}."]);
            }
            $weight = $this->number($values[11], $rowNumber, 'Peso');
            if ($weight < 0) {
                throw ValidationException::withMessages(['file' => "Peso inválido en la fila {$rowNumber}."]);
            }
            $client = $this->matchClient($this->text($values[9]), (string) $values[8], $clients);
            $provider = $this->matchProvider($this->text($values[20]), (string) $values[19], (string) $values[18], $providers);
            $provider = $this->transition->providerFor($providers, $period, 'Servicios', trim((string) $values[7]), null, $provider);
            $transportista = $this->text($values[18])
                ?? $this->text($provider?->operational_name)
                ?? $this->text($provider?->legal_name)
                ?? trim((string) $values[19]);
            $pending += $client === null || $provider === null ? 1 : 0;
            $periods[$period] = true;
            $prepared[] = [
                'tenant_id' => $tenantId,
                'periodo' => $period,
                'periodo_origen' => trim((string) $values[16]),
                'nombre_proceso' => $period.'-Servicios',
                'zona' => ProviderZone::resolve($provider?->tax_id ?: $this->text($values[20]), $provider?->id, trim((string) $values[0])),
                'tipo_pago' => trim((string) $values[1]),
                'seguimiento_paquete' => $tracking,
                'fecha_carga' => $this->date($values[3], $rowNumber)->format('Y-m-d'),
                'direccion' => trim((string) $values[4]),
                'numero_destino' => $this->text($values[5]),
                'depto_destino' => $this->text($values[6]),
                'comuna_destino' => trim((string) $values[7]),
                'cliente_origen' => trim((string) $values[8]),
                'rut_cliente_origen' => $this->text($values[9]),
                'servicio' => trim((string) $values[10]),
                'peso' => $weight,
                'estado_envio' => trim((string) $values[12]),
                'valor_final' => (int) $amount,
                'operador' => trim((string) $values[14]),
                'usuario' => trim((string) $values[15]),
                'usuario2' => $this->text($values[17]),
                'transportista' => $transportista,
                'razon_social_proveedor_origen' => trim((string) $values[19]),
                'rut_proveedor_origen' => $this->text($values[20]),
                'empresa' => trim((string) $values[21]),
                ...self::clientFields($client),
                ...self::providerFields($provider),
                'archivo_origen' => $filename,
                'hash_archivo' => $hash,
                'fila_origen' => $rowNumber,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        if ($prepared === []) {
            throw ValidationException::withMessages(['file' => 'La planilla no contiene registros de Servicios.']);
        }

        $imported = DB::transaction(function () use ($prepared, $tenantId, $periods): int {
            if (BaseServicio::query()->where('tenant_id', $tenantId)->whereIn('periodo', array_keys($periods))
                ->whereNotNull('closed_at')->exists()) {
                throw ValidationException::withMessages(['file' => 'Uno de los períodos de la planilla está cerrado. Reábrelo con la clave maestra antes de cargar servicios.']);
            }
            $count = 0;
            foreach (array_chunk($prepared, 250) as $chunk) {
                $count += DB::table('PPR_Base_Servicios')->insertOrIgnore($chunk);
            }

            return $count;
        });

        return ['imported' => $imported, 'existing' => count($prepared) - $imported,
            'pending' => $pending, 'periods' => array_keys($periods)];
    }

    /** @return array<string, mixed> */
    public static function clientFields(?Client $client): array
    {
        return ['client_id' => $client?->id, 'rut_cliente' => $client?->tax_id,
            'nombre_cliente' => $client?->commercial_name, 'razon_social_cliente' => $client?->legal_name];
    }

    /** @return array<string, mixed> */
    public static function providerFields(?Provider $provider): array
    {
        return ['provider_id' => $provider?->id, 'rut_proveedor' => $provider?->tax_id,
            'nombre_operacional' => $provider?->operational_name, 'razon_social_proveedor' => $provider?->legal_name,
            'tipo_documento' => $provider?->tax_document_type];
    }

    /** @return list<array<int, mixed>> */
    private function excelRows(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $rows = [];
            for ($line = 1; $line <= $sheet->getHighestDataRow(); $line++) {
                $row = [];
                for ($column = 1; $column <= 22; $column++) {
                    $row[] = $sheet->getCell([$column, $line])->getValue();
                }
                $rows[] = $row;
            }

            return $rows;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /** @return list<array<int, mixed>> */
    private function csvRows(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'No se pudo abrir el archivo CSV.']);
        }
        try {
            $firstLine = fgets($handle) ?: '';
            rewind($handle);
            $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
            $rows = [];
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $rows[] = array_map(function (mixed $value): mixed {
                    if (! is_string($value)) {
                        return $value;
                    }
                    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;

                    return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
                }, $row);
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function normalizeHeader(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii((string) $value))) ?? '';
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii(trim($value)))) ?? '');
    }

    private function taxId(?string $value): string
    {
        return strtoupper(preg_replace('/[^0-9kK]/', '', $value ?? '') ?? '');
    }

    private function period(string $value, int $rowNumber): string
    {
        $normalized = $this->normalize($value);
        if (preg_match('/^(\d{4})\s*(\d{2})(?:\s*servicios)?$/', $normalized, $matches)) {
            $period = $matches[1].$matches[2];
        } elseif (preg_match('/^(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)\s+(\d{4})\s+servicios$/', $normalized, $matches)) {
            $months = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
                'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];
            $period = $matches[2].sprintf('%02d', $months[$matches[1]]);
        } else {
            throw ValidationException::withMessages(['file' => "Período inválido en la fila {$rowNumber}."]);
        }
        if (! checkdate((int) substr($period, 4, 2), 1, (int) substr($period, 0, 4))) {
            throw ValidationException::withMessages(['file' => "Período inválido en la fila {$rowNumber}."]);
        }

        return $period;
    }

    private function number(mixed $value, int $rowNumber, string $field): float
    {
        $number = is_string($value) ? str_replace(',', '.', trim($value)) : $value;
        if (! is_numeric($number)) {
            throw ValidationException::withMessages(['file' => "{$field} inválido en la fila {$rowNumber}."]);
        }

        return (float) $number;
    }

    private function date(mixed $value, int $rowNumber): CarbonImmutable
    {
        try {
            if ($value instanceof DateTimeInterface) {
                return CarbonImmutable::instance($value);
            }
            if (is_numeric($value)) {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value));
            }
            if (is_string($value) && trim($value) !== '') {
                return CarbonImmutable::parse($value);
            }
        } catch (\Throwable) {
            // The row-specific message is returned below.
        }

        throw ValidationException::withMessages(['file' => "Fecha Carga inválida en la fila {$rowNumber}."]);
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function matchClient(?string $taxId, string $name, Collection $clients): ?Client
    {
        if ($this->taxId($taxId) !== '') {
            $matches = $clients->filter(fn (Client $client): bool => $this->taxId($client->tax_id) === $this->taxId($taxId));

            return $matches->count() === 1 ? $matches->first() : null;
        }
        $normalized = $this->normalize($name);
        $matches = $clients->filter(fn (Client $client): bool => collect([$client->commercial_name, $client->legal_name, $client->source_merchant_name])
            ->contains(fn (?string $candidate): bool => $this->normalize((string) $candidate) === $normalized));
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->isNotEmpty()) {
            return null;
        }
        $matches = $clients->filter(fn (Client $client): bool => str_starts_with($this->normalize((string) $client->commercial_name), $normalized.' '));

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function matchProvider(?string $taxId, string $legalName, string $transportista, Collection $providers): ?Provider
    {
        if ($this->taxId($taxId) !== '') {
            $matches = $providers->filter(fn (Provider $provider): bool => $this->taxId($provider->tax_id) === $this->taxId($taxId));

            return $matches->count() === 1 ? $matches->first() : null;
        }
        $names = array_filter([$this->normalize($legalName), $this->normalize($transportista)]);
        $matches = $providers->filter(fn (Provider $provider): bool => in_array($this->normalize($provider->legal_name), $names, true)
            || in_array($this->normalize((string) $provider->operational_name), $names, true));

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
