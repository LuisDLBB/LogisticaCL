<?php

namespace App\Modules\ProviderPayments\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class ExternalShipmentImporter
{
    private const HEADERS = ['fecha', 'id', 'osblue', 'localidaddestino', 'puntoentrega', 'cliente', 'observacion'];

    /** @return array{created: int, updated: int, unchanged: int, paid: int, closed: int, payments_excluded: int, paid_rows: list<array<string, mixed>>, issues: list<array<string, mixed>>} */
    public function import(string $path, int $tenantId): array
    {
        $reader = new Reader;
        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'paid' => 0, 'closed' => 0, 'payments_excluded' => 0, 'paid_rows' => [], 'issues' => []];
        $headersRead = false;
        $batch = [];
        $line = 0;
        $recordsSeen = 0;

        try {
            $duplicateIds = $this->duplicateIds($path);
            $reader->open($path);
            DB::transaction(function () use ($reader, $tenantId, $duplicateIds, &$result, &$headersRead, &$batch, &$line, &$recordsSeen): void {
                $closedPeriods = DB::table('PPR_Cierres_Pagos')->where('tenant_id', $tenantId)->pluck('periodo')->flip();
                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $row) {
                        $line++;
                        $values = $row->toArray();
                        if (! $headersRead) {
                            if (array_map($this->header(...), array_slice($values, 0, 7)) !== self::HEADERS) {
                                throw ValidationException::withMessages(['file' => 'Las columnas deben ser: Fecha, ID, OS Blue, Localidad Destino, Punto entrega, Cliente y Observacion.']);
                            }
                            $headersRead = true;

                            continue;
                        }
                        if ($this->isEmptyRow($values)) {
                            continue;
                        }
                        $recordsSeen++;
                        $values = array_pad($values, 7, null);
                        $tracking = strtoupper($this->sourceText($values[1]));
                        if ($tracking === '') {
                            $result['issues'][] = $this->issue($values, $line, 'Falta ID / Seguimiento');

                            continue;
                        }
                        if (mb_strlen($tracking) > 100) {
                            $result['issues'][] = $this->issue($values, $line, 'ID demasiado largo');

                            continue;
                        }
                        if (isset($duplicateIds[$tracking])) {
                            $result['issues'][] = $this->issue($values, $line, 'ID repetido en filas '.implode(', ', $duplicateIds[$tracking]));

                            continue;
                        }
                        try {
                            $batch[] = [
                                '_source_row' => $line,
                                'tenant_id' => $tenantId,
                                'tracking_number' => $tracking,
                                'fecha' => $this->date($values[0], $line),
                                'external_order_number' => $this->limited($values[2], 100, 'OS Blue', $line),
                                'external_courier_name' => 'Blue',
                                'destination_locality_name' => $this->limited($values[3], 150, 'Localidad Destino', $line),
                                'delivery_point' => $this->limited($values[4], 100, 'Punto entrega', $line),
                                'client_name_source' => $this->limited($values[5], 255, 'Cliente', $line),
                                'observacion' => $this->text($values[6]),
                                'exclude_provider_payment' => true,
                            ];
                        } catch (ValidationException $exception) {
                            $result['issues'][] = $this->issue($values, $line, $exception->errors()['file'][0] ?? 'Dato inválido');

                            continue;
                        }
                        if (count($batch) >= 500) {
                            $this->saveBatch($batch, $tenantId, $closedPeriods, $result);
                            $batch = [];
                        }
                    }
                    break;
                }
                if (! $headersRead || $recordsSeen === 0) {
                    throw ValidationException::withMessages(['file' => 'La planilla de Envíos Externos está vacía.']);
                }
                if ($batch !== []) {
                    $this->saveBatch($batch, $tenantId, $closedPeriods, $result);
                }
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['file' => 'No se pudo procesar el Excel de Envíos Externos. No se guardó ningún registro de este intento; revisa el archivo y vuelve a intentar.']);
        } finally {
            $reader->close();
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $batch
     * @param  array{created: int, updated: int, unchanged: int, paid: int, closed: int, payments_excluded: int, paid_rows: list<array<string, mixed>>, issues: list<array<string, mixed>>}  $result
     */
    private function saveBatch(array $batch, int $tenantId, Collection $closedPeriods, array &$result): void
    {
        $trackings = array_column($batch, 'tracking_number');
        $existing = DB::table('PPR_envios_externos')->where('tenant_id', $tenantId)
            ->whereIn('tracking_number', $trackings)->get()->keyBy('tracking_number');
        $paid = DB::table('PPR_Maestro_Pagos')->where('tenant_id', $tenantId)
            ->whereIn('seguimiento_paquete', $trackings)
            ->get(['seguimiento_paquete', 'periodo', 'nombre_proceso', 'razon_social_proveedor',
                'rut_proveedor', 'valor', 'oc', 'empresa_mandante', 'zona'])->keyBy('seguimiento_paquete');
        $paymentPeriods = DB::table('PPR_Pago_Movimientos_Courier')->where('tenant_id', $tenantId)
            ->whereIn('seguimiento_paquete', $trackings)->get(['seguimiento_paquete', 'periodo'])
            ->groupBy('seguimiento_paquete');
        $rows = [];
        $eligibleTrackings = [];
        $now = now();
        foreach ($batch as $row) {
            $tracking = $row['tracking_number'];
            if ($paid->has($tracking)) {
                $result['paid']++;
                $payment = $paid->get($tracking);
                $result['paid_rows'][] = [
                    'source_row' => $row['_source_row'], 'source_date' => $row['fecha'],
                    'tracking' => $tracking, 'os_blue' => $row['external_order_number'],
                    'client' => $row['client_name_source'], 'locality' => $row['destination_locality_name'],
                    'paid_period' => $payment->periodo, 'paid_process' => $payment->nombre_proceso,
                    'provider' => $payment->razon_social_proveedor, 'provider_tax_id' => $payment->rut_proveedor,
                    'amount' => $payment->valor, 'oc' => $payment->oc,
                    'company' => $payment->empresa_mandante, 'zone' => $payment->zona,
                ];
            } else {
                $eligibleTrackings[] = $tracking;
                if ($paymentPeriods->get($tracking, collect())
                    ->contains(fn (object $payment): bool => $closedPeriods->has($payment->periodo))) {
                    $result['closed']++;
                }
            }
            $old = $existing->get($tracking);
            unset($row['_source_row']);
            if ($old !== null && $this->matchesExisting($row, $old)) {
                $result['unchanged']++;

                continue;
            }
            $row['created_at'] = $old?->created_at ?? $now;
            $row['updated_at'] = $now;
            $rows[] = $row;
            $result[$old === null ? 'created' : 'updated']++;
        }
        if ($rows !== []) {
            DB::table('PPR_envios_externos')->upsert($rows, ['tenant_id', 'tracking_number'], [
                'fecha', 'external_order_number', 'external_courier_name', 'destination_locality_name',
                'delivery_point', 'client_name_source', 'observacion', 'exclude_provider_payment', 'updated_at',
            ]);
        }
        $result['payments_excluded'] += DB::table('PPR_Pago_Movimientos_Courier')->where('tenant_id', $tenantId)
            ->whereIn('seguimiento_paquete', $eligibleTrackings)
            ->whereNotIn('periodo', $closedPeriods->keys()->all())
            ->where(fn ($query) => $query->whereNull('condicion_pago')->orWhere('condicion_pago', '<>', 'NO')
                ->orWhereNull('valor')->orWhere('valor', '<>', 0))
            ->update(['condicion_pago' => 'NO', 'valor' => 0, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $row */
    private function matchesExisting(array $row, object $existing): bool
    {
        foreach (['fecha', 'external_order_number', 'external_courier_name', 'destination_locality_name',
            'delivery_point', 'client_name_source', 'observacion', 'exclude_provider_payment'] as $column) {
            if ((string) ($row[$column] ?? '') !== (string) ($existing->{$column} ?? '')) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, list<int>> */
    private function duplicateIds(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $firstRows = [];
        $duplicates = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $line = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    if ($line === 1) {
                        continue;
                    }
                    $values = $row->toArray();
                    if ($this->isEmptyRow($values)) {
                        continue;
                    }
                    $tracking = strtoupper($this->sourceText($values[1] ?? null));
                    if ($tracking === '') {
                        continue;
                    }
                    if (isset($firstRows[$tracking])) {
                        $duplicates[$tracking] ??= [$firstRows[$tracking]];
                        $duplicates[$tracking][] = $line;
                    } else {
                        $firstRows[$tracking] = $line;
                    }
                }
                break;
            }
        } finally {
            $reader->close();
        }

        return $duplicates;
    }

    /** @param list<mixed> $values */
    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($this->sourceText($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param list<mixed> $values
     * @return array<string, mixed>
     */
    private function issue(array $values, int $line, string $reason): array
    {
        return [
            'source_row' => $line,
            'reason' => $reason,
            'fecha' => $this->sourceText($values[0] ?? null),
            'tracking' => $this->sourceText($values[1] ?? null),
            'os_blue' => $this->sourceText($values[2] ?? null),
            'locality' => $this->sourceText($values[3] ?? null),
            'delivery_point' => $this->sourceText($values[4] ?? null),
            'client' => $this->sourceText($values[5] ?? null),
            'observacion' => $this->sourceText($values[6] ?? null),
        ];
    }

    private function sourceText(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('d-m-Y');
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function header(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii(trim((string) $value)))) ?? '';
    }

    private function text(mixed $value): ?string
    {
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

    private function date(mixed $value, int $line): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }
        $text = trim((string) $value);
        foreach (['d-m-Y', 'd/m/Y', 'Y-m-d', 'Y/m/d'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $text);
            if ($date !== false && $date->format($format) === $text) {
                return $date->format('Y-m-d');
            }
        }

        throw ValidationException::withMessages(['file' => "Fecha inválida en la fila {$line}; usa DD-MM-AAAA."]);
    }
}
