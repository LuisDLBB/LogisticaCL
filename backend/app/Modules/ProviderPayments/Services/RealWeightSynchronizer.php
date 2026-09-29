<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\CourierMovement;
use App\Models\RealWeight;
use Illuminate\Support\Facades\DB;

class RealWeightSynchronizer
{
    /** @return array{updated: int, without_match: int, protected: int} */
    public function syncOpen(int $tenantId): array
    {
        $result = ['updated' => 0, 'without_match' => 0, 'protected' => 0];
        $closedPeriods = DB::table('Cierres_Pagos')->where('tenant_id', $tenantId)->pluck('periodo')->flip();
        CourierMovement::query()->where('tenant_id', $tenantId)
            ->select(['id', 'tenant_id', 'tracking_number', 'weight_kg', 'peso_real', 'peso_transformado', 'peso_final', 'merchant_name', 'service_name', 'nombre_proceso'])
            ->chunkById(1000, function ($movements) use ($tenantId, $closedPeriods, &$result): void {
                $trackings = $movements->pluck('tracking_number');
                $realWeights = RealWeight::query()->where('tenant_id', $tenantId)
                    ->whereIn('seguimiento_paquete', $trackings)
                    ->get(['seguimiento_paquete', 'peso_real', 'comerciante', 'servicio'])->keyBy('seguimiento_paquete');
                $paid = DB::table('Maestro_Pagos')->where('tenant_id', $tenantId)
                    ->whereIn('seguimiento_paquete', $trackings)
                    ->pluck('seguimiento_paquete')->flip();
                $updates = [];
                $weightOnlyUpdates = [];
                $identityGroups = [];
                foreach ($movements as $movement) {
                    $period = substr((string) $movement->nombre_proceso, 0, 6);
                    if ($paid->has(strtoupper(trim((string) $movement->tracking_number))) || $closedPeriods->has($period)) {
                        $result['protected']++;

                        continue;
                    }
                    $realWeight = $realWeights->get($movement->tracking_number);
                    if ($realWeight === null) {
                        $result['without_match']++;
                        $finalWeight = CourierMovement::pesoFinal($movement->peso_real, $movement->peso_transformado);
                        if ($movement->peso_final !== $finalWeight) {
                            $weightOnlyUpdates[$finalWeight][] = $movement->id;
                        }

                        continue;
                    }
                    if (($movement->merchant_name && $realWeight->comerciante !== $movement->merchant_name)
                        || ($movement->service_name && $realWeight->servicio !== $movement->service_name)) {
                        $merchant = $movement->merchant_name ?: $realWeight->comerciante;
                        $service = $movement->service_name ?: $realWeight->servicio;
                        $key = serialize([$merchant, $service]);
                        $identityGroups[$key]['merchant'] = $merchant;
                        $identityGroups[$key]['service'] = $service;
                        $identityGroups[$key]['trackings'][] = $movement->tracking_number;
                    }
                    $weight = $realWeight->peso_real === null ? null : (int) $realWeight->peso_real;
                    $finalWeight = CourierMovement::pesoFinal($weight, $movement->peso_transformado);
                    if ($movement->peso_real !== $weight || $movement->peso_final !== $finalWeight) {
                        $updates[] = [
                            'id' => $movement->id,
                            'tenant_id' => $movement->tenant_id,
                            'tracking_number' => $movement->tracking_number,
                            'weight_kg' => $movement->weight_kg,
                            'peso_real' => $weight,
                            'peso_final' => $finalWeight,
                            'updated_at' => now(),
                        ];
                    }
                    $result['updated']++;
                }
                foreach ($identityGroups as $group) {
                    DB::table('peso_real')->where('tenant_id', $tenantId)
                        ->whereIn('seguimiento_paquete', $group['trackings'])
                        ->update(['comerciante' => $group['merchant'], 'servicio' => $group['service'], 'updated_at' => now()]);
                }
                if ($updates !== []) {
                    DB::table('movimientos_courier')->upsert($updates, ['id'], ['peso_real', 'peso_final', 'updated_at']);
                }
                foreach ($weightOnlyUpdates as $weight => $ids) {
                    DB::table('movimientos_courier')->where('tenant_id', $tenantId)->whereIn('id', $ids)
                        ->update(['peso_final' => $weight, 'updated_at' => now()]);
                }
            });

        return $result;
    }
}
