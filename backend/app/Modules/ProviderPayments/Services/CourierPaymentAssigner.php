<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\CostCenter;
use App\Models\CostCenterKey;
use App\Models\CostCenterWeightRate;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\ServiceType;
use Illuminate\Support\Facades\DB;

class CourierPaymentAssigner
{
    public function assign(int $tenantId, string $period): array
    {
        $result = ['paid' => 0, 'not_paid' => 0, 'missing_key' => 0, 'ambiguous_key' => 0, 'missing_rate' => 0];
        $services = ServiceType::query()->get()->keyBy(fn (ServiceType $service): string => mb_strtolower(trim($service->name)));
        $keys = CostCenterKey::query()->where('tenant_id', $tenantId)->where('is_active', true)
            ->with(['provider', 'client'])->get()->groupBy(fn (CostCenterKey $key): string => $this->identity(
                $key->provider?->tax_id ?: $key->provider_tax_id,
                $key->client?->tax_id ?: $key->client_tax_id,
                (string) $key->service_code,
            ));
        $centers = CostCenter::query()->where('is_active', true)->get()->keyBy('cost_center_code');
        $rates = CostCenterWeightRate::query()->where('is_active', true)->get()
            ->keyBy(fn (CostCenterWeightRate $rate): string => $rate->cost_center_code.'|'.$rate->final_weight);

        CourierPaymentMovement::query()->where('tenant_id', $tenantId)->where('periodo', $period)
            ->where(fn ($query) => $query->whereNull('condicion_pago')->orWhere('condicion_pago', 'SI'))
            ->select(['id', 'courier_movement_id', 'rut_proveedor', 'rut_cliente', 'peso_final', 'condicion_pago', 'valor'])
            ->chunkById(500, function ($payments) use ($services, $keys, $centers, $rates, &$result): void {
                $movements = CourierMovement::query()->whereIn('id', $payments->pluck('courier_movement_id'))
                    ->pluck('service_name', 'id');
                $updates = [];
                foreach ($payments as $payment) {
                    $serviceName = $movements->get($payment->courier_movement_id);
                    $service = $serviceName ? $services->get(mb_strtolower(trim($serviceName))) : null;
                    if ($service === null || ! filled($payment->rut_proveedor) || ! filled($payment->rut_cliente)) {
                        $result['missing_key']++;
                        continue;
                    }
                    $matches = $keys->get($this->identity($payment->rut_proveedor, $payment->rut_cliente, (string) $service->service_code));
                    if ($matches === null || $matches->isEmpty()) {
                        $result['missing_key']++;
                        continue;
                    }
                    $configurations = $matches->unique(function (CostCenterKey $key): string {
                        $status = strtoupper(trim((string) $key->payment_status));

                        return $status === 'NO' ? 'NO' : $status.'|'.$key->cost_center_code;
                    });
                    if ($configurations->count() !== 1) {
                        $result['ambiguous_key']++;
                        continue;
                    }
                    $key = $configurations->first();
                    $status = strtoupper(trim((string) $key->payment_status));
                    if ($status === 'NO') {
                        $updates['NO|null'][] = $payment->id;
                        continue;
                    }
                    if ($status !== 'SI') {
                        $result['missing_key']++;
                        continue;
                    }
                    $center = $centers->get($key->cost_center_code);
                    $weight = (int) $payment->peso_final;
                    $rate = $center && $weight > 0
                        ? $rates->get($center->cost_center_code.'|'.min($weight, 20)) : null;
                    if ($rate === null) {
                        $result['missing_rate']++;
                        continue;
                    }
                    $value = (int) $rate->value + max(0, $weight - 20) * (int) $center->additional_kilo_value;
                    if ($payment->condicion_pago === 'SI' && (int) $payment->valor === $value) {
                        continue;
                    }
                    $updates['SI|'.$value][] = $payment->id;
                }
                foreach ($updates as $group => $ids) {
                    [$status, $value] = explode('|', $group, 2);
                    $count = DB::table('Pago_Movimientos_Courier')->whereIn('id', $ids)
                        ->where(fn ($query) => $query->whereNull('condicion_pago')->orWhere('condicion_pago', 'SI'))
                        ->update([
                            'condicion_pago' => $status,
                            'valor' => $status === 'SI' ? (int) $value : null,
                            'updated_at' => now(),
                        ]);
                    $result[$status === 'SI' ? 'paid' : 'not_paid'] += $count;
                }
            });

        return $result;
    }

    private function identity(?string $providerRut, ?string $clientRut, string $serviceCode): string
    {
        return strtoupper(trim((string) $providerRut)).'|'.strtoupper(trim((string) $clientRut)).'|'.$serviceCode;
    }
}
