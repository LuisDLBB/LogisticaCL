<?php

namespace App\Modules\ProviderPayments\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class CourierSpecialPaymentImporter
{
    /** @return array{total: int, imported: int, existing: int, amount: int} */
    public function import(string $path, string $originalName, int $tenantId): array
    {
        $hash = hash_file('sha256', $path);
        $reader = new Reader;
        $rows = [];

        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                $rowNumber = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $values = $row->toArray();
                    $rowNumber++;
                    if ($rowNumber === 1) {
                        $headers = array_map(fn (mixed $value): string => $this->normalizeHeader($value), $values);
                        $expected = ['fecha', 'usuarioingresa', 'autoriza', 'agente', 'zonatipo', 'id', 'localidad', 'cliente', 'descripcion', 'monto'];
                        if (array_slice($headers, 0, 10) !== $expected) {
                            throw ValidationException::withMessages(['file' => 'Las columnas no coinciden con la base de pagos especiales.']);
                        }

                        continue;
                    }

                    if (count(array_filter($values, fn (mixed $value): bool => $value !== null && $value !== '')) === 0) {
                        continue;
                    }

                    $date = $this->date($values[0] ?? null, $rowNumber);
                    $amount = $values[9] ?? null;
                    if (! is_numeric($amount) || (float) $amount < 0 || (float) $amount !== (float) (int) $amount) {
                        throw ValidationException::withMessages(['file' => "Monto inválido en la fila {$rowNumber}."]);
                    }

                    foreach ([1 => 'Usuario Ingresa', 2 => 'Autoriza', 3 => 'Agente', 4 => 'Zona / Tipo', 6 => 'Localidad'] as $index => $label) {
                        if (trim((string) ($values[$index] ?? '')) === '') {
                            throw ValidationException::withMessages(['file' => "Falta {$label} en la fila {$rowNumber}."]);
                        }
                    }

                    $rows[] = [
                        'tenant_id' => $tenantId,
                        'periodo' => $date->format('Ym').'-Especiales',
                        'fecha' => $date->format('Y-m-d'),
                        'usuario_ingresa' => trim((string) $values[1]),
                        'autoriza' => trim((string) $values[2]),
                        'agente' => trim((string) $values[3]),
                        'zona_tipo' => trim((string) $values[4]),
                        'codigo_seguimiento' => $this->optionalText($values[5] ?? null),
                        'localidad' => trim((string) $values[6]),
                        'cliente' => $this->optionalText($values[7] ?? null),
                        'descripcion' => $this->optionalText($values[8] ?? null),
                        'monto' => (int) $amount,
                        'archivo_origen' => $originalName,
                        'hash_archivo' => $hash,
                        'fila_origen' => $rowNumber,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                break;
            }
        } finally {
            $reader->close();
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'La planilla no contiene pagos especiales.']);
        }

        $imported = DB::transaction(function () use ($rows): int {
            $count = 0;
            foreach (array_chunk($rows, 500) as $chunk) {
                $count += DB::table('courier_special_payments')->insertOrIgnore($chunk);
            }

            return $count;
        });

        return ['total' => count($rows), 'imported' => $imported, 'existing' => count($rows) - $imported, 'amount' => array_sum(array_column($rows, 'monto'))];
    }

    private function normalizeHeader(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii((string) $value))) ?? '';
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
            // The row-specific validation message is returned below.
        }

        throw ValidationException::withMessages(['file' => "Fecha inválida en la fila {$rowNumber}."]);
    }

    private function optionalText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
