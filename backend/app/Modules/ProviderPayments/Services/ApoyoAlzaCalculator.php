<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\Acuerdo;
use App\Models\ApoyoAlza;
use App\Models\RutaCv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApoyoAlzaCalculator
{
    private const DEYNA_VISIT_SERVICES = 'Visitas Lun a Vie | Visitas Miercoles | Visita Mensual | Visita SMU Mi';

    private const CLAUDIO_ROUTE_SERVICES = 'Ruta Lunes | Ruta Martes | Ruta Miercoles | Ruta Jueves | Ruta Viernes';

    /** @return array{total: int, calculados: int, no_pagar: int, pendientes: int, monto: int} */
    public function recalculate(int $tenantId, string $period): array
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);

        return DB::transaction(function () use ($tenantId, $period): array {
            $rows = ApoyoAlza::query()->with('provider')->where('tenant_id', $tenantId)
                ->where('periodo', $period)->orderBy('id')->lockForUpdate()->get();
            if ($rows->contains(fn (ApoyoAlza $row): bool => $row->closed_at !== null)) {
                throw ValidationException::withMessages(['periodo' => 'El período de Apoyo Alza está cerrado. Reábrelo con la clave maestra antes de recalcular.']);
            }
            $sourceNames = ['Acuerdos', 'Variable', 'Ruta CV'];
            $sourceGroups = DB::table('Pago_Movimientos_Courier')
                ->where('tenant_id', $tenantId)->where('periodo', $period)->where('condicion_pago', 'SI')
                ->whereIn('nombre_proceso', $sourceNames)
                ->selectRaw('nombre_proceso, rut_proveedor, service_name, COUNT(*) AS registros,
                    SUM(COALESCE(valor, 0)) AS monto, SUM(COALESCE(peso_final, 0)) AS dias,
                    SUM(CASE WHEN valor IS NULL THEN 1 ELSE 0 END) AS sin_valor')
                ->groupBy('nombre_proceso', 'rut_proveedor', 'service_name')->get();
            $bases = [];
            foreach ($sourceGroups as $source) {
                $sourceType = match ($source->nombre_proceso) {
                    'Acuerdos' => 'Acuerdos',
                    'Ruta CV' => 'Ruta CV',
                    default => 'Variables',
                };
                $key = $this->key($sourceType, (string) $source->rut_proveedor, (string) $source->service_name);
                $bases[$key] ??= ['registros' => 0, 'monto' => 0, 'dias' => 0, 'sin_valor' => 0];
                $bases[$key]['registros'] += (int) $source->registros;
                $bases[$key]['monto'] += (int) $source->monto;
                $bases[$key]['dias'] += (int) $source->dias;
                $bases[$key]['sin_valor'] += (int) $source->sin_valor;
            }

            $fixedProcessesOpen = [
                'Acuerdos' => Acuerdo::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                    ->whereNull('closed_at')->exists(),
                'Ruta CV' => RutaCv::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                    ->whereNull('closed_at')->exists(),
            ];
            $ready = 0;
            $noPay = 0;
            $totalAmount = 0;
            foreach ($rows as $row) {
                $this->applyProviderRules($row);
                $source = $this->sourceFor($row, $bases);
                $status = 'calculado';
                $amount = null;
                if (($row->factor === 'Dia de Ruta CV' && $row->monto_dia === 0)
                    || ($row->factor === '%' && $row->porcentaje !== null && (float) $row->porcentaje === 0.0)) {
                    $status = 'no_pagar';
                    $amount = 0;
                } elseif ($row->provider === null || $row->provider->tenant_id !== $tenantId) {
                    $status = 'sin_proveedor';
                } elseif ($fixedProcessesOpen[$row->proceso_base] ?? false) {
                    $status = 'proceso_abierto';
                } elseif ($source === null) {
                    $status = 'sin_base';
                } elseif ($row->proceso_base === 'Ruta CV') {
                    if ($row->monto_dia === null) {
                        $status = 'sin_factor';
                    } else {
                        $amount = $source['dias'] * $row->monto_dia;
                    }
                } elseif ($source['sin_valor'] > 0) {
                    $status = 'sin_valor_base';
                } elseif ($row->porcentaje === null) {
                    $status = 'sin_factor';
                } else {
                    $amount = (int) round($source['monto'] * (float) $row->porcentaje, 0, PHP_ROUND_HALF_UP);
                }
                if ($status === 'calculado' && $amount === 0) {
                    $status = 'no_pagar';
                }
                $row->update([
                    'servicio_acuerdo' => $row->servicio_acuerdo,
                    'porcentaje' => $row->porcentaje,
                    'registros_base' => $source['registros'] ?? 0,
                    'monto_base' => $source['monto'] ?? null,
                    'dias_base' => $row->proceso_base === 'Ruta CV' ? ($source['dias'] ?? null) : null,
                    'monto_apoyo' => $amount,
                    'estado_calculo' => $status,
                    'calculado_at' => now(),
                ]);
                if ($status === 'calculado') {
                    $ready++;
                    $totalAmount += $amount;
                } elseif ($status === 'no_pagar') {
                    $noPay++;
                }
            }

            return ['total' => $rows->count(), 'calculados' => $ready,
                'no_pagar' => $noPay, 'pendientes' => $rows->count() - $ready - $noPay, 'monto' => $totalAmount];
        });
    }

    private function key(string $process, string $rut, string $service): string
    {
        $serviceKey = $process === 'Acuerdos' ? Str::of($service)->squish()->ascii()->lower()->toString() : '';

        return $process.'|'.$this->normalizedRut($rut).'|'.$serviceKey;
    }

    private function normalizedRut(string $rut): string
    {
        return preg_replace('/[^0-9k]/', '', mb_strtolower($rut));
    }

    private function applyProviderRules(ApoyoAlza $row): void
    {
        if ($row->periodo < '202609') {
            return;
        }

        $rut = $this->normalizedRut((string) $row->provider?->tax_id);
        if ($rut === '774586083' && $row->proceso_base === 'Acuerdos') {
            $row->servicio_acuerdo = self::DEYNA_VISIT_SERVICES;
        }
        if ($rut === '125381278' && in_array($row->proceso_base, ['Acuerdos', 'Variables'], true)) {
            $row->porcentaje = '0.019000';
            if ($row->proceso_base === 'Acuerdos') {
                $row->servicio_acuerdo = self::CLAUDIO_ROUTE_SERVICES;
            }
        }
    }

    /**
     * @param  array<string, array{registros: int, monto: int, dias: int, sin_valor: int}>  $bases
     * @return array{registros: int, monto: int, dias: int, sin_valor: int}|null
     */
    private function sourceFor(ApoyoAlza $row, array $bases): ?array
    {
        if ($row->provider === null) {
            return null;
        }
        if ($row->proceso_base !== 'Acuerdos') {
            return $bases[$this->key($row->proceso_base, $row->provider->tax_id, '')] ?? null;
        }

        $services = collect(explode('|', (string) $row->servicio_acuerdo))
            ->map(fn (string $service): string => trim($service))
            ->filter()
            ->map(fn (string $service): string => $this->key($row->proceso_base, $row->provider->tax_id, $service))
            ->unique()->values();
        if ($services->isEmpty()) {
            return null;
        }

        $combined = ['registros' => 0, 'monto' => 0, 'dias' => 0, 'sin_valor' => 0];
        foreach ($services as $key) {
            if (! isset($bases[$key])) {
                return null;
            }
            foreach ($combined as $field => $value) {
                $combined[$field] += $bases[$key][$field];
            }
        }

        return $combined;
    }
}
