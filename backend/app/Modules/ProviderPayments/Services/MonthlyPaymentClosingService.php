<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\MaestroPago;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonthlyPaymentClosingService
{
    public function __construct(
        private readonly PurchaseOrderAssigner $purchaseOrders,
        private readonly MaestroPagoTaxCalculator $taxCalculator,
        private readonly PeumoPaymentAssigner $peumo,
    ) {}

    public static function assertOpen(int $tenantId, string $period): void
    {
        if (DB::table('Cierres_Pagos')->where('tenant_id', $tenantId)->where('periodo', $period)->exists()) {
            throw ValidationException::withMessages(['period' => "El período {$period} tiene un cierre definitivo y no admite cambios."]);
        }
    }

    /** @return array{registros: int, total: int} */
    public function close(int $tenantId, string $period): array
    {
        return DB::transaction(function () use ($tenantId, $period): array {
            self::assertOpen($tenantId, $period);

            DB::table('Pago_Movimientos_Courier')->where('tenant_id', $tenantId)->where('periodo', $period)
                ->whereIn('seguimiento_paquete', DB::table('envios_externos')->where('tenant_id', $tenantId)
                    ->where('exclude_provider_payment', true)->select('tracking_number'))
                ->where(fn ($query) => $query->whereNull('condicion_pago')->orWhere('condicion_pago', '<>', 'NO')
                    ->orWhereNull('valor')->orWhere('valor', '<>', 0))
                ->update(['condicion_pago' => 'NO', 'valor' => 0, 'updated_at' => now()]);

            $this->peumo->assign($tenantId, $period);
            $sourcePeumo = DB::table('movimientos_courier')->where('tenant_id', $tenantId)
                ->where('nombre_proceso', $period.'-Peumo');
            $workedMovementIds = DB::table('Pago_Movimientos_Courier')->where('tenant_id', $tenantId)
                ->where('periodo', $period)->select('courier_movement_id');
            $uncompiledPeumo = (clone $sourcePeumo)->whereNotIn('id', $workedMovementIds)->exists();
            $unresolvedPeumo = DB::table('Pago_Movimientos_Courier')->where('tenant_id', $tenantId)
                ->where('periodo', $period)
                ->where(fn ($query) => $query->where('nombre_proceso', 'Peumo')
                    ->orWhereIn('courier_movement_id', (clone $sourcePeumo)->select('id')))
                ->whereNull('condicion_pago')->exists();
            if ($uncompiledPeumo || $unresolvedPeumo) {
                throw ValidationException::withMessages(['period' => 'Peumo tiene movimientos sin compilar o con condición de pago pendiente. Revísalos antes del cierre definitivo.']);
            }

            $payments = DB::table('Pago_Movimientos_Courier')
                ->where('tenant_id', $tenantId)->where('periodo', $period);
            if ((clone $payments)->count() === 0) {
                throw ValidationException::withMessages(['period' => "El período {$period} no tiene pagos trabajados para cerrar."]);
            }
            if (! (clone $payments)->whereRaw('UPPER(TRIM(condicion_pago)) = ?', ['SI'])->exists()) {
                throw ValidationException::withMessages(['period' => "El período {$period} no tiene pagos con condición SI para guardar en Maestro_Pagos."]);
            }

            $purchaseOrders = $this->purchaseOrders->forPeriod($tenantId, $period);
            $closedAt = now()->toDateTimeString();
            $count = 0;
            $total = 0;
            $seen = [];
            $taxBases = [];
            $taxAmounts = [];
            (clone $payments)->whereRaw('UPPER(TRIM(condicion_pago)) = ?', ['SI'])
                ->orderBy('id')->chunkById(500, function ($rows) use ($period, $closedAt, $purchaseOrders, &$count, &$total, &$seen, &$taxBases, &$taxAmounts): void {
                    $snapshots = [];
                    foreach ($rows as $payment) {
                        $tracking = MaestroPago::trackingKey((string) $payment->seguimiento_paquete);
                        if ($tracking === '') {
                            throw ValidationException::withMessages(['period' => "El pago {$payment->id} del período {$period} no tiene seguimiento de paquete."]);
                        }
                        if (isset($seen[$tracking])) {
                            throw ValidationException::withMessages(['period' => "El seguimiento {$tracking} está repetido entre los pagos del período {$period}."]);
                        }
                        $seen[$tracking] = true;
                        $snapshot = (array) $payment;
                        unset($snapshot['id']);
                        if (ProviderZone::isDsGroup((string) $payment->rut_proveedor)) {
                            $snapshot['zona'] = 'RM';
                        }
                        $snapshot['seguimiento_paquete'] = $tracking;
                        $snapshot['pago_movimiento_id'] = $payment->id;
                        $snapshot['oc'] = $purchaseOrders[$this->purchaseOrders->groupKey($payment)];
                        if ($payment->valor === null) {
                            throw ValidationException::withMessages(['period' => "El pago {$payment->id} no tiene monto para calcular impuestos."]);
                        }
                        $snapshot = array_merge($snapshot, $this->taxCalculator->allocateForOrder(
                            $payment->tipo_documento, (int) $payment->valor, (int) $payment->id,
                            $snapshot['oc'], $taxBases, $taxAmounts,
                        ));
                        $snapshot['closed_at'] = $closedAt;
                        $snapshots[] = $snapshot;
                        $total += (int) ($payment->valor ?? 0);
                    }

                    $conflict = DB::table('Maestro_Pagos')->whereIn('seguimiento_paquete', array_column($snapshots, 'seguimiento_paquete'))
                        ->value('seguimiento_paquete');
                    if ($conflict !== null) {
                        throw ValidationException::withMessages(['period' => "El seguimiento {$conflict} ya está en Maestro_Pagos. No se cerró el período."]);
                    }
                    DB::table('Maestro_Pagos')->insert($snapshots);
                    $count += count($snapshots);
                });

            DB::table('Cierres_Pagos')->insert([
                'tenant_id' => $tenantId,
                'periodo' => $period,
                'registros' => $count,
                'total' => $total,
                'closed_at' => $closedAt,
            ]);

            return ['registros' => $count, 'total' => $total];
        });
    }
}
