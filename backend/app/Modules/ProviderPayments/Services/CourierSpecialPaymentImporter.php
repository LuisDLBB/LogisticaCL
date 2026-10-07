<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\MaestroPago;
use App\Models\Provider;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class CourierSpecialPaymentImporter
{
    public function __construct(private readonly CalamaProviderTransition $transition) {}

    /** @return array{total: int, imported: int, existing: int, reassigned: int, amount: int, paid_rows: array} */
    public function import(string $path, string $originalName, int $tenantId, string $period): array
    {
        if (! preg_match('/^\d{6}-Especiales$/', $period)) {
            throw ValidationException::withMessages(['period_month' => 'Selecciona un período de pago válido.']);
        }
        MonthlyPaymentClosingService::assertOpen($tenantId, substr($period, 0, 6));

        $hash = hash_file('sha256', $path);
        $reader = new Reader;
        $rows = [];
        $paidRows = [];
        $providers = Provider::query()->where('tenant_id', $tenantId)->get();

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

                    $tracking = $this->optionalText($values[5] ?? null);
                    $paid = $tracking !== null && strtoupper($tracking) !== 'N/A'
                        ? MaestroPago::query()->whereKey(MaestroPago::trackingKey($tracking))->first() : null;
                    if ($paid !== null) {
                        $paidRows[] = [
                            'tracking' => $tracking,
                            'source_row' => $rowNumber,
                            'paid_period' => $paid->periodo,
                            'paid_process' => $paid->nombre_proceso,
                            'provider' => $paid->razon_social_proveedor,
                            'amount' => $paid->valor === null ? null : (int) $paid->valor,
                        ];

                        continue;
                    }

                    $agent = trim((string) $values[3]);
                    $locality = trim((string) $values[6]);
                    $provider = $this->transition->providerFor($providers, substr($period, 0, 6), 'Especiales', $locality, null, null);
                    $provider ??= $this->transition->providerFor($providers, substr($period, 0, 6), 'Especiales', preg_replace('/^operador\s+/iu', '', $agent), null, null);

                    $rows[] = [
                        'tenant_id' => $tenantId,
                        'periodo' => $period,
                        'fecha' => $date->format('Y-m-d'),
                        'usuario_ingresa' => trim((string) $values[1]),
                        'autoriza' => trim((string) $values[2]),
                        'agente' => $agent,
                        'zona_tipo' => trim((string) $values[4]),
                        'codigo_seguimiento' => $tracking,
                        'localidad' => $locality,
                        'provider_id' => $provider?->id,
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

        if ($rows === [] && $paidRows === []) {
            throw ValidationException::withMessages(['file' => 'La planilla no contiene pagos especiales.']);
        }

        [$imported, $reassigned] = DB::transaction(function () use ($rows, $tenantId, $hash, $period): array {
            $closedPeriods = DB::table('PPR_Cierres_Pagos')->where('tenant_id', $tenantId)->pluck('periodo')
                ->map(fn (string $closedPeriod): string => $closedPeriod.'-Especiales');
            if ($closedPeriods->isNotEmpty() && DB::table('PPR_courier_special_payments')
                ->where('tenant_id', $tenantId)->where('hash_archivo', $hash)->whereNull('finalized_at')
                ->whereIn('periodo', $closedPeriods)->exists()) {
                throw ValidationException::withMessages(['file' => 'Este archivo ya tiene registros en un período cerrado. No se pueden trasladar a otro mes.']);
            }
            $reassigned = DB::table('PPR_courier_special_payments')
                ->where('tenant_id', $tenantId)->where('hash_archivo', $hash)->whereNull('finalized_at')->where('periodo', '!=', $period)
                ->update(['periodo' => $period, 'updated_at' => now()]);
            $count = 0;
            foreach (array_chunk($rows, 500) as $chunk) {
                $count += DB::table('PPR_courier_special_payments')->insertOrIgnore($chunk);
            }

            return [$count, $reassigned];
        });

        return ['total' => count($rows) + count($paidRows), 'imported' => $imported, 'existing' => count($rows) - $imported, 'reassigned' => $reassigned, 'amount' => array_sum(array_column($rows, 'monto')), 'paid_rows' => $paidRows];
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
