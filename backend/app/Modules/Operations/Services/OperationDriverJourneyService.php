<?php

namespace App\Modules\Operations\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationDriverJourneyService
{
    public function driver(int $tenant, int $user): object
    {
        return DB::table('Ope_Choferes')->where(['tenant_id' => $tenant, 'user_id' => $user, 'is_active' => true])->firstOrFail();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function assigned(int $tenant, object $driver, string $date): Collection
    {
        $rut = strtoupper(preg_replace('/[^0-9K]/i', '', $driver->rut));
        $guides = DB::table('Ope_ProgramacionSalidas as departure')
            ->join('Ope_Lotes as lot', 'lot.id', '=', 'departure.lot_id')
            ->join('Ope_Guias as guide', function ($join): void {
                $join->on('guide.departure_id', '=', 'departure.id')->on('guide.version', '=', 'departure.version');
            })
            ->where('lot.tenant_id', $tenant)
            ->where('departure.departure_date', $date)
            ->where('departure.status', 'approved')
            ->whereRaw("UPPER(REPLACE(REPLACE(departure.driver_rut, '.', ''), '-', '')) = ?", [$rut])
            ->orderBy('departure.id')
            ->get(['departure.id', 'departure.version', 'departure.plate', 'departure.name', 'departure.role', 'guide.id as guide_id', 'guide.snapshot']);
        if ($guides->isEmpty()) {
            return collect();
        }

        $claimed = DB::table('Ope_RecorridoParadas')->whereIn('departure_id', $guides->pluck('id'))
            ->get(['departure_id', 'guide_version'])->mapWithKeys(fn (object $stop): array => [$stop->departure_id.':'.$stop->guide_version => true]);
        $groups = [];
        foreach ($guides as $guide) {
            if ($claimed->has($guide->id.':'.$guide->version)) {
                continue;
            }
            $snapshot = json_decode($guide->snapshot, true);
            $configuration = $snapshot['agencies'][0]['configuration'] ?? [];
            $kind = $configuration['transport_kind'] ?? null;
            $transportId = (int) ($configuration['transport_id'] ?? 0);
            if (! in_array($kind, ['trunk', 'post'], true) || $transportId < 1) {
                continue;
            }
            $key = hash('sha256', OperationAccess::json([$date, $kind, $transportId, strtoupper((string) $guide->plate)]));
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'date' => $date,
                    'transport_kind' => $kind,
                    'transport_id' => $transportId,
                    'name' => $snapshot['agencies'][0]['transport']['name'] ?? $configuration['name'] ?? $guide->name,
                    'plate' => $guide->plate,
                    'stops' => [],
                ];
            }
            $destination = $snapshot['destination'] ?? [];
            $groups[$key]['stops'][] = [
                'departure_id' => $guide->id,
                'guide_id' => $guide->guide_id,
                'guide_version' => $guide->version,
                'sort_order' => (int) ($configuration['stop_order'] ?? 999),
                'name' => $destination['name'] ?? $guide->name,
                'address' => $destination['address'] ?? '',
                'commune' => $destination['commune'] ?? '',
                'snapshot' => $snapshot,
                'count' => (int) ($snapshot['count'] ?? 0),
            ];
        }
        foreach ($groups as &$group) {
            usort($group['stops'], fn (array $left, array $right): int => [$left['sort_order'], $left['departure_id']] <=> [$right['sort_order'], $right['departure_id']]);
        }
        unset($group);

        return collect(array_values($groups));
    }

    public function claim(int $tenant, object $driver, string $date, string $key): int
    {
        return DB::transaction(function () use ($tenant, $driver, $date, $key): int {
            $group = $this->assigned($tenant, $driver, $date)->firstWhere('key', $key);
            if (! $group || count($group['stops']) === 0) {
                throw ValidationException::withMessages(['route' => 'Esta ruta ya fue tomada o dejó de estar aprobada. Actualiza la pantalla.']);
            }
            $journey = DB::table('Ope_Recorridos')->insertGetId([
                'tenant_id' => $tenant, 'driver_id' => $driver->id,
                'departure_date' => $date, 'transport_kind' => $group['transport_kind'],
                'transport_id' => $group['transport_id'], 'name' => $group['name'],
                'plate' => $group['plate'], 'status' => 'assigned',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($group['stops'] as $index => $stop) {
                DB::table('Ope_RecorridoParadas')->insert([
                    'journey_id' => $journey, 'departure_id' => $stop['departure_id'],
                    'guide_id' => $stop['guide_id'], 'guide_version' => $stop['guide_version'],
                    'sequence' => $index + 1, 'name' => $stop['name'],
                    'address' => $stop['address'], 'commune' => $stop['commune'],
                    'guide_snapshot' => OperationAccess::json($stop['snapshot']),
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $journey;
        });
    }

    public function journey(int $tenant, int $driver, int $id): object
    {
        return DB::table('Ope_Recorridos')->where(['tenant_id' => $tenant, 'driver_id' => $driver, 'id' => $id])->firstOrFail();
    }

    public function stop(int $journey, int $id): object
    {
        return DB::table('Ope_RecorridoParadas')->where(['journey_id' => $journey, 'id' => $id])->firstOrFail();
    }

    /** @return array{collected: int, received: int, transferred: int, confirmed: int, warehouse: int, in_custody: int, available: int} */
    public function returnBalance(int $journey): array
    {
        $collected = (int) DB::table('Ope_RecorridoParadas')->where('journey_id', $journey)->sum('return_count');
        $received = (int) DB::table('Ope_RecorridoTraspasos')->where(['to_journey_id' => $journey, 'status' => 'received'])->sum('package_count');
        $transferred = (int) DB::table('Ope_RecorridoTraspasos')->where('from_journey_id', $journey)->sum('package_count');
        $confirmed = (int) DB::table('Ope_RecorridoTraspasos')->where(['from_journey_id' => $journey, 'status' => 'received'])->sum('package_count');
        $warehouse = (int) DB::table('Ope_RecorridoDevolucionesBodega')->where('journey_id', $journey)->sum('package_count');

        return compact('collected', 'received', 'transferred', 'confirmed', 'warehouse') + [
            'in_custody' => $collected + $received - $confirmed - $warehouse,
            'available' => $collected + $received - $transferred - $warehouse,
        ];
    }
}
