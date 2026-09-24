<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\CostCenter;
use App\Models\CostCenterKey;
use App\Models\CostCenterWeightRate;
use App\Models\Coverage;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\ServiceType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CourierPaymentAssigner
{
    public function assign(int $tenantId, string $period): array
    {
        $result = ['paid' => 0, 'not_paid' => 0, 'missing_key' => 0, 'ambiguous_key' => 0, 'missing_rate' => 0,
            'missing_coverage' => 0, 'ambiguous_coverage' => 0, 'missing_return_value' => 0,
            'weight_defaulted' => 0, 'weights_recalculated' => 0];
        $this->syncFinalWeights($tenantId, $period, $result);
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
        $coverages = Coverage::query()->where('tenant_id', $tenantId)->where('is_active', true)
            ->with('provider')->get()->groupBy(fn (Coverage $coverage): string => $this->communeKey($coverage->commune_name));

        CourierPaymentMovement::query()->where('tenant_id', $tenantId)->where('periodo', $period)
            ->where(fn ($query) => $query->whereNull('condicion_pago')->orWhere('condicion_pago', 'SI'))
            ->select(['id', 'courier_movement_id', 'rut_proveedor', 'rut_cliente', 'comuna_matriz', 'peso_final', 'condicion_pago', 'valor', 'nombre_proceso', 'comuna_destino', 'fecha'])
            ->chunkById(500, function ($payments) use ($services, $keys, $centers, $rates, $coverages, &$result): void {
                $movements = CourierMovement::query()->whereIn('id', $payments->pluck('courier_movement_id'))
                    ->pluck('service_name', 'id');
                $updates = [];
                foreach ($payments as $payment) {
                    if (strcasecmp(trim((string) $payment->nombre_proceso), 'Retornos') === 0) {
                        $return = $this->returnPayment($payment, $coverages);
                        if (isset($return['error'])) {
                            $result[$return['error']]++;
                            continue;
                        }
                        if ($payment->condicion_pago === $return['status'] && $payment->valor === $return['value']) {
                            continue;
                        }
                        $updates[$return['status'].'|'.($return['value'] ?? 'null')][] = $payment->id;
                        continue;
                    }
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
                    $matrix = $this->communeKey((string) $payment->comuna_matriz);
                    if ($matrix !== '') {
                        $matrixMatches = $matches->filter(fn (CostCenterKey $key): bool =>
                            $this->communeKey((string) $key->agent_name) === $matrix
                        );
                        if ($matrixMatches->isNotEmpty()) {
                            $matches = $matrixMatches;
                        }
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

    private function syncFinalWeights(int $tenantId, string $period, array &$result): void
    {
        $weight = 'CASE WHEN peso_real > 0 THEN peso_real WHEN peso_transformado > 0 THEN peso_transformado ELSE 1 END';
        $paymentWeight = '(SELECT peso_final FROM movimientos_courier WHERE movimientos_courier.id = Pago_Movimientos_Courier.courier_movement_id AND movimientos_courier.tenant_id = Pago_Movimientos_Courier.tenant_id)';
        $payments = fn () => DB::table('Pago_Movimientos_Courier')
            ->where('Pago_Movimientos_Courier.tenant_id', $tenantId)->where('Pago_Movimientos_Courier.periodo', $period);

        DB::transaction(function () use ($tenantId, $weight, $paymentWeight, $payments, &$result): void {
            $result['weight_defaulted'] = $payments()
                ->join('movimientos_courier as movement', function ($join): void {
                    $join->on('movement.id', '=', 'Pago_Movimientos_Courier.courier_movement_id')
                        ->on('movement.tenant_id', '=', 'Pago_Movimientos_Courier.tenant_id');
                })
                ->where('Pago_Movimientos_Courier.nombre_proceso', 'Lanas')
                ->whereRaw('COALESCE(Pago_Movimientos_Courier.peso_final, 0) <= 0')
                ->whereRaw('COALESCE(movement.peso_real, 0) <= 0 AND COALESCE(movement.peso_transformado, 0) <= 0')
                ->count();

            DB::table('movimientos_courier')->where('tenant_id', $tenantId)
                ->whereIn('id', $payments()->select('courier_movement_id'))
                ->whereRaw("COALESCE(peso_final, -1) <> $weight")
                ->update(['peso_final' => DB::raw($weight), 'updated_at' => now()]);

            $result['weights_recalculated'] = $payments()
                ->whereRaw("COALESCE(peso_final, -1) <> $paymentWeight")
                ->update(['peso_final' => DB::raw($paymentWeight), 'updated_at' => now()]);
        });
    }

    private function identity(?string $providerRut, ?string $clientRut, string $serviceCode): string
    {
        return strtoupper(trim((string) $providerRut)).'|'.strtoupper(trim((string) $clientRut)).'|'.$serviceCode;
    }

    private function returnPayment(CourierPaymentMovement $payment, Collection $coverages): array
    {
        $matches = $coverages->get($this->communeKey((string) $payment->comuna_destino), collect());
        $date = $payment->fecha?->toDateString();
        $matches = $matches->filter(fn (Coverage $coverage): bool =>
            ($coverage->effective_from === null || ($date !== null && $coverage->effective_from->toDateString() <= $date))
            && ($coverage->effective_to === null || ($date !== null && $coverage->effective_to->toDateString() >= $date))
        );
        if ($matches->isEmpty()) {
            return ['error' => 'missing_coverage'];
        }
        $providerRut = strtoupper(trim((string) $payment->rut_proveedor));
        $providerMatches = $matches->filter(fn (Coverage $coverage): bool => strtoupper(trim((string) (
            $coverage->provider?->tax_id ?: $coverage->provider_tax_id
        ))) === $providerRut);
        if ($providerMatches->isNotEmpty()) {
            $matches = $providerMatches;
        }
        $configurations = $matches->unique(fn (Coverage $coverage): string => $coverage->return_payment_applies
            ? 'SI|'.$coverage->return_value : 'NO');
        if ($configurations->count() !== 1) {
            return ['error' => 'ambiguous_coverage'];
        }
        $coverage = $configurations->first();
        if (! $coverage->return_payment_applies) {
            return ['status' => 'NO', 'value' => null];
        }
        $value = $coverage->return_value;
        if ($value === null || (float) $value < 0 || floor((float) $value) !== (float) $value) {
            return ['error' => 'missing_return_value'];
        }

        return ['status' => 'SI', 'value' => (int) $value];
    }

    private function communeKey(string $commune): string
    {
        return Str::of($commune)->squish()->lower()->ascii()->toString();
    }
}
