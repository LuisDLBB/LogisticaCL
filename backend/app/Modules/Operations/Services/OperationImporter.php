<?php

namespace App\Modules\Operations\Services;

use App\Modules\Operations\Jobs\ImportOperationLoad;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\CachingStrategyFactory;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\CachingStrategyFactoryInterface;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\CachingStrategyInterface;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\InMemoryStrategy;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\MemoryLimit;
use OpenSpout\Reader\XLSX\Reader;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;
use ZipArchive;

class OperationImporter
{
    public function enqueue(string $path, string $storedPath, string $filename, int $tenant, int $user, string $type, string $sheet, array $mapping): int
    {
        return DB::transaction(function () use ($path, $storedPath, $filename, $tenant, $user, $type, $sheet, $mapping): int {
            DB::table('MBA_tenants')->where('id', $tenant)->lockForUpdate()->firstOrFail();
            $hash = hash_file('sha256', $path);
            $existing = DB::table('Ope_Cargas')->where(['tenant_id' => $tenant, 'source_type' => $type, 'sha256' => $hash])->first();
            if ($existing && $existing->status !== 'failed' && ($existing->sheet !== $sheet || json_decode($existing->mapping, true) !== $mapping)) {
                throw ValidationException::withMessages(['file' => 'Este archivo ya fue cargado con otra configuración. Utiliza la carga existente o corrige y vuelve a guardar el Excel.']);
            }
            if ($existing && $existing->status !== 'failed') {
                return $existing->id;
            }
            if ($existing) {
                $id = $existing->id;
                DB::table('Ope_Cargas')->where('id', $id)->update(['status' => 'queued', 'sheet' => $sheet, 'mapping' => OperationAccess::json($mapping), 'error' => null, 'updated_at' => now()]);
            } else {
                $id = DB::table('Ope_Cargas')->insertGetId(['tenant_id' => $tenant, 'user_id' => $user, 'source_type' => $type, 'filename' => $filename, 'path' => $storedPath, 'sha256' => $hash, 'sheet' => $sheet, 'mapping' => OperationAccess::json($mapping), 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
            }
            OperationAccess::audit($tenant, $user, 'Recibir archivo', 'carga', $id, ['sha256' => $hash, 'status' => 'queued', 'type' => $type]);
            ImportOperationLoad::dispatch($id)->onConnection(config('operations.import_connection'))->onQueue('operations');

            return $id;
        });
    }

    public function import(string $path, string $storedPath, string $filename, int $tenant, int $user, string $type, string $sheet, array $mapping, ?int $loadId = null): int
    {
        $hash = hash_file('sha256', $path);
        $existing = DB::table('Ope_Cargas')->where(['tenant_id' => $tenant, 'source_type' => $type, 'sha256' => $hash])->first();
        if ($existing && $loadId === null) {
            if ($existing->sheet !== $sheet || json_decode($existing->mapping, true) !== $mapping) {
                throw ValidationException::withMessages(['file' => 'Este archivo ya fue cargado con otra configuración. Utiliza la carga existente o corrige y vuelve a guardar el Excel.']);
            }

            return $existing->id;
        }
        if ($loadId !== null && (! $existing || $existing->id !== $loadId)) {
            throw ValidationException::withMessages(['file' => 'La huella del archivo cambió desde su recepción. Vuelve a cargar el Excel.']);
        }
        $zip = new ZipArchive;
        $boundedStrings = false;
        if ($zip->open($path) === true) {
            $strings = $zip->statName('xl/sharedStrings.xml');
            $boundedStrings = $strings !== false && $strings['size'] <= 24 * 1024 * 1024;
            $zip->close();
        }
        $cache = new class($boundedStrings) implements CachingStrategyFactoryInterface
        {
            public function __construct(private bool $boundedStrings) {}

            public function createBestCachingStrategy(?int $sharedStringsUniqueCount, string $tempFolder): CachingStrategyInterface
            {
                if ($this->boundedStrings && $sharedStringsUniqueCount !== null && $sharedStringsUniqueCount <= 250000) {
                    return new InMemoryStrategy($sharedStringsUniqueCount);
                }

                return (new CachingStrategyFactory(new MemoryLimit((string) ini_get('memory_limit'))))->createBestCachingStrategy($sharedStringsUniqueCount, $tempFolder);
            }
        };
        $reader = new Reader(null, $cache);
        $activeLoadId = $loadId;
        try {
            $reader->open($path);

            $process = function () use ($reader, $storedPath, $filename, $tenant, $user, $type, $sheet, $mapping, $hash, $loadId, &$activeLoadId): int {
                $load = $loadId ?? DB::table('Ope_Cargas')->insertGetId(['tenant_id' => $tenant, 'user_id' => $user, 'source_type' => $type, 'filename' => $filename, 'path' => $storedPath, 'sha256' => $hash, 'sheet' => $sheet, 'mapping' => OperationAccess::json($mapping), 'created_at' => now(), 'updated_at' => now()]);
                $activeLoadId = $load;
                if ($loadId !== null) {
                    DB::table('Ope_FilasFuente')->where('load_id', $load)->delete();
                }
                DB::table('Ope_Cargas')->where('id', $load)->update(['status' => 'processing', 'row_count' => 0, 'invalid_count' => 0]);
                $count = $invalid = 0;
                $found = false;
                $buffer = [];
                foreach ($reader->getSheetIterator() as $worksheet) {
                    if ($worksheet->getName() !== $sheet) {
                        continue;
                    }
                    $found = true;
                    $headers = [];
                    foreach ($worksheet->getRowIterator() as $line => $row) {
                        $values = $row->toArray();
                        if ($line === 1) {
                            $headers = array_map(fn ($value): string => OperationAccess::key((string) $value), $values);
                            $this->validateHeaders($headers, $type, $mapping);

                            continue;
                        }
                        if (count(array_filter($values, fn ($value): bool => $value !== null && $value !== '')) === 0) {
                            continue;
                        }
                        $errors = [];
                        $data = $type === 'master' ? $this->master($values, $headers, $errors) : $this->reception($values, $mapping, $errors);
                        $count++;
                        $invalid += $errors === [] ? 0 : 1;
                        $raw = array_map(fn ($value) => $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value, $values);
                        $buffer[] = ['load_id' => $load, 'line' => $line, 'tracking' => $data['tracking'] ?: null, 'raw' => OperationAccess::json($raw), 'data' => OperationAccess::json($data), 'errors' => OperationAccess::json($errors)];
                        if (count($buffer) === 200) {
                            DB::transaction(function () use ($buffer, $load, $count, $invalid): void {
                                DB::table('Ope_FilasFuente')->insert($buffer);
                                DB::table('Ope_Cargas')->where('id', $load)->update(['row_count' => $count, 'invalid_count' => $invalid, 'updated_at' => now()]);
                            });
                            $buffer = [];
                        }
                    }
                    break;
                }
                if (! $found || $count === 0) {
                    throw ValidationException::withMessages(['file' => 'No se encontró la hoja indicada con registros. Revisa el nombre y la fila de encabezados.']);
                }
                if ($buffer !== []) {
                    DB::table('Ope_FilasFuente')->insert($buffer);
                }
                DB::transaction(function () use ($load, $count, $invalid, $tenant, $user, $hash, $type, $mapping): void {
                    DB::table('Ope_Cargas')->where('id', $load)->update(['row_count' => $count, 'invalid_count' => $invalid, 'status' => 'completed', 'error' => null, 'updated_at' => now()]);
                    OperationAccess::audit($tenant, $user, 'Carga Excel', 'carga', $load, ['sha256' => $hash, 'type' => $type, 'rows' => $count, 'invalid' => $invalid, 'mapping' => $mapping]);
                });

                return $load;
            };

            return $process();
        } catch (ValidationException $error) {
            if ($activeLoadId !== null) {
                DB::table('Ope_Cargas')->where('id', $activeLoadId)->update(['status' => 'failed', 'error' => implode(' ', array_merge(...array_values($error->errors()))), 'updated_at' => now()]);
            }
            throw $error;
        } catch (Throwable $error) {
            if ($activeLoadId !== null) {
                DB::table('Ope_Cargas')->where('id', $activeLoadId)->update(['status' => 'failed', 'error' => 'La carga se interrumpió. Vuelve a cargar el archivo para reintentar.', 'updated_at' => now()]);
            }
            report($error);
            throw ValidationException::withMessages(['file' => 'No fue posible leer el Excel. Guarda una copia válida en formato .xlsx e intenta nuevamente.']);
        } finally {
            $reader->close();
        }
    }

    private function validateHeaders(array $headers, string $type, array $mapping): void
    {
        if ($type === 'master') {
            foreach (['seguimiento paquete', 'comuna de destino', 'comerciante', 'servicio'] as $header) {
                if (! in_array($header, $headers, true)) {
                    throw ValidationException::withMessages(['file' => 'El Maestro debe contener Seguimiento paquete, Comuna de destino, Comerciante y Servicio.']);
                }
            }
        } else {
            foreach (['date', 'tracking', 'weight', 'operator'] as $field) {
                if (empty($mapping[$field]) || ! isset($headers[$this->column($mapping[$field])]) || $headers[$this->column($mapping[$field])] === '') {
                    throw ValidationException::withMessages(['file' => 'Revisa las columnas configuradas para fecha, paquete, peso y operario.']);
                }
            }
        }
    }

    private function master(array $values, array $headers, array &$errors): array
    {
        $get = function (string $name) use ($values, $headers): string {
            $index = array_search($name, $headers, true);

            return $index === false ? '' : trim((string) ($values[$index] ?? ''));
        };
        $tracking = $this->tracking($get('seguimiento paquete'), $errors);
        $commune = $get('comuna de destino');
        if ($commune === '' || str_starts_with($commune, '#')) {
            $errors[] = 'Comuna de destino ausente o inválida.';
        }
        foreach (['comuna de destino' => 150, 'comerciante' => 255, 'servicio' => 160] as $field => $length) {
            if (mb_strlen($get($field)) > $length || str_starts_with($get($field), '#')) {
                $errors[] = 'Dato inválido o demasiado largo en '.$field.'.';
            }
        }

        return ['tracking' => $tracking, 'commune' => $commune, 'merchant' => $get('comerciante'), 'service' => $get('servicio'), 'recipient' => $get('nombre del destinatario'), 'address' => $get('direccion'), 'geolize_guide' => $get('guia de despacho')];
    }

    private function reception(array $values, array $mapping, array &$errors): array
    {
        $get = fn (string $name) => empty($mapping[$name]) ? null : ($values[$this->column($mapping[$name])] ?? null);
        $tracking = $this->tracking(trim((string) $get('tracking')), $errors);
        $weight = $this->weight($get('weight'));
        if ($weight === null) {
            $errors[] = 'Peso volumétrico ausente, no positivo o inválido.';
        }
        if (($mapping['profile'] ?? '') === 'legacy') {
            foreach ([2, 13] as $column) {
                $other = $values[$column] ?? null;
                if ($other !== null && $other !== '' && ($this->weight($other) === null || $this->weight($other) !== $weight)) {
                    $errors[] = 'Los pesos C, F y N no coinciden. Corrige la lectura en Recepción.';
                    break;
                }
            }
        }
        $date = $this->date($get('date'));
        if ($date === null) {
            $errors[] = 'Fecha de escaneo inválida.';
        }
        $operator = trim((string) $get('operator'));
        if ($operator === '' || mb_strlen($operator) > 160 || str_starts_with($operator, '#')) {
            $errors[] = 'Operario ausente o inválido.';
        }
        $guide = trim((string) $get('customer_guide'));
        $reference = trim((string) $get('reference'));
        if (mb_strlen($guide) > 160 || mb_strlen($reference) > 160 || str_starts_with($guide, '#')) {
            $errors[] = 'Guía cliente o referencia inválida.';
        }

        return ['tracking' => $tracking, 'weight' => $weight, 'date' => $date, 'operator' => $operator, 'customer_guide' => $guide ?: null, 'reference' => $reference ?: null];
    }

    private function tracking(string $value, array &$errors): string
    {
        if ($value === '' || mb_strlen($value) > 100 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $value) !== 1) {
            $errors[] = 'Código completo de paquete ausente o inválido.';

            return '';
        }

        return mb_strtoupper($value);
    }

    private function weight(mixed $value): ?string
    {
        $value = str_replace(',', '.', trim((string) $value));
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0 || (float) $value > 999999999 || round((float) $value, 3) !== (float) $value) {
            return null;
        }

        return number_format((float) $value, 3, '.', '');
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 100000) {
            return Date::excelToDateTimeObject((float) $value)->format('Y-m-d H:i:s');
        }
        foreach (['!Y-m-d H:i:s', '!Y-m-d', '!d/m/Y H:i:s', '!d/m/Y', '!d-m-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, trim((string) $value));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    private function column(string $column): int
    {
        $index = 0;
        foreach (str_split(mb_strtoupper($column)) as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }

        return $index - 1;
    }
}
