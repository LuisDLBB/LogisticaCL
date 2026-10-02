<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\MaestroPago;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\CachingStrategyFactory;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\CachingStrategyFactoryInterface;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\CachingStrategyInterface;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\InMemoryStrategy;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\MemoryLimit;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;
use ZipArchive;

class RealWeightImporter
{
    private const SIZE_WEIGHTS = ['XS' => 1, 'S' => 4, 'M' => 6, 'L' => 10, 'XL' => 30, 'XXL' => 36];

    private const HEADERS = [
        'fecha', 'seguimientopaquete', 'codigoseguimiento', 'pesoreal',
        'cliente', 'operario', 'observacion', 'guiacliente',
    ];

    /** @return array{created: int, updated: int, paid: int, closed: int, unmatched: int, invalid: int, duplicate: int, period: string, issues: array, issue_overflow: int} */
    public function import(string $path, int $tenantId, string $format = 'xlsx'): array
    {
        $result = ['created' => 0, 'updated' => 0, 'paid' => 0, 'closed' => 0, 'unmatched' => 0,
            'invalid' => 0, 'duplicate' => 0, 'period' => '', 'issues' => [], 'issue_overflow' => 0];
        $selected = [];
        $issues = [];
        $hasRows = false;

        try {
            foreach ($this->rows($path, $format) as [$line, $values]) {
                if ($line === 1) {
                    $headers = array_map($this->header(...), array_slice($values, 0, 8));
                    if ($headers !== self::HEADERS) {
                        throw ValidationException::withMessages(['file' => 'Las columnas deben ser: Fecha, Seguimiento_Paquete, Codigo_seguimiento, Peso_Real, Cliente, Operario, Observacion y GuiaCliente.']);
                    }

                    continue;
                }
                if ($this->emptyRow($values)) {
                    continue;
                }
                $hasRows = true;

                $values = array_pad($values, 8, null);
                $tracking = strtoupper($this->text($values[1]) ?? '');
                $code = strtoupper($this->text($values[2]) ?? '');
                $valid = true;
                if (preg_match('/^4N\d{12}(?:-\d{3})?$/D', $tracking) !== 1) {
                    $this->issue($issues, $result, 'Seguimiento_Paquete', $values[1], 'Revisar seguimiento; debe tener el formato 4NAAAAMMDD0000-000 o sin sufijo.', $line);
                    $valid = false;
                }
                if (preg_match('/^4N\d{12}$/D', $code) !== 1 || ($valid && $code !== substr($tracking, 0, 14))) {
                    $this->issue($issues, $result, 'Codigo_seguimiento', $values[2], 'Revisar código; debe coincidir con el seguimiento sin el sufijo.', $line);
                    $valid = false;
                }
                try {
                    $date = $this->date($values[0], $line);
                } catch (ValidationException) {
                    $this->issue($issues, $result, 'Fecha', $values[0], 'Revisar formato de fecha; usa DD-MM-AAAA o una fecha de Excel válida.', $line);
                    $valid = false;
                }
                try {
                    [, $size] = $this->weightAndSize($values[3], $line);
                    if ($size !== null && str_starts_with($size, 'Error-')) {
                        $this->issue($issues, $result, 'Peso_Real', $values[3], 'Texto no reconocido; se guardará sin peso y con Error- en Talla.', $line);
                    }
                } catch (ValidationException) {
                    $this->issue($issues, $result, 'Peso_Real', $values[3], 'Revisar peso; el valor supera el límite permitido.', $line);
                    $valid = false;
                }
                foreach ([4 => ['Cliente', 255], 5 => ['Operario', 160], 7 => ['GuiaCliente', 160]] as $index => [$field, $max]) {
                    if (mb_strlen($this->text($values[$index]) ?? '') > $max) {
                        $this->issue($issues, $result, $field, $values[$index], "Revisar {$field}; supera {$max} caracteres.", $line);
                        $valid = false;
                    }
                }
                if (! $valid) {
                    $result['invalid']++;

                    continue;
                }

                $result['period'] = max($result['period'], substr($date, 0, 7));
                if (isset($selected[$tracking])) {
                    $result['duplicate']++;
                    if ($date < $selected[$tracking]['date']) {
                        continue;
                    }
                }
                $selected[$tracking] = ['line' => $line, 'date' => $date];
            }
            if (! $hasRows) {
                throw ValidationException::withMessages(['file' => 'La planilla de Peso Real está vacía.']);
            }

            $result['issues'] = array_values($issues);
            if ($selected === []) {
                return $result;
            }

            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::connection()->getPdo()->exec('PRAGMA busy_timeout = 15000');
            }
            DB::transaction(function () use ($path, $format, $tenantId, $selected, &$result): void {
                foreach (['created', 'updated', 'paid', 'closed', 'unmatched'] as $counter) {
                    $result[$counter] = 0;
                }
                $closedPeriods = DB::table('PPR_Cierres_Pagos')->where('tenant_id', $tenantId)
                    ->pluck('periodo')->flip();
                $batch = [];

                foreach ($this->rows($path, $format) as [$line, $values]) {
                    if ($line === 1 || $this->emptyRow($values)) {
                        continue;
                    }
                    $values = array_pad($values, 8, null);
                    $tracking = strtoupper($this->text($values[1]) ?? '');
                    if (($selected[$tracking]['line'] ?? null) !== $line) {
                        continue;
                    }
                    [$weight, $size] = $this->weightAndSize($values[3], $line);
                    $batch[] = [
                        'tenant_id' => $tenantId,
                        'seguimiento_paquete' => $tracking,
                        'codigo_seguimiento' => strtoupper($this->text($values[2]) ?? ''),
                        'peso_real' => $weight,
                        'talla' => $size,
                        'fecha_proceso' => $selected[$tracking]['date'],
                        'cliente_origen' => $this->limited($values[4], 255, 'Cliente', $line),
                        'operario' => $this->limited($values[5], 160, 'Operario', $line),
                        'observacion' => $this->text($values[6]),
                        'guia_cliente' => $this->limited($values[7], 160, 'GuiaCliente', $line),
                    ];
                    if (count($batch) >= 500) {
                        $this->saveBatch($batch, $tenantId, $closedPeriods, $result);
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    $this->saveBatch($batch, $tenantId, $closedPeriods, $result);
                }
            }, 3);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'No se pudo completar la carga de Peso Real. No se guardaron cambios; revisa el archivo y el registro de errores.']);
        }

        try {
            Storage::disk('local')->put("real-weight-imports/last-{$tenantId}.json", json_encode([
                'issues' => $result['issues'],
                'issue_overflow' => $result['issue_overflow'],
                'imported_at' => now()->format('d-m-Y H:i'),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            report($exception);
        }

        return $result;
    }

    /** @return \Generator<array{int, array<mixed>}> */
    private function rows(string $path, string $format): \Generator
    {
        if ($format === 'csv') {
            $handle = fopen($path, 'rb');
            $firstLine = $handle === false ? '' : (fgets($handle) ?: '');
            if ($handle !== false) {
                fclose($handle);
            }
            $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
            $reader = new CsvReader(new CsvOptions(FIELD_DELIMITER: $delimiter));
        } else {
            $zip = new ZipArchive;
            $cacheInMemory = false;
            if ($zip->open($path) === true) {
                $sharedStrings = $zip->statName('xl/sharedStrings.xml');
                $cacheInMemory = $sharedStrings !== false && $sharedStrings['size'] <= 24 * 1024 * 1024;
                $zip->close();
            }
            // The default disk cache rereads thousands of files for large Peso Real sheets.
            // A bounded in-memory cache keeps this 112k-row import practical.
            $factory = new class($cacheInMemory) implements CachingStrategyFactoryInterface
            {
                public function __construct(private readonly bool $cacheInMemory) {}

                public function createBestCachingStrategy(?int $sharedStringsUniqueCount, string $tempFolder): CachingStrategyInterface
                {
                    if ($this->cacheInMemory && $sharedStringsUniqueCount !== null && $sharedStringsUniqueCount <= 250000) {
                        return new InMemoryStrategy($sharedStringsUniqueCount);
                    }

                    return (new CachingStrategyFactory(new MemoryLimit((string) ini_get('memory_limit'))))
                        ->createBestCachingStrategy($sharedStringsUniqueCount, $tempFolder);
                }
            };
            $reader = new XlsxReader(null, $factory);
        }

        $reader->open($path);
        try {
            $line = 0;
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield [++$line, $row->toArray()];
                }

                break;
            }
        } finally {
            $reader->close();
        }
    }

    /** @param array<mixed> $values */
    private function emptyRow(array $values): bool
    {
        return collect($values)->every(fn (mixed $value): bool => $value === null || (is_string($value) && trim($value) === ''));
    }

    /** @param array<string, array{field: string, value: string, reason: string, count: int, lines: array<int>}> $issues
     * @param  array<string, mixed>  $result
     */
    private function issue(array &$issues, array &$result, string $field, mixed $value, string $reason, int $line): void
    {
        $raw = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : trim((string) ($value ?? ''));
        $raw = mb_substr($raw === '' ? '(vacío)' : $raw, 0, 160);
        $key = $field."\0".$raw."\0".$reason;
        if (! isset($issues[$key])) {
            if (count($issues) >= 200) {
                $result['issue_overflow']++;

                return;
            }
            $issues[$key] = ['field' => $field, 'value' => $raw, 'reason' => $reason, 'count' => 0, 'lines' => []];
        }
        $issues[$key]['count']++;
        if (count($issues[$key]['lines']) < 5) {
            $issues[$key]['lines'][] = $line;
        }
    }

    /** @param list<array<string, mixed>> $batch
     * @param  array<string, mixed>  $result
     */
    private function saveBatch(array $batch, int $tenantId, Collection $closedPeriods, array &$result): void
    {
        $trackings = array_column($batch, 'seguimiento_paquete');
        $existing = DB::table('PPR_peso_real')->where('tenant_id', $tenantId)
            ->whereIn('seguimiento_paquete', $trackings)->get()->keyBy('seguimiento_paquete');
        $movements = DB::table('PPR_movimientos_courier')->where('tenant_id', $tenantId)
            ->whereIn('tracking_number', $trackings)->get(['tracking_number', 'merchant_name', 'service_name', 'nombre_proceso'])
            ->keyBy('tracking_number');
        $paid = DB::table('PPR_Maestro_Pagos')->where('tenant_id', $tenantId)
            ->whereIn('seguimiento_paquete', $trackings)
            ->pluck('seguimiento_paquete')->flip();
        $rows = [];
        $now = now();

        foreach ($batch as $row) {
            $tracking = $row['seguimiento_paquete'];
            if ($paid->has(MaestroPago::trackingKey($tracking))) {
                $result['paid']++;

                continue;
            }
            $old = $existing->get($tracking);
            $movement = $movements->get($tracking);
            $periods = [];
            if ($movement !== null && preg_match('/^\d{6}/', (string) $movement->nombre_proceso) === 1) {
                $periods[] = substr($movement->nombre_proceso, 0, 4).'-'.substr($movement->nombre_proceso, 4, 2);
            } else {
                $periods[] = substr($row['fecha_proceso'], 0, 7);
                if ($old !== null) {
                    $periods[] = substr((string) $old->fecha_proceso, 0, 7);
                }
            }
            if (collect($periods)->contains(fn (string $period): bool => $closedPeriods->has(str_replace('-', '', $period)))) {
                $result['closed']++;

                continue;
            }

            $row['comerciante'] = $this->text($movement?->merchant_name)
                ?? ($old?->comerciante ?: $row['cliente_origen'] ?: '');
            $row['servicio'] = $this->text($movement?->service_name) ?? ($old?->servicio ?: '');
            $row['created_at'] = $old?->created_at ?? $now;
            $row['updated_at'] = $now;
            $rows[] = $row;
            $result[$old === null ? 'created' : 'updated']++;
            if ($movement === null) {
                $result['unmatched']++;
            }
        }

        if ($rows !== []) {
            DB::table('PPR_peso_real')->upsert($rows, ['tenant_id', 'seguimiento_paquete'], [
                'codigo_seguimiento', 'peso_real', 'talla', 'fecha_proceso', 'cliente_origen', 'operario',
                'observacion', 'guia_cliente', 'comerciante', 'servicio', 'updated_at',
            ]);
        }
    }

    private function header(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii(trim((string) $value, " \t\n\r\0\x0B\xEF\xBB\xBF")))) ?? '';
    }

    private function text(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function limited(mixed $value, int $max, string $field, int $line): ?string
    {
        $text = $this->text($value);
        if ($text !== null && mb_strlen($text) > $max) {
            throw ValidationException::withMessages(['file' => "{$field} supera {$max} caracteres en la fila {$line}."]);
        }

        return $text;
    }

    /** @return array{?int, ?string} */
    private function weightAndSize(mixed $value, int $line): array
    {
        $raw = $this->text($value);
        if ($raw === null) {
            return [null, null];
        }

        $size = mb_strtoupper($raw);
        if (isset(self::SIZE_WEIGHTS[$size])) {
            return [self::SIZE_WEIGHTS[$size], $size];
        }

        if (preg_match('/^\d+(?:[,.]\d+)?$/D', $raw) === 1) {
            $integerPart = preg_split('/[,.]/', $raw, 2)[0];
            if (strlen(ltrim($integerPart, '0')) > 10 || (float) $integerPart > 4294967295) {
                throw ValidationException::withMessages(['file' => "Peso_Real supera el máximo permitido en la fila {$line}."]);
            }

            return [(int) $integerPart, null];
        }

        $error = 'Error-'.$raw;
        if (mb_strlen($error) > 255) {
            throw ValidationException::withMessages(['file' => "El texto de Peso_Real supera 249 caracteres en la fila {$line}."]);
        }

        return [null, $error];
    }

    private function date(mixed $value, int $line): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (Throwable) {
                throw ValidationException::withMessages(['file' => "Fecha inválida en la fila {$line}; usa DD-MM-AAAA."]);
            }
        }
        $text = trim((string) $value);
        foreach (['d-m-Y', 'd/m/Y', 'Y-m-d', 'Y/m/d', 'j-n-Y', 'j/n/Y'] as $format) {
            try {
                $date = DateTimeImmutable::createFromFormat('!'.$format, $text);
            } catch (Throwable) {
                $date = false;
            }
            if ($date !== false && $date->format($format) === $text) {
                return $date->format('Y-m-d');
            }
        }

        throw ValidationException::withMessages(['file' => "Fecha inválida en la fila {$line}; usa DD-MM-AAAA."]);
    }
}
