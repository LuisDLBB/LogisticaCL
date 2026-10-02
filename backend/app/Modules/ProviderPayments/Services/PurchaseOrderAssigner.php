<?php

namespace App\Modules\ProviderPayments\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseOrderAssigner
{
    /** @return array<string, string> */
    public function forPeriod(int $tenantId, string $period): array
    {
        $groups = [];

        DB::table('PPR_Pago_Movimientos_Courier')
            ->where('tenant_id', $tenantId)
            ->where('periodo', $period)
            ->whereRaw('UPPER(TRIM(condicion_pago)) = ?', ['SI'])
            ->orderBy('id')
            ->chunkById(500, function ($payments) use (&$groups): void {
                foreach ($payments as $payment) {
                    $key = $this->groupKey($payment);
                    $zone = $this->zone($payment);
                    $name = $this->normalized((string) $payment->razon_social_proveedor);
                    $company = $this->normalized((string) $payment->empresa_mandante);
                    $documentFamily = str_starts_with($this->normalized((string) $payment->tipo_documento), 'BOLETA') ? 'Boleta' : 'Factura';

                    if ($zone === '' || $name === '' || $company === '' || $this->rut($payment) === '') {
                        throw ValidationException::withMessages([
                            'period' => "El pago {$payment->id} necesita zona, razón social, RUT del proveedor y empresa mandante para asignar una OC.",
                        ]);
                    }

                    if (isset($groups[$key]) && $groups[$key]['document_family'] !== $documentFamily) {
                        throw ValidationException::withMessages([
                            'period' => "La OC del proveedor {$payment->rut_proveedor} reúne factura y boleta. Separe esos pagos antes del cierre.",
                        ]);
                    }

                    if (! isset($groups[$key]) || strcmp($name, $groups[$key]['name']) < 0) {
                        $groups[$key] = [
                            'zone' => $zone,
                            'name' => $name,
                            'company' => $company,
                            'bucket' => $this->bucket($payment),
                            'document_family' => $documentFamily,
                        ];
                    }
                }
            });

        uksort($groups, function (string $left, string $right) use ($groups): int {
            $a = $groups[$left];
            $b = $groups[$right];

            return [$this->zoneRank($a['zone']), $a['name'], $a['company'], $this->bucketRank($a['bucket']), $left]
                <=> [$this->zoneRank($b['zone']), $b['name'], $b['company'], $this->bucketRank($b['bucket']), $right];
        });

        $lastOc = DB::table('PPR_Maestro_Pagos')
            ->where('tenant_id', $tenantId)
            ->where('periodo', $period)
            ->where('oc', 'like', $period.'%')
            ->max('oc');
        $sequence = $lastOc === null ? 0 : (int) substr((string) $lastOc, 6);
        $assigned = [];

        foreach (array_keys($groups) as $key) {
            $sequence++;
            if ($sequence > 9999) {
                throw ValidationException::withMessages(['period' => "El período {$period} superó el límite de 9.999 órdenes de compra."]);
            }
            $assigned[$key] = sprintf('%s%04d', $period, $sequence);
        }

        return $assigned;
    }

    public function groupKey(object $payment): string
    {
        return json_encode([
            $this->zone($payment),
            $this->rut($payment),
            $this->normalized((string) $payment->empresa_mandante),
            $this->bucket($payment),
        ], JSON_THROW_ON_ERROR);
    }

    public function concept(object $payment): string
    {
        return match ($this->bucket($payment)) {
            'TRONCAL NORTE' => 'Troncal Norte',
            'TRONCAL V' => 'Troncal V',
            'SERVICIOS HASTA 14-08-2026' => 'Servicios hasta 14/08/2026',
            'SERVICIOS HASTA 17-09-2026' => 'Servicios hasta 17/09/2026',
            default => 'General',
        };
    }

    private function bucket(object $payment): string
    {
        $rut = $this->rut($payment);
        $service = $this->normalized((string) ($payment->service_name ?? ''));
        $process = $this->normalized((string) ($payment->nombre_proceso ?? ''));

        if ($rut === '772015259') {
            if (str_contains($service, 'TRONCAL NORTE')) {
                return 'TRONCAL NORTE';
            }
            if (str_contains($service, 'TRONCAL V') || ($process === 'ACUERDOS' && $service === 'FIJO MENSUAL')) {
                return 'TRONCAL V';
            }
        }

        if ($rut === '178509683' && $process === 'SERVICIOS') {
            $date = substr((string) ($payment->fecha ?? ''), 0, 10);
            if ((string) $payment->periodo === '202608' && $date >= '2026-08-01' && $date <= '2026-08-14') {
                return 'SERVICIOS HASTA 14-08-2026';
            }
            if ((string) $payment->periodo === '202609' && $date >= '2026-09-01' && $date <= '2026-09-17') {
                return 'SERVICIOS HASTA 17-09-2026';
            }
        }

        return 'GENERAL';
    }

    private function rut(object $payment): string
    {
        return preg_replace('/[^0-9K]/', '', strtoupper((string) ($payment->rut_proveedor ?? '')));
    }

    private function zone(object $payment): string
    {
        if (ProviderZone::isDsGroup((string) ($payment->rut_proveedor ?? ''))) {
            return 'RM';
        }

        return $this->normalized((string) ($payment->zona ?? ''));
    }

    private function normalized(string $value): string
    {
        return preg_replace('/\s+/', ' ', strtoupper(Str::ascii(trim($value))));
    }

    private function zoneRank(string $zone): int
    {
        return match ($zone) {
            'RM' => 0,
            'REGIONES' => 1,
            default => 2,
        };
    }

    private function bucketRank(string $bucket): int
    {
        return match ($bucket) {
            'GENERAL' => 0,
            'SERVICIOS HASTA 14-08-2026' => 1,
            'SERVICIOS HASTA 17-09-2026' => 1,
            'TRONCAL NORTE' => 2,
            'TRONCAL V' => 3,
            default => 4,
        };
    }
}
