<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\CourierPaymentMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PeumoPaymentAssigner
{
    public function __construct(private PeumoRateResolver $rates) {}

    /** @return array{paid:int,pending:int,missing_guide:int,missing_rate:int,ambiguous_rate:int,missing_identity:int} */
    public function assign(int $tenantId, string $period): array
    {
        $result = ['paid' => 0, 'pending' => 0, 'missing_guide' => 0, 'missing_rate' => 0, 'ambiguous_rate' => 0, 'missing_identity' => 0];
        $payments = CourierPaymentMovement::query()->where('tenant_id', $tenantId)->where('periodo', $period)
            ->where('nombre_proceso', 'Peumo')->with('courierMovement:id,dispatch_guide')
            ->orderBy('id')->get();
        $groups = $payments->groupBy(function (CourierPaymentMovement $payment): string {
            $guide = $this->guideKey((string) $payment->courierMovement?->dispatch_guide);

            return $guide === '' ? 'missing:'.$payment->id : 'guide:'.$guide;
        });

        foreach ($groups as $group) {
            $guide = $this->guideKey((string) $group->first()->courierMovement?->dispatch_guide);
            if ($guide === '') {
                $this->pending($group, 'missing_guide', $result);

                continue;
            }

            $rates = $group->mapWithKeys(fn (CourierPaymentMovement $payment): array => [
                $payment->id => $this->rates->resolve((string) $payment->comuna_destino),
            ]);
            if ($rates->contains(fn (array $rate): bool => $rate['status'] !== 'ok')) {
                $reason = $rates->contains(fn (array $rate): bool => $rate['status'] === 'ambiguous')
                    ? 'ambiguous_rate' : 'missing_rate';
                $this->pending($group, $reason, $result);

                continue;
            }

            foreach ($group->values() as $index => $payment) {
                if ($payment->condicion_pago === 'NO') {
                    continue;
                }
                if (! filled($payment->rut_proveedor) || ! filled($payment->rut_cliente)) {
                    $this->pending(collect([$payment]), 'missing_identity', $result);

                    continue;
                }
                $rate = $rates->get($payment->id);
                $value = $index === 0 ? $rate['first'] : $rate['rest'];
                if ($payment->condicion_pago !== 'SI' || (int) $payment->valor !== $value) {
                    DB::table('Pago_Movimientos_Courier')->where('id', $payment->id)
                        ->update(['condicion_pago' => 'SI', 'valor' => $value, 'updated_at' => now()]);
                }
                $result['paid']++;
            }
        }

        return $result;
    }

    private function guideKey(string $guide): string
    {
        $key = Str::of($guide)->squish()->lower()->ascii()->toString();

        return preg_match('/^0+$/', $key) === 1
            || in_array($key, ['', 'n/a', 's/n', 'sin guia', 'no aplica'], true) ? '' : $key;
    }

    private function pending(iterable $payments, string $reason, array &$result): void
    {
        foreach ($payments as $payment) {
            if ($payment->condicion_pago === 'NO') {
                continue;
            }
            if ($payment->condicion_pago === 'SI' || $payment->valor !== null) {
                DB::table('Pago_Movimientos_Courier')->where('id', $payment->id)
                    ->update(['condicion_pago' => null, 'valor' => null, 'updated_at' => now()]);
            }
            $result['pending']++;
            $result[$reason]++;
        }
    }
}
