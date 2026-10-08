<?php

namespace App\Modules\Operations\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OperationReservationService
{
    public function pendingBatches(int $tenant): Collection
    {
        return DB::table('Ope_Reservas as reservation')
            ->join('Ope_Lotes as source', 'source.id', '=', 'reservation.source_lot_id')
            ->join('Ope_Bultos as package', 'package.id', '=', 'reservation.source_package_id')
            ->where('reservation.tenant_id', $tenant)
            ->where('reservation.status', 'pending')
            ->groupBy('reservation.batch_id', 'reservation.source_lot_id', 'source.name', 'source.operation_date', 'reservation.route_label', 'reservation.role')
            ->orderBy('source.operation_date')
            ->orderBy('reservation.source_lot_id')
            ->get([
                'reservation.batch_id', 'reservation.source_lot_id', 'source.name as source_name',
                'source.operation_date as source_date', 'reservation.route_label', 'reservation.role',
                DB::raw('COUNT(*) as package_count'), DB::raw('SUM(package.weight) as weight'),
                DB::raw('MAX(CASE WHEN reservation.warehouse_confirmed_at IS NOT NULL THEN 1 ELSE 0 END) as returned_after_departure'),
            ]);
    }

    public function includedBatches(int $tenant, int $lotId): Collection
    {
        return DB::table('Ope_Reservas as reservation')
            ->join('Ope_Lotes as source', 'source.id', '=', 'reservation.source_lot_id')
            ->join('Ope_Bultos as package', 'package.id', '=', 'reservation.included_package_id')
            ->where('reservation.tenant_id', $tenant)
            ->where('reservation.included_lot_id', $lotId)
            ->where('reservation.status', 'included')
            ->groupBy('reservation.batch_id', 'reservation.source_lot_id', 'source.name', 'reservation.route_label')
            ->get([
                'reservation.batch_id', 'reservation.source_lot_id', 'source.name as source_name',
                'reservation.route_label', DB::raw('COUNT(*) as package_count'),
                DB::raw('SUM(package.weight) as weight'),
                DB::raw('SUM(CASE WHEN EXISTS (SELECT 1 FROM Ope_BultoTramos AS leg WHERE leg.package_id = reservation.included_package_id) THEN 1 ELSE 0 END) as scheduled_count'),
            ]);
    }

    /** @param  array<int, int>  $configurationIds */
    public function reserve(int $tenant, int $user, int $lotId, array $configurationIds, bool $warehouseReturned = false): int
    {
        return DB::transaction(function () use ($tenant, $user, $lotId, $configurationIds, $warehouseReturned): int {
            $lot = DB::table('Ope_Lotes')->where(['tenant_id' => $tenant, 'id' => $lotId])->lockForUpdate()->firstOrFail();
            if (DB::table('Ope_Incidencias')->where('lot_id', $lot->id)->whereNull('resolved_at')->exists()) {
                throw ValidationException::withMessages(['configuration_ids' => 'Resuelve las incidencias antes de guardar una reserva.']);
            }
            $configurations = DB::table('Ope_GuiaConfiguraciones as configuration')
                ->join('PPR_coverages as coverage', 'coverage.id', '=', 'configuration.coverage_id')
                ->where('configuration.tenant_id', $tenant)
                ->where('coverage.tenant_id', $tenant)
                ->where('configuration.is_active', true)
                ->whereIn('configuration.id', $configurationIds)
                ->orderBy('configuration.sequence')->orderBy('configuration.id')
                ->get(['configuration.*', 'coverage.ID_ComunaMatrizAgencia as agency_code']);
            if ($configurations->count() !== count(array_unique($configurationIds))) {
                throw ValidationException::withMessages(['configuration_ids' => 'Selecciona rutas vigentes de esta empresa.']);
            }

            $reserved = [];
            foreach ($configurations as $configuration) {
                $coverageIds = $this->groupCoverageIds($tenant, $lotId, $configuration);
                $packages = DB::table('Ope_Bultos')
                    ->where('lot_id', $lotId)->where('excluded', false)
                    ->whereIn('coverage_id', $coverageIds)->lockForUpdate()->get(['id']);
                foreach ($packages as $package) {
                    $reserved[$package->id] ??= $configuration;
                }
            }
            if ($reserved === []) {
                throw ValidationException::withMessages(['configuration_ids' => 'Las rutas seleccionadas no tienen bultos disponibles para reservar.']);
            }
            $packageIds = array_keys($reserved);
            $existingLegs = DB::table('Ope_BultoTramos as leg')
                ->join('Ope_GuiaConfiguraciones as configuration', 'configuration.id', '=', 'leg.configuration_id')
                ->join('Ope_ProgramacionSalidas as departure', 'departure.id', '=', 'leg.departure_id')
                ->whereIn('leg.package_id', $packageIds)
                ->get(['leg.package_id', 'configuration.sequence', 'departure.status'])
                ->groupBy('package_id');
            $hasApprovedEarlierLeg = false;
            foreach ($reserved as $packageId => $configuration) {
                foreach ($existingLegs->get($packageId, collect()) as $leg) {
                    if ($leg->status !== 'approved' || $leg->sequence >= $configuration->sequence) {
                        throw ValidationException::withMessages(['configuration_ids' => 'Una de estas cargas ya tiene una salida para este tramo o una posterior. Cancela primero las salidas pendientes; las guías aprobadas permanecen como registro histórico.']);
                    }
                    $hasApprovedEarlierLeg = true;
                }
            }
            if ($hasApprovedEarlierLeg && ! $warehouseReturned) {
                throw ValidationException::withMessages(['warehouse_returned' => 'Confirma que todos los bultos de esta selección regresaron físicamente a la bodega de origen.']);
            }
            if (DB::table('Ope_Reservas')->whereIn('source_package_id', $packageIds)->exists()) {
                throw ValidationException::withMessages(['configuration_ids' => 'Una de estas cargas ya fue reservada. Actualiza la página.']);
            }

            $batches = [];
            $locations = DB::table('Ope_Ubicaciones')->whereIn('id', $configurations->pluck('destination_id'))->pluck('name', 'id');
            foreach ($reserved as $packageId => $configuration) {
                $batches[$configuration->id] ??= (string) Str::uuid();
                $routeLabel = $configuration->role.' · '.($locations[$configuration->destination_id] ?? $configuration->name);
                DB::table('Ope_Reservas')->insert([
                    'tenant_id' => $tenant, 'source_lot_id' => $lotId, 'source_package_id' => $packageId,
                    'batch_id' => $batches[$configuration->id], 'route_label' => mb_substr($routeLabel, 0, 200),
                    'role' => $configuration->role, 'status' => 'pending', 'reserved_by' => $user,
                    'warehouse_confirmed_at' => $hasApprovedEarlierLeg ? now() : null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('Ope_Bultos')->whereIn('id', $packageIds)->update(['excluded' => true, 'updated_at' => now()]);
            OperationAccess::audit($tenant, $user, 'Guardar reserva', 'lote', $lotId, [
                'packages' => $packageIds, 'batches' => array_values($batches),
                'returned_to_warehouse' => $hasApprovedEarlierLeg,
            ]);

            return count($packageIds);
        });
    }

    /** @param  array<int, string>  $batchIds */
    public function include(int $tenant, int $user, int $targetLotId, array $batchIds): int
    {
        return DB::transaction(function () use ($tenant, $user, $targetLotId, $batchIds): int {
            $target = DB::table('Ope_Lotes')->where(['tenant_id' => $tenant, 'id' => $targetLotId])->lockForUpdate()->firstOrFail();
            if (DB::table('Ope_ProgramacionSalidas')->where('lot_id', $targetLotId)->where('status', '<>', 'cancelled')->exists()) {
                throw ValidationException::withMessages(['batch_ids' => 'Incluye las reservas antes de programar las salidas de este proceso.']);
            }
            $reservations = DB::table('Ope_Reservas')->where('tenant_id', $tenant)
                ->where('status', 'pending')->whereIn('batch_id', $batchIds)
                ->lockForUpdate()->orderBy('id')->get();
            if ($reservations->isEmpty() || $reservations->pluck('batch_id')->unique()->count() !== count(array_unique($batchIds))) {
                throw ValidationException::withMessages(['batch_ids' => 'Selecciona reservas pendientes. Actualiza la página si ya fueron incluidas.']);
            }
            $sources = DB::table('Ope_Lotes')->whereIn('id', $reservations->pluck('source_lot_id'))
                ->where('tenant_id', $tenant)->get()->keyBy('id');
            if ($reservations->contains(fn (object $row): bool => $row->source_lot_id === $targetLotId
                || ($sources[$row->source_lot_id]->operation_date ?? '9999-12-31') > $target->operation_date)) {
                throw ValidationException::withMessages(['batch_ids' => 'Incluye la reserva en otro proceso de la misma fecha o una fecha posterior.']);
            }
            $packages = DB::table('Ope_Bultos')->whereIn('id', $reservations->pluck('source_package_id'))->get()->keyBy('id');
            $duplicate = DB::table('Ope_Bultos')->where('lot_id', $targetLotId)
                ->whereIn('tracking', $packages->pluck('tracking'))->value('tracking');
            if ($duplicate) {
                throw ValidationException::withMessages(['batch_ids' => 'El código '.$duplicate.' ya está en el proceso de destino. No se duplicó el bulto.']);
            }
            $coverageIds = $packages->pluck('coverage_id')->filter()->unique();
            $coverages = DB::table('PPR_coverages')->where('tenant_id', $tenant)->where('is_active', true)
                ->whereIn('id', $coverageIds)->get()->keyBy('id');
            if ($coverageIds->count() !== $coverages->count()) {
                throw ValidationException::withMessages(['batch_ids' => 'Una cobertura reservada ya no está vigente. Actualiza la cobertura antes de incluirla.']);
            }

            foreach ($reservations as $reservation) {
                $package = $packages[$reservation->source_package_id];
                $snapshot = json_decode($package->snapshot, true) ?: [];
                $snapshot['reservation'] = [
                    'source_lot_id' => $reservation->source_lot_id,
                    'source_package_id' => $package->id,
                    'reserved_at' => $reservation->created_at,
                    'warehouse_confirmed_at' => $reservation->warehouse_confirmed_at,
                ];
                $includedPackageId = DB::table('Ope_Bultos')->insertGetId([
                    'lot_id' => $targetLotId, 'tracking' => $package->tracking, 'reading_id' => $package->reading_id,
                    'weight' => $package->weight, 'operator' => $package->operator,
                    'customer_guide' => $package->customer_guide, 'reference' => $package->reference,
                    'merchant' => $package->merchant, 'service' => $package->service,
                    'commune' => $package->commune, 'coverage_id' => $package->coverage_id,
                    'snapshot' => OperationAccess::json($snapshot), 'excluded' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('Ope_Reservas')->where('id', $reservation->id)->update([
                    'status' => 'included', 'included_lot_id' => $targetLotId,
                    'included_package_id' => $includedPackageId, 'included_by' => $user,
                    'included_at' => now(), 'updated_at' => now(),
                ]);
            }
            OperationAccess::audit($tenant, $user, 'Incluir reservas', 'lote', $targetLotId, [
                'batches' => array_values($batchIds), 'packages' => $reservations->pluck('source_package_id')->all(),
            ]);

            return $reservations->count();
        });
    }

    public function cancelPending(int $tenant, int $user, int $sourceLotId, string $batchId): int
    {
        return DB::transaction(function () use ($tenant, $user, $sourceLotId, $batchId): int {
            DB::table('Ope_Lotes')->where(['tenant_id' => $tenant, 'id' => $sourceLotId])->lockForUpdate()->firstOrFail();
            $reservations = DB::table('Ope_Reservas')->where([
                'tenant_id' => $tenant, 'source_lot_id' => $sourceLotId,
                'batch_id' => $batchId, 'status' => 'pending',
            ])->lockForUpdate()->get();
            if ($reservations->isEmpty()) {
                throw ValidationException::withMessages(['batch' => 'La reserva ya no está pendiente. Actualiza la página.']);
            }
            if ($reservations->contains(fn (object $row): bool => $row->warehouse_confirmed_at !== null)) {
                throw ValidationException::withMessages(['batch' => 'La carga regresó a bodega después de una salida aprobada. Inclúyela en un proceso nuevo para iniciar de nuevo desde bodega.']);
            }
            $packageIds = $reservations->pluck('source_package_id');
            DB::table('Ope_Bultos')->whereIn('id', $packageIds)->update(['excluded' => false, 'updated_at' => now()]);
            DB::table('Ope_Reservas')->whereIn('id', $reservations->pluck('id'))->delete();
            OperationAccess::audit($tenant, $user, 'Cancelar reserva', 'lote', $sourceLotId, [
                'batch' => $batchId, 'packages' => $packageIds->all(),
            ]);

            return $reservations->count();
        });
    }

    public function returnToPending(int $tenant, int $user, int $targetLotId, string $batchId): int
    {
        return DB::transaction(function () use ($tenant, $user, $targetLotId, $batchId): int {
            DB::table('Ope_Lotes')->where(['tenant_id' => $tenant, 'id' => $targetLotId])->lockForUpdate()->firstOrFail();
            $reservations = DB::table('Ope_Reservas')->where([
                'tenant_id' => $tenant, 'included_lot_id' => $targetLotId,
                'batch_id' => $batchId, 'status' => 'included',
            ])->lockForUpdate()->get();
            if ($reservations->isEmpty()) {
                throw ValidationException::withMessages(['batch' => 'Esta reserva ya no está incluida en el proceso.']);
            }
            $packageIds = $reservations->pluck('included_package_id');
            if (DB::table('Ope_BultoTramos')->whereIn('package_id', $packageIds)->exists()) {
                throw ValidationException::withMessages(['batch' => 'Cancela primero las salidas programadas de estos bultos antes de devolverlos a reservas.']);
            }
            if (DB::table('Ope_Reservas')->whereIn('source_package_id', $packageIds)->exists()) {
                throw ValidationException::withMessages(['batch' => 'Esta carga ya fue reservada nuevamente. Resuelve primero la reserva posterior.']);
            }
            DB::table('Ope_Reservas')->whereIn('id', $reservations->pluck('id'))->update([
                'status' => 'pending', 'included_lot_id' => null, 'included_package_id' => null,
                'included_by' => null, 'included_at' => null, 'updated_at' => now(),
            ]);
            DB::table('Ope_Bultos')->whereIn('id', $packageIds)->delete();
            OperationAccess::audit($tenant, $user, 'Devolver a reservas', 'lote', $targetLotId, [
                'batch' => $batchId, 'packages' => $packageIds->all(),
            ]);

            return $reservations->count();
        });
    }

    private function groupCoverageIds(int $tenant, int $lotId, object $selected): Collection
    {
        $query = DB::table('Ope_GuiaConfiguraciones as configuration')
            ->join('PPR_coverages as coverage', 'coverage.id', '=', 'configuration.coverage_id')
            ->join('Ope_Bultos as package', 'package.coverage_id', '=', 'coverage.id')
            ->where('configuration.tenant_id', $tenant)
            ->where('coverage.tenant_id', $tenant)
            ->where('configuration.is_active', true)
            ->where('configuration.role', $selected->role)
            ->where('package.lot_id', $lotId)->where('package.excluded', false);
        if ($selected->group_code === null) {
            $query->where('coverage.ID_ComunaMatrizAgencia', $selected->agency_code);
        } else {
            $query->where('configuration.group_code', $selected->group_code)
                ->where('configuration.sequence', $selected->sequence)
                ->where('configuration.origin_id', $selected->origin_id)
                ->where('configuration.destination_id', $selected->destination_id);
        }
        $candidates = $query->distinct()->get(['configuration.coverage_id', 'configuration.transport_kind', 'configuration.transport_id']);
        if ($selected->group_code === null) {
            return $candidates->pluck('coverage_id');
        }
        $signature = $this->transportSignature($tenant, $selected);

        return $candidates->filter(fn (object $candidate): bool => $this->transportSignature($tenant, $candidate) === $signature)
            ->pluck('coverage_id');
    }

    private function transportSignature(int $tenant, object $configuration): string
    {
        $table = $configuration->transport_kind === 'trunk' ? 'Ope_Troncales' : 'Ope_Postas';
        $transport = DB::table($table)->where(['tenant_id' => $tenant, 'id' => $configuration->transport_id])->first();
        $driver = $transport?->driver_id ? DB::table('Ope_Choferes')->where(['tenant_id' => $tenant, 'id' => $transport->driver_id])->first() : null;

        return OperationAccess::json([$transport?->plate, $driver?->rut, $driver?->name]);
    }
}
