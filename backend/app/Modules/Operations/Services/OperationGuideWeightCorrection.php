<?php

namespace App\Modules\Operations\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationGuideWeightCorrection
{
    /**
     * @return array{updated: int, original_guide_ids: array<int, int>, new_guide_ids: array<int, int>}
     */
    public function correctLot(int $tenantId, int $userId, int $lotId): array
    {
        return DB::transaction(function () use ($tenantId, $userId, $lotId): array {
            DB::table('MBA_tenants')->where('id', $tenantId)->lockForUpdate()->firstOrFail();
            DB::table('Ope_Lotes')->where(['id' => $lotId, 'tenant_id' => $tenantId])->firstOrFail();

            $guides = DB::table('Ope_Guias as guide')
                ->join('Ope_ProgramacionSalidas as departure', 'departure.id', '=', 'guide.departure_id')
                ->where('departure.lot_id', $lotId)
                ->where('departure.status', 'approved')
                ->whereColumn('guide.version', 'departure.version')
                ->orderBy('guide.id')
                ->select('guide.*')
                ->lockForUpdate()
                ->get();
            $approvedCount = DB::table('Ope_ProgramacionSalidas')->where(['lot_id' => $lotId, 'status' => 'approved'])->count();
            if ($guides->count() !== $approvedCount) {
                throw ValidationException::withMessages(['guides' => 'Hay salidas aprobadas sin una guía vigente. No se corrigió ningún dato.']);
            }

            $packages = DB::table('Ope_Bultos')->where('lot_id', $lotId)->get(['tracking', 'weight'])->keyBy('tracking');
            $result = ['updated' => 0, 'original_guide_ids' => [], 'new_guide_ids' => []];
            foreach ($guides as $guide) {
                $snapshot = json_decode($guide->snapshot, true, flags: JSON_THROW_ON_ERROR);
                $totalUnits = 0;
                foreach ($snapshot['lines'] as &$line) {
                    $lineUnits = 0;
                    foreach ($line['packages'] as $tracking) {
                        $package = $packages->get($tracking);
                        if ($package === null || $package->weight === null) {
                            throw ValidationException::withMessages(['guides' => 'El bulto '.$tracking.' no tiene un peso guardado en Operaciones. No se corrigió ningún dato.']);
                        }
                        $lineUnits += (int) round((float) $package->weight * 1000);
                    }
                    $oldWeight = $line['weight'];
                    $line['weight'] = OperationWeightFormatter::canonical($lineUnits / 1000);
                    $line['description'] = OperationWeightFormatter::withWeight($line['description'], $oldWeight, OperationWeightFormatter::display($line['weight']));
                    $totalUnits += $lineUnits;
                }
                unset($line);

                foreach ($snapshot['packages'] as &$snapshotPackage) {
                    $package = $packages->get($snapshotPackage['tracking']);
                    if ($package === null || $package->weight === null) {
                        throw ValidationException::withMessages(['guides' => 'Un bulto de la guía no tiene un peso guardado en Operaciones. No se corrigió ningún dato.']);
                    }
                    $snapshotPackage['weight'] = OperationWeightFormatter::canonical($package->weight);
                }
                unset($snapshotPackage);

                $snapshot['weight'] = OperationWeightFormatter::canonical($totalUnits / 1000);
                $encoded = OperationAccess::json($snapshot);
                if ($encoded === $guide->snapshot) {
                    continue;
                }

                $newVersion = (int) $guide->version + 1;
                $newGuideId = DB::table('Ope_Guias')->insertGetId([
                    'departure_id' => $guide->departure_id,
                    'version' => $newVersion,
                    'snapshot' => $encoded,
                    'sha256' => hash('sha256', $encoded),
                    'approved_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('Ope_ProgramacionSalidas')->where('id', $guide->departure_id)->update([
                    'version' => $newVersion,
                    'approved_by' => $userId,
                    'approved_at' => now(),
                    'updated_at' => now(),
                ]);
                OperationAccess::audit($tenantId, $userId, 'Corregir pesos de guía interna', 'guia', $newGuideId, [
                    'previous_guide_id' => $guide->id,
                    'previous_version' => $guide->version,
                    'version' => $newVersion,
                    'sha256' => hash('sha256', $encoded),
                ]);
                $result['updated']++;
                $result['original_guide_ids'][] = (int) $guide->id;
                $result['new_guide_ids'][] = $newGuideId;
            }

            return $result;
        });
    }
}
