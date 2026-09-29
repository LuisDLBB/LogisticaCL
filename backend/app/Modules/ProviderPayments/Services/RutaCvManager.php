<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\Provider;
use App\Models\RutaCv;
use App\Models\RutaCvFrequency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class RutaCvManager
{
    public function __construct(private readonly CalamaProviderTransition $transition) {}

    /** @return array{periodo: string, imported: int, unmatched: int} */
    public function import(string $path, int $tenantId): array
    {
        $spreadsheet = IOFactory::load($path);

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $expected = ['Periodo', 'Proceso', 'Zona', 'Servicio', 'Frecuencia', 'Facturador', 'Usuario', 'Detalle-Ruta', 'Comuna', 'Producto', 'Valor', 'Agente'];
            foreach ($expected as $index => $header) {
                if ($this->normalize((string) $sheet->getCell([$index + 1, 1])->getValue()) !== $this->normalize($header)) {
                    throw ValidationException::withMessages(['file' => 'La planilla no tiene el formato esperado de Base Ruta CV.']);
                }
            }
            for ($day = 1; $day <= 31; $day++) {
                if ((int) $sheet->getCell([$day + 12, 1])->getValue() !== $day) {
                    throw ValidationException::withMessages(['file' => 'Faltan las columnas de días 1 a 31 en la planilla.']);
                }
            }

            $providers = Provider::query()->where('tenant_id', $tenantId)->get();
            $rows = [];
            $period = null;
            $unmatched = 0;
            for ($line = 2; $line <= $sheet->getHighestDataRow(); $line++) {
                if (trim((string) $sheet->getCell("F{$line}")->getValue()) === '') {
                    continue;
                }
                $rowPeriod = trim((string) $sheet->getCell("A{$line}")->getValue());
                if (! $this->validPeriod($rowPeriod) || ($period !== null && $period !== $rowPeriod)) {
                    throw ValidationException::withMessages(['file' => "Período inválido o mezclado en la fila {$line}."]);
                }
                $period = $rowPeriod;
                MonthlyPaymentClosingService::assertOpen($tenantId, $period);
                $valor = $sheet->getCell("K{$line}")->getValue();
                if (! is_numeric($valor) || (float) $valor < 0 || (float) $valor !== (float) (int) $valor) {
                    throw ValidationException::withMessages(['file' => "Valor inválido en la fila {$line}."]);
                }
                $facturador = trim((string) $sheet->getCell("F{$line}")->getValue());
                $provider = $this->matchProvider($facturador, $providers);
                $provider = $this->transition->providerFor($providers, $period, 'Ruta CV', trim((string) $sheet->getCell("I{$line}")->getValue()), null, $provider);
                $unmatched += $provider === null ? 1 : 0;
                $days = [];
                for ($day = 1; $day <= 31; $day++) {
                    if (mb_strtoupper(trim((string) $sheet->getCell([$day + 12, $line])->getValue())) === 'X') {
                        $days[] = $day;
                    }
                }
                $totalCell = $sheet->getCell("AT{$line}")->getValue();
                $fixed = is_numeric($totalCell) && ! str_starts_with((string) $totalCell, '=');
                $amount = $fixed ? (int) $totalCell : null;
                $absences = (int) ($sheet->getCell("AS{$line}")->getValue() ?: 0);
                if ($absences < 0 || $absences > count($days)) {
                    throw ValidationException::withMessages(['file' => "Inasistencia inválida en la fila {$line}."]);
                }
                $data = [
                    'tenant_id' => $tenantId, 'periodo' => $period,
                    'route_key' => hash('sha256', $line.'|'.$this->normalize($facturador).'|'.trim((string) $sheet->getCell("G{$line}")->getValue())),
                    'proceso' => trim((string) $sheet->getCell("B{$line}")->getValue()) ?: 'Ruta CV',
                    'zona' => trim((string) $sheet->getCell("C{$line}")->getValue()),
                    'servicio' => trim((string) $sheet->getCell("D{$line}")->getValue()) ?: 'Ruta CV',
                    'frecuencia' => trim((string) $sheet->getCell("E{$line}")->getValue()),
                    'facturador' => $facturador,
                    'usuario' => trim((string) $sheet->getCell("G{$line}")->getValue()),
                    'detalle_ruta' => trim((string) $sheet->getCell("H{$line}")->getValue()),
                    'comuna' => trim((string) $sheet->getCell("I{$line}")->getValue()),
                    'producto' => trim((string) $sheet->getCell("J{$line}")->getValue()) ?: null,
                    'valor' => (int) $valor,
                    'agente' => trim((string) $sheet->getCell("L{$line}")->getValue()) ?: null,
                    'dias' => $days, 'inasistencia' => $absences,
                    'tipo_cobro' => $fixed ? 'fijo' : 'diario', 'monto_fijo' => $amount,
                    'total_mensual' => $this->total($days, $absences, (int) $valor, $fixed ? 'fijo' : 'diario', $amount),
                    'observacion' => $sheet->getCell("AU{$line}")->getValue() === null ? null : trim((string) $sheet->getCell("AU{$line}")->getValue()),
                    'origen' => 'excel', 'fila_origen' => $line,
                ] + $this->providerFields($provider);
                foreach (['zona', 'frecuencia', 'usuario', 'detalle_ruta', 'comuna'] as $field) {
                    if ($data[$field] === '') {
                        throw ValidationException::withMessages(['file' => "Falta {$field} en la fila {$line}."]);
                    }
                }
                $rows[] = $data;
            }
            if ($rows === [] || $period === null) {
                throw ValidationException::withMessages(['file' => 'La planilla no contiene rutas.']);
            }
            DB::transaction(function () use ($tenantId, $period, $rows): void {
                if (RutaCv::query()->where('tenant_id', $tenantId)->where('periodo', $period)->exists()) {
                    throw ValidationException::withMessages(['file' => "El período {$period} ya tiene rutas. Puedes corregirlas en pantalla sin duplicarlas."]);
                }
                foreach ($rows as $row) {
                    RutaCvFrequency::query()->firstOrCreate(
                        ['tenant_id' => $tenantId, 'name_key' => Str::slug($row['frecuencia'])],
                        ['name' => $row['frecuencia'], 'weekdays' => $this->defaultWeekdays($row['frecuencia'])],
                    );
                    RutaCv::query()->create($row);
                }
            });

            return ['periodo' => $period, 'imported' => count($rows), 'unmatched' => $unmatched];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /** @param list<int> $excludeRouteIds
     * @return array{source: string, created: int, excluded: int}
     */
    public function generate(int $tenantId, string $period, array $excludeRouteIds = []): array
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);
        if (! $this->validPeriod($period)) {
            throw ValidationException::withMessages(['periodo' => 'Selecciona un período AAAAMM válido.']);
        }

        return DB::transaction(function () use ($tenantId, $period, $excludeRouteIds): array {
            if (RutaCv::query()->where('tenant_id', $tenantId)->where('periodo', $period)->exists()) {
                throw ValidationException::withMessages(['periodo' => "El período {$period} ya existe. No se sobrescribió ningún cambio."]);
            }
            $sourcePeriod = RutaCv::query()->where('tenant_id', $tenantId)->where('periodo', '<', $period)
                ->orderByDesc('periodo')->value('periodo');
            if ($sourcePeriod === null) {
                throw ValidationException::withMessages(['periodo' => 'Primero carga un período anterior de Ruta CV.']);
            }
            $source = RutaCv::query()->where('tenant_id', $tenantId)->where('periodo', $sourcePeriod)->get();
            $sourceIds = $source->pluck('id')->all();
            if (array_diff($excludeRouteIds, $sourceIds) !== []) {
                throw ValidationException::withMessages(['exclude_route_ids' => 'La selección incluye rutas que no pertenecen a la base anterior usada para este mes. Vuelve a revisarla.']);
            }
            if (count($excludeRouteIds) === $source->count()) {
                throw ValidationException::withMessages(['exclude_route_ids' => 'Deja al menos una ruta para generar el nuevo período.']);
            }
            $frequencies = RutaCvFrequency::query()->where('tenant_id', $tenantId)->get()->keyBy('name_key');
            $providers = Provider::query()->where('tenant_id', $tenantId)->get();
            foreach ($source->whereNotIn('id', $excludeRouteIds) as $route) {
                $weekdays = $frequencies->get(Str::slug($route->frecuencia))?->weekdays ?: $this->defaultWeekdays($route->frecuencia);
                $days = $this->suggestDays($period, $weekdays);
                $copy = $route->only([
                    'tenant_id', 'route_key', 'proceso', 'zona', 'servicio', 'frecuencia',
                    'facturador', 'usuario', 'detalle_ruta', 'comuna', 'producto', 'valor', 'agente',
                    'provider_id', 'rut_proveedor', 'razon_social_proveedor', 'nombre_pila_proveedor',
                    'tipo_cobro', 'monto_fijo',
                ]);
                $provider = $this->transition->providerFor($providers, $period, 'Ruta CV', $route->comuna, null, $providers->firstWhere('id', $route->provider_id));
                $copy = array_merge($copy, $this->providerFields($provider));
                RutaCv::query()->create($copy + [
                    'periodo' => $period, 'dias' => $days, 'inasistencia' => 0,
                    'total_mensual' => $this->total($days, 0, $route->valor, $route->tipo_cobro, $route->monto_fijo),
                    'observacion' => null, 'origen' => 'generado', 'fila_origen' => null,
                ]);
            }

            return ['source' => $sourcePeriod, 'created' => $source->count() - count($excludeRouteIds), 'excluded' => count($excludeRouteIds)];
        });
    }

    /** @return list<int> */
    public function suggestDays(string $period, array $weekdays): array
    {
        $month = CarbonImmutable::create((int) substr($period, 0, 4), (int) substr($period, 4, 2), 1);
        $days = [];
        for ($day = 1; $day <= $month->daysInMonth; $day++) {
            if (in_array($month->setDay($day)->dayOfWeekIso, $weekdays, true)) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /** @return list<int> */
    public function defaultWeekdays(string $frequency): array
    {
        $normalized = $this->normalize($frequency);

        return match (true) {
            str_contains($normalized, 'lu a vi') => [1, 2, 3, 4, 5],
            str_contains($normalized, 'lu mi vi') => [1, 3, 5],
            str_contains($normalized, 'ma ju vi') => [2, 4, 5],
            default => [],
        };
    }

    /** @param list<int> $days */
    public function total(array $days, int $absences, int $rate, string $mode, ?int $fixedAmount): int
    {
        return $mode === 'fijo' ? (int) $fixedAmount : max(0, count($days) - $absences) * $rate;
    }

    /** @return array{provider_id: ?int, rut_proveedor: ?string, razon_social_proveedor: ?string, nombre_pila_proveedor: ?string} */
    public function providerFields(?Provider $provider): array
    {
        return [
            'provider_id' => $provider?->id,
            'rut_proveedor' => $provider?->tax_id,
            'razon_social_proveedor' => $provider?->legal_name,
            'nombre_pila_proveedor' => $provider?->operational_name,
        ];
    }

    private function matchProvider(string $facturador, $providers): ?Provider
    {
        $name = $this->normalize($facturador);
        $matches = $providers->filter(fn (Provider $provider): bool => $this->normalize($provider->legal_name) === $name
            || $this->normalize((string) $provider->operational_name) === $name);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function normalize(string $value): string
    {
        $value = Str::ascii(mb_strtolower(trim($value)));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim(preg_replace('/\s+(spa|eirl|ltda|sa)$/', '', trim($value)) ?? '');
    }

    private function validPeriod(string $period): bool
    {
        return (bool) preg_match('/^\d{6}$/', $period)
            && checkdate((int) substr($period, 4, 2), 1, (int) substr($period, 0, 4));
    }
}
