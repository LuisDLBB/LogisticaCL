<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\Client;
use App\Models\Provider;
use App\Models\VisitaDiaria;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class VisitaDiariaManager
{
    public function __construct(private readonly CalamaProviderTransition $transition) {}

    /** @return array{imported: int, pending: int} */
    public function import(string $path, int $tenantId, string $period): array
    {
        $this->assertPeriod($period);
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);
        $spreadsheet = IOFactory::load($path);

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $expected = ['NuevoAgente', 'Local', 'Nombre del Local', 'Direccion', 'Comuna', 'Frecuencia',
                'SLA Operador desde RM (Paq)', 'SLA Cliente desde RM (Paq)', 'SLA desde Locales a CD',
                'Estatus', 'Razón social proveedor', 'Nombre de pila proveedor', 'RUT proveedor',
                'Valor x Dia', 'Cliente', 'Rut Cliente', 'Comerciante (Pila)'];
            foreach ($expected as $index => $header) {
                if ($this->normalize((string) $sheet->getCell([$index + 1, 1])->getValue()) !== $this->normalize($header)) {
                    throw ValidationException::withMessages(['file' => 'La planilla no tiene las 17 columnas esperadas de Base_Visitas.']);
                }
            }

            $allProviders = Provider::query()->where('tenant_id', $tenantId)->get();
            $providers = $allProviders->groupBy(fn (Provider $provider): string => $this->rutKey($provider->tax_id));
            $clients = Client::query()->where('tenant_id', $tenantId)->get()->groupBy(fn (Client $client): string => $this->rutKey($client->tax_id));
            $rows = [];
            $keys = [];
            $pending = 0;
            for ($line = 2; $line <= $sheet->getHighestDataRow(); $line++) {
                $values = [];
                for ($column = 1; $column <= 17; $column++) {
                    $values[] = $sheet->getCell([$column, $line])->getValue();
                }
                if (trim((string) ($values[1] ?? '')) === '' && trim((string) ($values[2] ?? '')) === '') {
                    continue;
                }
                if (trim((string) $values[1]) === '' || trim((string) $values[2]) === ''
                    || trim((string) $values[4]) === '' || trim((string) $values[5]) === '') {
                    throw ValidationException::withMessages(['file' => "Faltan local, comuna o frecuencia en la fila {$line}."]);
                }
                $rate = $values[13];
                if (! is_numeric($rate) || (float) $rate < 0 || (float) $rate !== (float) (int) $rate) {
                    throw ValidationException::withMessages(['file' => "Valor por día inválido en la fila {$line}."]);
                }
                $providerRut = trim((string) $values[12]);
                $clientRut = trim((string) $values[15]);
                $providerMatches = $providers->get($this->rutKey($providerRut), collect());
                $clientMatches = $clients->get($this->rutKey($clientRut), collect());
                $provider = $providerMatches->count() === 1 ? $providerMatches->first() : null;
                $provider = $this->transition->providerFor($allProviders, $period, 'Visitas', trim((string) $values[4]), null, $provider);
                $client = $clientMatches->count() === 1 ? $clientMatches->first() : null;
                $pending += ($provider === null || $client === null || trim((string) $values[3]) === '') ? 1 : 0;
                $key = hash('sha256', implode('|', [$this->rutKey($providerRut), $this->rutKey($clientRut),
                    $this->normalize((string) $values[1]), $this->normalize((string) $values[2]), $this->normalize((string) $values[3])]));
                if (isset($keys[$key])) {
                    throw ValidationException::withMessages(['file' => "La fila {$line} repite el proveedor, cliente y local de la fila {$keys[$key]}."]);
                }
                $keys[$key] = $line;
                $days = $this->suggestDays($period, (string) $values[5]);
                $rows[] = [
                    'tenant_id' => $tenantId, 'periodo' => $period, 'nombre_proceso' => $period.'-Visitas', 'visit_key' => $key,
                    'agente_original' => $this->text($values[0]), 'local' => trim((string) $values[1]),
                    'nombre_local' => trim((string) $values[2]), 'direccion' => trim((string) $values[3]),
                    'comuna' => trim((string) $values[4]), 'frecuencia' => trim((string) $values[5]),
                    'sla_operador' => $this->text($values[6]), 'sla_cliente' => $this->text($values[7]),
                    'sla_local_cd' => $this->text($values[8]), 'estatus_origen' => $this->text($values[9]),
                    'razon_social_proveedor_origen' => $this->text($values[10]),
                    'nombre_pila_proveedor_origen' => $this->text($values[11]),
                    'rut_proveedor_origen' => $providerRut ?: null, 'provider_id' => $provider?->id,
                    'razon_social_cliente_origen' => $this->text($values[14]),
                    'rut_cliente_origen' => $clientRut ?: null, 'comerciante_pila_origen' => $this->text($values[16]),
                    'client_id' => $client?->id,
                    'zona' => ProviderZone::resolve($provider?->tax_id ?: $providerRut, $provider?->id, in_array($provider?->operator_type, ['RM', 'Regiones'], true) ? $provider->operator_type : null),
                    'valor_dia' => (int) $rate, 'dias' => $days,
                    'total_mensual' => $this->total($days, (int) $rate), 'origen' => 'excel', 'fila_origen' => $line,
                ];
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => 'La planilla no contiene visitas.']);
            }
            DB::transaction(function () use ($rows, $tenantId, $period): void {
                if (VisitaDiaria::query()->where('tenant_id', $tenantId)->where('periodo', $period)->exists()) {
                    throw ValidationException::withMessages(['periodo' => "El período {$period} ya tiene visitas. No se duplicó la carga."]);
                }
                foreach ($rows as $row) {
                    VisitaDiaria::query()->create($row);
                }
            });

            return ['imported' => count($rows), 'pending' => $pending];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function generate(int $tenantId, string $period): int
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);
        $this->assertPeriod($period);

        return DB::transaction(function () use ($tenantId, $period): int {
            if (VisitaDiaria::query()->where('tenant_id', $tenantId)->where('periodo', $period)->exists()) {
                throw ValidationException::withMessages(['periodo' => "El período {$period} ya existe. No se sobrescribió."]);
            }
            $previous = CarbonImmutable::create((int) substr($period, 0, 4), (int) substr($period, 4, 2), 1)->subMonth()->format('Ym');
            $source = VisitaDiaria::query()->where('tenant_id', $tenantId)->where('periodo', $previous)->get();
            if ($source->isEmpty()) {
                throw ValidationException::withMessages(['periodo' => "No hay visitas en {$previous} para generar este mes."]);
            }
            $providers = Provider::query()->where('tenant_id', $tenantId)->get();
            foreach ($source as $visit) {
                $days = $this->suggestDays($period, $visit->frecuencia);
                $provider = $this->transition->providerFor($providers, $period, 'Visitas', $visit->comuna, null, $providers->firstWhere('id', $visit->provider_id));
                $copy = $visit->only([
                    'tenant_id', 'visit_key', 'agente_original', 'local', 'nombre_local', 'direccion', 'comuna',
                    'frecuencia', 'sla_operador', 'sla_cliente', 'sla_local_cd', 'estatus_origen',
                    'razon_social_proveedor_origen', 'nombre_pila_proveedor_origen', 'rut_proveedor_origen', 'provider_id',
                    'razon_social_cliente_origen', 'rut_cliente_origen', 'comerciante_pila_origen', 'client_id', 'zona', 'valor_dia',
                ]);
                $copy['provider_id'] = $provider?->id;
                $copy['zona'] = ProviderZone::resolve($provider?->tax_id, $provider?->id, $copy['zona'] ?? null);
                VisitaDiaria::query()->create($copy + ['periodo' => $period, 'nombre_proceso' => $period.'-Visitas', 'dias' => $days,
                    'total_mensual' => $this->total($days, $visit->valor_dia), 'origen' => 'generado']);
            }

            return $source->count();
        });
    }

    /** @return list<int> */
    public function suggestDays(string $period, string $frequency): array
    {
        $this->assertPeriod($period);
        $month = CarbonImmutable::create((int) substr($period, 0, 4), (int) substr($period, 4, 2), 1);
        $frequency = $this->normalize($frequency);
        if (str_contains($frequency, '15 dias') || str_contains($frequency, 'quincenal')) {
            return [15, $month->daysInMonth];
        }
        if (str_contains($frequency, '30 dias') || str_contains($frequency, 'mensual')) {
            return [$month->daysInMonth];
        }
        $weekdays = match (true) {
            str_contains($frequency, 'lunes a viernes') => [1, 2, 3, 4, 5],
            str_contains($frequency, 'lunes miercoles viernes') => [1, 3, 5],
            str_contains($frequency, 'martes y jueves') => [2, 4],
            $frequency === 'miercoles' => [3],
            $frequency === 'jueves' => [4],
            default => [],
        };
        $days = [];
        for ($day = 1; $day <= $month->daysInMonth; $day++) {
            if ($period === '202609' && $day === 18 && str_contains($frequency, 'lunes a viernes')) {
                continue;
            }
            if (in_array($month->setDay($day)->dayOfWeekIso, $weekdays, true)) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /** @param list<int> $days */
    public function total(array $days, int $rate): int
    {
        return count($days) * $rate;
    }

    public function assertPeriod(string $period): void
    {
        if (! preg_match('/^\d{6}$/', $period)
            || ! checkdate((int) substr($period, 4, 2), 1, (int) substr($period, 0, 4))) {
            throw ValidationException::withMessages(['periodo' => 'Selecciona un período AAAAMM válido.']);
        }
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function rutKey(?string $rut): string
    {
        return preg_replace('/[^0-9K]/', '', mb_strtoupper((string) $rut)) ?? '';
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(mb_strtolower(trim($value)))) ?? '');
    }
}
