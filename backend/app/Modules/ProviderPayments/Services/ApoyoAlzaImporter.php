<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\ApoyoAlza;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ApoyoAlzaImporter
{
    private const HEADERS = [
        'razonsocialcliente', 'rutcliente', 'proceso', 'serviciodeacuerdo', 'factor',
        'porcentaje', 'monto', 'empresamandante', 'agencia',
    ];

    public function __construct(private readonly ApoyoAlzaCalculator $calculator, private readonly CalamaProviderTransition $transition) {}

    /** @return array{period: string, imported: int, existing: int, calculated: int, pending: int, amount: int} */
    public function import(string $path, string $filename, int $tenantId, string $period): array
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);
        $workbook = IOFactory::load($path);
        try {
            $sheet = $workbook->getActiveSheet();
            $headers = [];
            for ($column = 1; $column <= 9; $column++) {
                $headers[] = $this->normalize($sheet->getCell([$column, 1])->getValue());
            }
            if ($headers !== self::HEADERS) {
                throw ValidationException::withMessages(['file' => 'Las nueve columnas de Apoyo Alza no coinciden con la plantilla adjunta.']);
            }

            $providers = Provider::query()->where('tenant_id', $tenantId)->get();
            $providersByRut = $providers->groupBy(fn (Provider $provider): string => $this->rut($provider->tax_id));
            $hash = hash_file('sha256', $path);
            $rows = [];
            for ($line = 2; $line <= $sheet->getHighestDataRow(); $line++) {
                if ($line > 5001) {
                    throw ValidationException::withMessages(['file' => 'La planilla supera el máximo de 5.000 filas de apoyo.']);
                }
                $values = [];
                for ($column = 1; $column <= 9; $column++) {
                    $cell = $sheet->getCell([$column, $line]);
                    $raw = $cell->getValue();
                    $values[] = is_string($raw) && str_starts_with($raw, '=')
                        ? $cell->getOldCalculatedValue() : $raw;
                }
                if (collect($values)->every(fn ($value): bool => $value === null || trim((string) $value) === '')) {
                    continue;
                }
                $name = trim((string) $values[0]);
                $rut = trim((string) ($values[1] ?? ''));
                $process = trim((string) $values[2]);
                $factor = trim((string) $values[4]);
                if ($name === '' || mb_strlen($name) > 255) {
                    throw ValidationException::withMessages(['file' => "La fila {$line} requiere un nombre de proveedor válido."]);
                }
                if (! in_array($process, ['Acuerdos', 'Variables', 'Ruta CV'], true)) {
                    throw ValidationException::withMessages(['file' => "El proceso de la fila {$line} debe ser Acuerdos, Variables o Ruta CV."]);
                }
                if (($process === 'Ruta CV' && $factor !== 'Dia de Ruta CV')
                    || ($process !== 'Ruta CV' && $factor !== '%')) {
                    throw ValidationException::withMessages(['file' => "El factor de la fila {$line} no corresponde al proceso {$process}."]);
                }
                $service = trim((string) ($values[3] ?? ''));
                if (($process === 'Acuerdos' && $service === '') || mb_strlen($service) > 160) {
                    throw ValidationException::withMessages(['file' => "Falta el servicio de Acuerdos en la fila {$line}."]);
                }
                $rate = $factor === '%' ? $this->percentage($values[5], $line) : null;
                $dailyAmount = $factor === 'Dia de Ruta CV' ? $this->wholeAmount($values[6], $line) : null;
                $company = trim((string) ($values[7] ?? ''));
                $agency = trim((string) ($values[8] ?? ''));
                if ($company === '' || mb_strlen($company) > 100 || $agency === '' || mb_strlen($agency) > 150) {
                    throw ValidationException::withMessages(['file' => "La fila {$line} requiere empresa mandante y agencia válidas."]);
                }
                $rutMatches = $rut === '' ? collect() : $providersByRut->get($this->rut($rut), collect());
                $nameMatches = $providers->filter(fn (Provider $provider): bool => in_array($this->normalize($name), [
                    $this->normalize($provider->legal_name), $this->normalize($provider->operational_name),
                ], true));
                $matches = $rutMatches->count() === 1 ? $rutMatches : $nameMatches;
                $provider = $matches->count() === 1 ? $matches->first() : null;
                $provider = $this->transition->providerFor($providers, $period, 'Apoyo', $agency, null, $provider);
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'periodo' => $period,
                    'nombre_proceso' => $period.'-Apoyo',
                    'proveedor_origen' => $name,
                    'rut_proveedor_origen' => $rut ?: null,
                    'provider_id' => $provider?->id,
                    'proceso_base' => $process,
                    'servicio_acuerdo' => $service ?: null,
                    'factor' => $factor,
                    'porcentaje' => $rate,
                    'monto_dia' => $dailyAmount,
                    'empresa_mandante' => $company,
                    'agencia' => $agency,
                    'archivo_origen' => $filename,
                    'hash_archivo' => $hash,
                    'fila_origen' => $line,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => 'La planilla Apoyo Alza no contiene filas de datos.']);
            }

            return DB::transaction(function () use ($tenantId, $period, $hash, $rows): array {
                $existing = ApoyoAlza::query()->where('tenant_id', $tenantId)->where('periodo', $period)->get();
                if ($existing->isNotEmpty()) {
                    if ($existing->contains(fn (ApoyoAlza $row): bool => $row->closed_at !== null)) {
                        throw ValidationException::withMessages(['file' => "El período {$period} de Apoyo Alza está cerrado. Reábrelo con la clave maestra antes de cargarlo."]);
                    }
                    if ($existing->count() !== count($rows) || $existing->contains(fn (ApoyoAlza $row): bool => $row->hash_archivo !== $hash)) {
                        throw ValidationException::withMessages(['file' => "El período {$period} ya tiene Apoyo Alza cargado. Corrige sus filas aquí para evitar duplicados."]);
                    }

                    return ['period' => $period, 'imported' => 0, 'existing' => $existing->count(),
                        'calculated' => $existing->where('estado_calculo', 'calculado')->count(),
                        'pending' => $existing->whereNotIn('estado_calculo', ['calculado', 'no_pagar'])->count(),
                        'amount' => (int) $existing->sum('monto_apoyo')];
                }
                foreach (array_chunk($rows, 250) as $chunk) {
                    DB::table('apoyo_alzas')->insert($chunk);
                }
                $result = $this->calculator->recalculate($tenantId, $period);

                return ['period' => $period, 'imported' => count($rows), 'existing' => 0,
                    'calculated' => $result['calculados'], 'pending' => $result['pendientes'],
                    'amount' => $result['monto']];
            });
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    private function normalize(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(Str::ascii(trim((string) $value))));
    }

    private function rut(string $value): string
    {
        return preg_replace('/[^0-9k]/', '', mb_strtolower($value));
    }

    private function percentage(mixed $value, int $line): string
    {
        $text = trim((string) $value);
        $percentText = str_ends_with($text, '%');
        if ($percentText) {
            $text = rtrim($text, '%');
        }
        if (! is_numeric($text)) {
            throw ValidationException::withMessages(['file' => "El porcentaje de la fila {$line} debe ser numérico."]);
        }
        $rate = (float) $text / ($percentText ? 100 : 1);
        if ($rate < 0 || $rate > 1) {
            throw ValidationException::withMessages(['file' => "El porcentaje de la fila {$line} debe estar entre 0 % y 100 %."]);
        }

        return number_format($rate, 6, '.', '');
    }

    private function wholeAmount(mixed $value, int $line): int
    {
        if (! is_numeric($value) || (float) $value < 0 || floor((float) $value) !== (float) $value) {
            throw ValidationException::withMessages(['file' => "El monto por día de la fila {$line} debe ser un entero no negativo."]);
        }

        return (int) $value;
    }
}
