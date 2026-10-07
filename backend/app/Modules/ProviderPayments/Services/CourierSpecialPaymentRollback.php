<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\CourierSpecialPayment;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourierSpecialPaymentRollback
{
    /** @return array{restored: int, deleted_movements: int, deleted_payments: int} */
    public function rollback(int $tenantId, string $processName): array
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, substr($processName, 0, 6));

        return DB::transaction(function () use ($tenantId, $processName): array {
            $specials = CourierSpecialPayment::query()->where('tenant_id', $tenantId)
                ->where('periodo', $processName)->whereNotNull('finalized_at')
                ->orderBy('id')->lockForUpdate()->get();
            $payments = CourierPaymentMovement::query()->where('tenant_id', $tenantId)
                ->where('periodo', substr($processName, 0, 6))->where('nombre_proceso', 'Especiales')->lockForUpdate()->get();
            if ($payments->count() !== $specials->count()
                || $specials->pluck('finalized_tracking_number')->filter()->unique()->count() !== $specials->count()) {
                throw ValidationException::withMessages(['process_name' => 'Los pagos Especiales no coinciden con los registros finalizados. No se eliminó nada.']);
            }

            $paymentsByTracking = $payments->keyBy('seguimiento_paquete');
            $result = ['restored' => 0, 'deleted_movements' => 0, 'deleted_payments' => 0];
            foreach ($specials as $special) {
                $payment = $paymentsByTracking->get($special->finalized_tracking_number);
                if (! $payment || $payment->updated_at?->toDateTimeString() !== $special->finalized_at?->toDateTimeString()) {
                    throw ValidationException::withMessages(['process_name' => "El pago del registro {$special->id} cambió después de finalizar Especiales o ya no existe. No se eliminó nada."]);
                }
                $movement = CourierMovement::query()->where('tenant_id', $tenantId)
                    ->whereKey($payment->courier_movement_id)->lockForUpdate()->first();
                if (! $movement) {
                    throw ValidationException::withMessages(['process_name' => "No se encontró el movimiento del registro {$special->id}. No se eliminó nada."]);
                }

                $previous = $special->payment_before_finalization;
                if ($previous !== null) {
                    if (($previous['id'] ?? null) !== $payment->id
                        || ($previous['tenant_id'] ?? null) !== $tenantId
                        || ($previous['courier_movement_id'] ?? null) !== $movement->id
                        || ($previous['seguimiento_paquete'] ?? null) !== $payment->seguimiento_paquete
                        || $movement->nombre_proceso === $processName) {
                        throw ValidationException::withMessages(['process_name' => "La copia previa del registro {$special->id} no corresponde a su pago actual. No se eliminó nada."]);
                    }
                    $previous['zona'] = ProviderZone::resolve($previous['rut_proveedor'] ?? null, $previous['provider_id'] ?? null, $previous['zona'] ?? null);
                    $previous['nombre_proceso'] = CourierPaymentMovement::withoutPeriodPrefix($previous['nombre_proceso'] ?? null);
                    DB::table('PPR_Pago_Movimientos_Courier')->where('tenant_id', $tenantId)
                        ->where('id', $payment->id)
                        ->update(Arr::except($previous, ['id', 'tenant_id', 'courier_movement_id', 'created_at']));
                    $result['restored']++;
                } elseif ($movement->nombre_proceso === $processName && $movement->source_system === 'Especiales') {
                    $payment->delete();
                    $movement->delete();
                    $result['deleted_payments']++;
                    $result['deleted_movements']++;
                } else {
                    throw ValidationException::withMessages(['process_name' => "Falta la copia previa del registro {$special->id}. No se eliminó nada."]);
                }

                $special->update([
                    'finalized_tracking_number' => null,
                    'finalized_at' => null,
                    'payment_before_finalization' => null,
                ]);
            }

            if (CourierMovement::query()->where('tenant_id', $tenantId)->where('nombre_proceso', $processName)->exists()) {
                throw ValidationException::withMessages(['process_name' => 'Quedaron movimientos Especiales sin registro de origen. No se eliminó nada.']);
            }

            return $result;
        });
    }
}
