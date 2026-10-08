<?php

namespace App\Modules\Operations\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

class OperationWorkflow
{
    private array $masterWeightColumns = [];

    private array $masterLoadsByLot = [];

    public function createLot(int $tenant, int $user, array $input): int
    {
        return DB::transaction(function () use ($tenant, $user, $input): int {
            $master = DB::table('Ope_Cargas')->where(['tenant_id' => $tenant, 'id' => $input['master_load_id'], 'source_type' => 'master', 'status' => 'completed'])->firstOrFail();
            $loads = DB::table('Ope_Cargas')->where('tenant_id', $tenant)->where('source_type', 'reception')->where('status', 'completed')->whereIn('id', $input['reception_load_ids'])->get();
            if ($loads->count() !== count(array_unique($input['reception_load_ids']))) {
                throw ValidationException::withMessages(['reception_load_ids' => 'Las cargas de Recepción deben pertenecer a esta empresa.']);
            }
            $lot = DB::table('Ope_Lotes')->insertGetId(['tenant_id' => $tenant, 'user_id' => $user, 'master_load_id' => $master->id, 'name' => $input['name'], 'operation_date' => $input['operation_date'], 'created_at' => now(), 'updated_at' => now()]);
            foreach ($loads as $load) {
                DB::table('Ope_LoteFuentes')->insert(['lot_id' => $lot, 'load_id' => $load->id]);
            }
            $readings = DB::table('Ope_FilasFuente')->whereIn('load_id', $loads->pluck('id'))->orderBy('id')->get();
            $valid = $readings->filter(function ($row) use ($lot, $input): bool {
                $errors = json_decode($row->errors, true);
                $data = json_decode($row->data, true);
                if (isset($data['date']) && substr($data['date'], 0, 10) > $input['operation_date']) {
                    $errors[] = 'El escaneo es posterior a la fecha del proceso.';
                }
                if ($errors !== []) {
                    $this->issue($lot, null, 'invalid_source', 'Recepción: fila '.$row->line.' de carga '.$row->load_id.'. '.implode(' ', $errors), ['row_id' => $row->id]);

                    return false;
                }

                return true;
            });
            $masters = collect();
            foreach ($valid->pluck('tracking')->unique()->chunk(500) as $chunk) {
                $masters = $masters->concat(DB::table('Ope_FilasFuente')->where('load_id', $master->id)->whereIn('tracking', $chunk)->get());
            }
            $masters = $masters->groupBy('tracking');
            $coverages = DB::table('PPR_coverages')->where('tenant_id', $tenant)->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $input['operation_date']))
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $input['operation_date']))
                ->get();
            $writtenCoverages = $coverages->groupBy(fn ($coverage): string => (string) $coverage->commune_name);
            $exactCoverages = $coverages->groupBy(fn ($coverage): string => OperationAccess::literalKey($coverage->commune_name));
            $normalizedCoverages = $coverages->groupBy(fn ($coverage): string => OperationAccess::key($coverage->commune_name));
            foreach ($valid->groupBy('tracking') as $tracking => $rows) {
                $reading = $rows->sortByDesc(fn ($row): float => (float) json_decode($row->data, true)['weight'])->first();
                $data = json_decode($reading->data, true);
                $masterRows = $masters->get($tracking, collect());
                $masterData = $masterRows->count() === 1 && json_decode($masterRows->first()->errors, true) === [] ? json_decode($masterRows->first()->data, true) : [];
                if ($masterData !== [] && ! array_key_exists('geolize_weight', $masterData)) {
                    $masterData['geolize_weight'] = $this->legacyMasterWeight($master->id, $masterRows->first()->raw);
                }
                $operationsWeight = $data['weight'] ?? null;
                $geolizeWeight = $operationsWeight === null ? $this->wholeGeolizeWeight($masterData['geolize_weight'] ?? null) : null;
                $weight = $operationsWeight ?? $geolizeWeight;
                $commune = (string) ($masterData['commune'] ?? '');
                $coverageMatches = $writtenCoverages->get($commune, collect());
                if ($coverageMatches->isEmpty()) {
                    $coverageMatches = $exactCoverages->get(OperationAccess::literalKey($commune), collect());
                }
                if ($coverageMatches->isEmpty()) {
                    $coverageMatches = $normalizedCoverages->get(OperationAccess::key($commune), collect());
                }
                $coverage = $coverageMatches->count() === 1 ? $coverageMatches->first() : null;
                $package = DB::table('Ope_Bultos')->insertGetId(['lot_id' => $lot, 'tracking' => $tracking, 'reading_id' => $reading?->id, 'weight' => $weight, 'operator' => $data['operator'] ?? null, 'customer_guide' => $data['customer_guide'] ?? null, 'reference' => $data['reference'] ?? null, 'merchant' => $masterData['merchant'] ?? null, 'service' => $masterData['service'] ?? null, 'commune' => $masterData['commune'] ?? null, 'coverage_id' => $coverage?->id, 'snapshot' => OperationAccess::json(['master' => $masterData, 'coverage' => $coverage, 'weight_source' => $operationsWeight !== null ? 'operations' : ($geolizeWeight !== null ? 'geolize' : null)]), 'created_at' => now(), 'updated_at' => now()]);
                if ($masterRows->count() !== 1 || json_decode($masterRows->first()?->errors ?? '[]', true) !== []) {
                    $this->issue($lot, $package, 'master_missing', 'Paquete ausente, repetido o inválido en el Maestro seleccionado. Corrige las fuentes y prepara un nuevo proceso, o excluye justificadamente el bulto.', ['row_ids' => $masterRows->pluck('id')->all()]);
                }
                if (! $coverage) {
                    $this->issue($lot, $package, 'coverage_conflict', 'Cobertura ausente o ambigua para '.$tracking.'. Selecciona la cobertura vigente correcta.', ['commune' => $masterData['commune'] ?? null, 'candidate_ids' => $coverageMatches->pluck('id')->all()]);
                }
                if ($weight === null && $masterData !== []) {
                    $this->issue($lot, $package, 'weight_missing', 'No hay peso de Operaciones ni un peso entero válido en Geolize para '.$tracking.'. Corrige las fuentes o excluye justificadamente el bulto.', []);
                }
            }
            if ($valid->isEmpty()) {
                $this->issue($lot, null, 'empty_lot', 'El proceso no tiene lecturas válidas. Corrige el Excel y prepara un nuevo proceso.', []);
            }
            $this->prepareGuideRoutes($tenant, $lot);
            OperationAccess::audit($tenant, $user, 'Preparar proceso', 'lote', $lot, $input);

            return $lot;
        });
    }

    public function prepareGuideRoutes(int $tenant, int $lotId): void
    {
        DB::table('Ope_Lotes')->where(['tenant_id' => $tenant, 'id' => $lotId])->firstOrFail();
        $coverageIds = DB::table('Ope_Bultos as package')
            ->join('PPR_coverages as coverage', 'coverage.id', '=', 'package.coverage_id')
            ->where(['package.lot_id' => $lotId, 'package.excluded' => false, 'coverage.tenant_id' => $tenant])
            ->whereNotNull('coverage.ID_ComunaMatrizAgencia')
            ->distinct()
            ->get(['coverage.id', 'coverage.ID_ComunaMatrizAgencia']);
        if ($coverageIds->isEmpty()) {
            return;
        }

        $pendingCoverageIds = DB::table('Ope_BultoTramos as leg')
            ->join('Ope_Bultos as package', 'package.id', '=', 'leg.package_id')
            ->join('Ope_ProgramacionSalidas as departure', 'departure.id', '=', 'leg.departure_id')
            ->whereIn('package.coverage_id', $coverageIds->pluck('id'))
            ->where('departure.status', 'draft')
            ->distinct()->pluck('package.coverage_id')->flip();

        $agencies = DB::table('Ope_Agencias')->where(['tenant_id' => $tenant, 'is_active' => true])
            ->get()->keyBy('agency_code');
        $trunks = DB::table('Ope_Troncales')->where(['tenant_id' => $tenant, 'is_active' => true])
            ->whereIn('id', $agencies->pluck('trunk_id'))->get()->keyBy('id');
        $posts = DB::table('Ope_Postas')->where('tenant_id', $tenant)->get();
        $postsById = $posts->keyBy('id');
        $postsByCode = $posts->keyBy('post_code');
        $planner = app(OperationGuideRoutePlanner::class);
        $configurations = DB::table('Ope_GuiaConfiguraciones')->where('tenant_id', $tenant)
            ->whereIn('coverage_id', $coverageIds->pluck('id'))->get()
            ->keyBy(fn ($configuration): string => $configuration->coverage_id.'|'.$configuration->sequence);
        $locations = [];
        $locationId = function (string $name, string $address, string $commune) use ($tenant, &$locations): int {
            $key = $address.'|'.$commune;

            return $locations[$key] ??= $this->guideLocation($tenant, $name, $address, $commune);
        };

        foreach ($coverageIds as $coverage) {
            $agency = $agencies->get($coverage->ID_ComunaMatrizAgencia);
            $trunk = $agency ? $trunks->get($agency->trunk_id) : null;
            if (! $agency || ! $trunk) {
                continue;
            }
            if (blank($trunk->origin_address) || blank($trunk->destination_address)
                || OperationAccess::key($trunk->origin_address) === 'pendiente'
                || OperationAccess::key($trunk->destination_address) === 'pendiente') {
                continue;
            }

            $planned = $planner->legs($agency, $trunk, $postsById, $postsByCode, $agencies);
            if ($planned === [] || collect($planned)->contains(fn (array $leg): bool => blank($leg['origin']['address'])
                || blank($leg['destination']['address'])
                || OperationAccess::key($leg['origin']['address']) === 'pendiente'
                || OperationAccess::key($leg['destination']['address']) === 'pendiente')) {
                continue;
            }
            if ($pendingCoverageIds->has($coverage->id)) {
                $current = $configurations->filter(fn (object $configuration): bool => $configuration->coverage_id === $coverage->id && $configuration->is_active);
                $structureChanged = $current->count() !== count($planned)
                    || collect($planned)->contains(function (array $leg) use ($configurations, $coverage): bool {
                        $existing = $configurations->get($coverage->id.'|'.$leg['sequence']);

                        return ! $existing || $existing->role !== $leg['role']
                            || $existing->group_code !== $leg['groupCode']
                            || $existing->transport_kind !== $leg['transportKind']
                            || (int) $existing->transport_id !== (int) $leg['transportId'];
                    });
                if ($structureChanged) {
                    continue;
                }
            }
            $legs = collect($planned)->map(function (array $leg) use ($locationId): array {
                return [
                    'sequence' => $leg['sequence'], 'role' => $leg['role'],
                    'origin_id' => $locationId($leg['origin']['name'], $leg['origin']['address'], $leg['origin']['commune']),
                    'destination_id' => $locationId($leg['destination']['name'], $leg['destination']['address'], $leg['destination']['commune']),
                    'transport_kind' => $leg['transportKind'], 'transport_id' => $leg['transportId'],
                    'group_code' => $leg['groupCode'], 'stop_order' => $leg['stopOrder'],
                ];
            });
            foreach ($legs as $leg) {
                $existing = $configurations->get($coverage->id.'|'.$leg['sequence']);
                $data = [...$leg, 'tenant_id' => $tenant, 'coverage_id' => $coverage->id, 'name' => $agency->name, 'is_active' => true, 'updated_at' => now()];
                if ($existing) {
                    $changed = $existing->name !== $data['name'] || $existing->role !== $data['role']
                        || $existing->origin_id !== $leg['origin_id'] || $existing->destination_id !== $leg['destination_id']
                        || $existing->transport_kind !== $leg['transport_kind'] || (int) $existing->transport_id !== (int) $leg['transport_id']
                        || $existing->group_code !== $leg['group_code'] || (int) $existing->stop_order !== (int) $leg['stop_order']
                        || ! $existing->is_active;
                    if ($changed) {
                        $structural = $existing->role !== $data['role'] || $existing->group_code !== $data['group_code']
                            || $existing->transport_kind !== $data['transport_kind']
                            || (int) $existing->transport_id !== (int) $data['transport_id'];
                        $updated = [...$data, 'version' => $existing->version + 1];
                        DB::table('Ope_GuiaConfiguraciones')->where('id', $existing->id)->update($updated);
                        if (! $structural) {
                            $this->refreshConfigurationRoute($existing, $updated);
                        }
                    }
                } else {
                    DB::table('Ope_GuiaConfiguraciones')->insert([...$data, 'template' => '{cliente} / {servicio}: {bultos} bultos, {peso} kg', 'requires_customer_guide' => false, 'version' => 1, 'created_at' => now()]);
                }
            }
            DB::table('Ope_GuiaConfiguraciones')->where(['tenant_id' => $tenant, 'coverage_id' => $coverage->id])
                ->whereNotIn('sequence', $legs->pluck('sequence'))->update(['is_active' => false, 'updated_at' => now()]);
        }
    }

    private function guideLocation(int $tenant, string $name, string $address, string $commune): int
    {
        $id = DB::table('Ope_Ubicaciones')->where(['tenant_id' => $tenant, 'address' => $address, 'commune' => $commune, 'is_active' => true])->value('id');

        return $id ?: DB::table('Ope_Ubicaciones')->insertGetId([
            'tenant_id' => $tenant, 'name' => $name, 'address' => $address, 'commune' => $commune,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function refreshConfigurationRoute(object $existing, array $data): void
    {
        $updated = [...$data, 'version' => $existing->version + 1];
        DB::table('Ope_GuiaConfiguraciones')->where('id', $existing->id)->update($updated);

        $departures = DB::table('Ope_SalidaAgencias as agency')
            ->join('Ope_ProgramacionSalidas as departure', 'departure.id', '=', 'agency.departure_id')
            ->where('agency.configuration_id', $existing->id)
            ->where('departure.status', 'draft')
            ->whereNotIn('departure.id', DB::table('Ope_Guias')->select('departure_id'))
            ->get(['departure.id', 'agency.snapshot']);
        if ($departures->isEmpty()) {
            return;
        }

        $origin = DB::table('Ope_Ubicaciones')->where('id', $data['origin_id'])->firstOrFail();
        $destination = DB::table('Ope_Ubicaciones')->where('id', $data['destination_id'])->firstOrFail();
        foreach ($departures as $departure) {
            $snapshot = json_decode($departure->snapshot, true, 512, JSON_THROW_ON_ERROR);
            $snapshot['configuration'] = [...$snapshot['configuration'], ...$updated];
            $snapshot['origin'] = (array) $origin;
            $snapshot['destination'] = (array) $destination;
            DB::table('Ope_SalidaAgencias')->where(['departure_id' => $departure->id, 'configuration_id' => $existing->id])
                ->update(['snapshot' => OperationAccess::json($snapshot)]);
            DB::table('Ope_ProgramacionSalidas')->where('id', $departure->id)
                ->update(['origin_id' => $data['origin_id'], 'destination_id' => $data['destination_id'], 'updated_at' => now()]);
        }
    }

    public function resolve(int $tenant, int $user, int $lotId, int $issueId, array $input): void
    {
        DB::transaction(function () use ($tenant, $user, $lotId, $issueId, $input): void {
            $lot = DB::table('Ope_Lotes')->where(['tenant_id' => $tenant, 'id' => $lotId])->lockForUpdate()->firstOrFail();
            $this->editable($lotId);
            $this->resolveIssue($tenant, $user, $lot, $issueId, $input);
        });
    }

    public function resolveMany(int $tenant, int $user, int $lotId, array $resolutions): void
    {
        DB::transaction(function () use ($tenant, $user, $lotId, $resolutions): void {
            $lot = DB::table('Ope_Lotes')->where(['tenant_id' => $tenant, 'id' => $lotId])->lockForUpdate()->firstOrFail();
            $this->editable($lotId);
            foreach ($resolutions as $resolution) {
                $this->resolveIssue($tenant, $user, $lot, $resolution['issue_id'], $resolution);
            }
        });
    }

    private function resolveIssue(int $tenant, int $user, object $lot, int $issueId, array $input): void
    {
        $issue = DB::table('Ope_Incidencias')->where(['lot_id' => $lot->id, 'id' => $issueId])->whereNull('resolved_at')->firstOrFail();
        $context = json_decode($issue->context, true);
        $package = $issue->package_id ? DB::table('Ope_Bultos')->where('id', $issue->package_id)->firstOrFail() : null;
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($issue->code === 'reading_conflict' && $input['action'] === 'reading' && mb_strlen($reason) < 10) {
            $preferredIndex = null;
            $highestWeight = null;
            foreach ($context['readings'] ?? [] as $index => $reading) {
                if (! is_numeric($reading['weight'] ?? null) || ! isset($context['row_ids'][$index])) {
                    continue;
                }
                if ($highestWeight === null || (float) $reading['weight'] > $highestWeight) {
                    $highestWeight = (float) $reading['weight'];
                    $preferredIndex = $index;
                }
            }
            if ($preferredIndex !== null && (int) ($input['reading_id'] ?? 0) === (int) $context['row_ids'][$preferredIndex]) {
                $reason = 'Lectura duplicada: se conservó la lectura de mayor peso.';
            }
        }
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => 'Escribe un motivo de al menos 10 caracteres o conserva la lectura de mayor peso.']);
        }
        $input['reason'] = $reason;
        if ($input['action'] === 'exclude' && $issue->code !== 'empty_lot') {
            if ($package) {
                DB::table('Ope_Bultos')->where('id', $package->id)->update(['excluded' => true, 'updated_at' => now()]);
                DB::table('Ope_Incidencias')->where('package_id', $package->id)->whereNull('resolved_at')->update(['resolution' => $input['reason'], 'resolved_by' => $user, 'resolved_at' => now(), 'updated_at' => now()]);
            }
        } elseif ($issue->code === 'reading_conflict' && $input['action'] === 'reading') {
            if (! in_array((int) ($input['reading_id'] ?? 0), $context['row_ids'], true)) {
                throw ValidationException::withMessages(['reading_id' => 'Selecciona una de las lecturas originales del paquete.']);
            }
            $row = DB::table('Ope_FilasFuente')->where('id', $input['reading_id'])->firstOrFail();
            $data = json_decode($row->data, true);
            $snapshot = json_decode($package->snapshot, true);
            if (! array_key_exists('geolize_weight', $snapshot['master'] ?? [])) {
                $snapshot['master']['geolize_weight'] = $this->legacyPackageMasterWeight($package);
            }
            $geolizeWeight = $this->wholeGeolizeWeight($snapshot['master']['geolize_weight'] ?? null);
            $weight = $data['weight'] ?? $geolizeWeight;
            if ($weight === null) {
                throw ValidationException::withMessages(['reading_id' => 'La lectura seleccionada no tiene peso y Geolize tampoco ofrece un respaldo válido.']);
            }
            $snapshot['weight_source'] = ($data['weight'] ?? null) !== null ? 'operations' : 'geolize';
            DB::table('Ope_Bultos')->where('id', $package->id)->update(['reading_id' => $row->id, 'weight' => $weight, 'operator' => $data['operator'], 'customer_guide' => $data['customer_guide'], 'reference' => $data['reference'], 'snapshot' => OperationAccess::json($snapshot), 'updated_at' => now()]);
        } elseif ($issue->code === 'coverage_conflict' && $input['action'] === 'coverage') {
            $coverage = DB::table('PPR_coverages')->where(['id' => $input['coverage_id'] ?? 0, 'tenant_id' => $tenant, 'is_active' => true])->first();
            if (! $coverage || ($coverage->effective_from && $coverage->effective_from > $lot->operation_date) || ($coverage->effective_to && $coverage->effective_to < $lot->operation_date)) {
                throw ValidationException::withMessages(['coverage_id' => 'Selecciona una cobertura vigente de esta empresa.']);
            }
            $snapshot = json_decode($package->snapshot, true);
            $snapshot['coverage'] = $coverage;
            DB::table('Ope_Bultos')->where('id', $package->id)->update(['coverage_id' => $coverage->id, 'snapshot' => OperationAccess::json($snapshot), 'updated_at' => now()]);
        } else {
            throw ValidationException::withMessages(['action' => 'Esta incidencia requiere corregir las fuentes o excluir el registro con un motivo.']);
        }
        DB::table('Ope_Incidencias')->where('id', $issue->id)->update(['resolution' => $input['reason'], 'resolved_by' => $user, 'resolved_at' => now(), 'updated_at' => now()]);
        OperationAccess::audit($tenant, $user, 'Resolver incidencia', 'incidencia', $issue->id, $input, $package);
    }

    public function createDeparture(int $tenant, int $user, int $lotId, array $input): int
    {
        return DB::transaction(function () use ($tenant, $user, $lotId, $input): int {
            $lot = DB::table('Ope_Lotes')->where(['tenant_id' => $tenant, 'id' => $lotId])->lockForUpdate()->firstOrFail();
            $this->assertReviewed($lotId);
            if ($input['departure_date'] < $lot->operation_date) {
                throw ValidationException::withMessages(['departure_date' => 'La salida no puede ser anterior al proceso.']);
            }
            $configurations = DB::table('Ope_GuiaConfiguraciones')->where('tenant_id', $tenant)->where('is_active', true)->whereIn('id', $input['configuration_ids'])->get();
            if ($configurations->count() !== count(array_unique($input['configuration_ids']))) {
                throw ValidationException::withMessages(['configuration_ids' => 'Selecciona configuraciones activas de esta empresa.']);
            }
            $first = $configurations->first();
            if (! $first) {
                throw ValidationException::withMessages(['configuration_ids' => 'Selecciona al menos una ruta vigente.']);
            }
            if ($first->group_code !== null) {
                return $this->createGroupedDeparture($tenant, $user, $lotId, $input, $first, $configurations);
            }
            $agency = DB::table('PPR_coverages as coverage')
                ->join('Ope_Agencias as agency', function ($join): void {
                    $join->on('agency.agency_code', '=', 'coverage.ID_ComunaMatrizAgencia')
                        ->on('agency.tenant_id', '=', 'coverage.tenant_id');
                })
                ->where(['coverage.id' => $first->coverage_id, 'agency.tenant_id' => $tenant, 'agency.is_active' => true])
                ->select('agency.*')
                ->first();
            $transport = null;
            $driver = null;
            if ($agency) {
                if (($first->role === 'posta2' || ($first->role === 'posta1' && $agency->second_post_id === null))
                    && OperationAccess::key($agency->address) === 'pendiente') {
                    throw ValidationException::withMessages(['configuration_ids' => 'Falta la dirección real de entrega de esta agencia.']);
                }
                if ($configurations->contains(fn ($configuration): bool => $configuration->role !== $first->role)) {
                    throw ValidationException::withMessages(['configuration_ids' => 'Selecciona solo un tramo por guía.']);
                }
                $selectedAgencyCodes = DB::table('Ope_GuiaConfiguraciones as configuration')
                    ->join('PPR_coverages as coverage', 'coverage.id', '=', 'configuration.coverage_id')
                    ->whereIn('configuration.id', $configurations->pluck('id'))
                    ->pluck('coverage.ID_ComunaMatrizAgencia');
                if ($selectedAgencyCodes->contains(fn ($code): bool => (int) $code !== (int) $agency->agency_code)) {
                    throw ValidationException::withMessages(['configuration_ids' => 'Cada guía debe corresponder a una sola agencia.']);
                }
                $agencyCoverageIds = DB::table('Ope_Bultos as package')
                    ->join('PPR_coverages as coverage', 'coverage.id', '=', 'package.coverage_id')
                    ->where(['package.lot_id' => $lotId, 'package.excluded' => false, 'coverage.ID_ComunaMatrizAgencia' => $agency->agency_code])
                    ->distinct()
                    ->pluck('package.coverage_id');
                if (! $agencyCoverageIds->contains($first->coverage_id)) {
                    throw ValidationException::withMessages(['configuration_ids' => 'La agencia seleccionada no tiene bultos para esa cobertura en el proceso.']);
                }
                $configurations = DB::table('Ope_GuiaConfiguraciones')
                    ->where('tenant_id', $tenant)
                    ->where('role', $first->role)
                    ->where('is_active', true)
                    ->whereIn('coverage_id', $agencyCoverageIds)
                    ->get();
                if ($agencyCoverageIds->diff($configurations->pluck('coverage_id'))->isNotEmpty()) {
                    throw ValidationException::withMessages(['configuration_ids' => 'Configura este tramo para todas las coberturas de la agencia antes de programar su guía.']);
                }
                $table = $first->role === 'troncal' ? 'Ope_Troncales' : 'Ope_Postas';
                $transportId = match ($first->role) {
                    'troncal' => $agency->trunk_id,
                    'posta1' => $agency->post_id,
                    'posta2' => $agency->second_post_id,
                };
                if ($transportId === null) {
                    throw ValidationException::withMessages(['configuration_ids' => 'Esta agencia no tiene transporte asignado para ese tramo.']);
                }
                $transport = DB::table($table)->where(['tenant_id' => $tenant, 'id' => $transportId])->firstOrFail();
                $driver = $transport->driver_id ? DB::table('Ope_Choferes')->where(['tenant_id' => $tenant, 'id' => $transport->driver_id])->first() : null;
            }
            foreach ($configurations as $configuration) {
                if ($configuration->role !== $first->role || $configuration->origin_id !== $first->origin_id || $configuration->destination_id !== $first->destination_id) {
                    throw ValidationException::withMessages(['configuration_ids' => 'Las coberturas de la agencia deben tener el mismo tramo, origen y destino.']);
                }
            }
            $id = DB::table('Ope_ProgramacionSalidas')->insertGetId([
                'lot_id' => $lotId,
                'departure_date' => $input['departure_date'],
                'name' => $agency ? mb_substr($agency->name.' · '.$input['name'], 0, 160) : $input['name'],
                'role' => $first->role,
                'origin_id' => $first->origin_id,
                'destination_id' => $first->destination_id,
                'plate' => $transport?->plate,
                'driver_name' => $driver?->name,
                'driver_rut' => $driver?->rut,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($configurations as $configuration) {
                $origin = DB::table('Ope_Ubicaciones')->where(['tenant_id' => $tenant, 'id' => $configuration->origin_id, 'is_active' => true])->firstOrFail();
                $destination = DB::table('Ope_Ubicaciones')->where(['tenant_id' => $tenant, 'id' => $configuration->destination_id, 'is_active' => true])->firstOrFail();
                $packages = DB::table('Ope_Bultos')->where(['lot_id' => $lotId, 'coverage_id' => $configuration->coverage_id, 'excluded' => false])->get();
                if ($packages->isEmpty()) {
                    throw ValidationException::withMessages(['configuration_ids' => 'Una de las agencias seleccionadas no tiene bultos disponibles.']);
                }
                $packageIds = $packages->pluck('id');
                if (DB::table('Ope_BultoTramos')->where('configuration_id', $configuration->id)->whereIn('package_id', $packageIds)->exists()) {
                    throw ValidationException::withMessages(['configuration_ids' => 'Un bulto ya está programado para este tramo.']);
                }
                $missingGuide = $configuration->requires_customer_guide ? $packages->first(fn ($package): bool => ! $package->customer_guide) : null;
                if ($missingGuide) {
                    throw ValidationException::withMessages(['configuration_ids' => 'Falta el número de guía cliente requerido para '.$missingGuide->tracking.'.']);
                }
                $prior = DB::table('Ope_GuiaConfiguraciones')->where('coverage_id', $configuration->coverage_id)->where('is_active', true)->where('sequence', '<', $configuration->sequence)->orderBy('sequence')->pluck('id');
                $hasRegisteredPostOrigin = $first->role !== 'troncal'
                    && filled($transport?->origin_address)
                    && filled($transport?->origin_commune);
                foreach ($prior as $priorId) {
                    $previousMembers = DB::table('Ope_BultoTramos')->where('configuration_id', $priorId)->whereIn('package_id', $packageIds)->get(['package_id', 'departure_id'])->keyBy('package_id');
                    if ($previousMembers->count() !== $packages->count()) {
                        $missingPackage = $packages->first(fn ($package): bool => ! $previousMembers->has($package->id));
                        throw ValidationException::withMessages(['configuration_ids' => 'Programa primero los tramos anteriores de '.$missingPackage->tracking.'.']);
                    }
                    if ($prior->last() === $priorId && ! $hasRegisteredPostOrigin) {
                        $previousSnapshots = DB::table('Ope_SalidaAgencias')->where('configuration_id', $priorId)
                            ->whereIn('departure_id', $previousMembers->pluck('departure_id')->unique())
                            ->pluck('snapshot', 'departure_id');
                        foreach ($previousMembers as $member) {
                            $previous = json_decode($previousSnapshots->get($member->departure_id, 'null'), true);
                            if (! $previous || $previous['destination']['id'] !== $origin->id
                                || OperationAccess::key($previous['destination']['address']) !== OperationAccess::key($origin->address)
                                || OperationAccess::key($previous['destination']['commune']) !== OperationAccess::key($origin->commune)) {
                                throw ValidationException::withMessages(['configuration_ids' => 'El origen de la posta no coincide con el destino guardado en la salida anterior.']);
                            }
                        }
                    }
                }
                foreach ($packageIds->chunk(300) as $chunk) {
                    DB::table('Ope_BultoTramos')->insert($chunk->map(fn ($packageId): array => [
                        'departure_id' => $id, 'package_id' => $packageId, 'configuration_id' => $configuration->id,
                    ])->all());
                }
                DB::table('Ope_SalidaAgencias')->insert(['departure_id' => $id, 'configuration_id' => $configuration->id, 'snapshot' => OperationAccess::json(['configuration' => $configuration, 'origin' => $origin, 'destination' => $destination, 'transport' => ['name' => $transport?->name]])]);
            }
            OperationAccess::audit($tenant, $user, 'Programar salida', 'salida', $id, $input);

            return $id;
        });
    }

    private function createGroupedDeparture(int $tenant, int $user, int $lotId, array $input, object $first, Collection $selected): int
    {
        $coverageIds = DB::table('Ope_Bultos')->where(['lot_id' => $lotId, 'excluded' => false])
            ->whereNotNull('coverage_id')->distinct()->pluck('coverage_id');
        $candidates = DB::table('Ope_GuiaConfiguraciones as configuration')
            ->join('PPR_coverages as coverage', 'coverage.id', '=', 'configuration.coverage_id')
            ->join('Ope_Agencias as agency', function ($join): void {
                $join->on('agency.agency_code', '=', 'coverage.ID_ComunaMatrizAgencia')
                    ->on('agency.tenant_id', '=', 'coverage.tenant_id');
            })
            ->where('configuration.tenant_id', $tenant)->where('configuration.is_active', true)
            ->where('configuration.group_code', $first->group_code)
            ->where('configuration.sequence', $first->sequence)
            ->where('configuration.role', $first->role)
            ->where('configuration.origin_id', $first->origin_id)
            ->where('configuration.destination_id', $first->destination_id)
            ->whereIn('configuration.coverage_id', $coverageIds)
            ->orderBy('agency.agency_code')->orderBy('configuration.id')
            ->get(['configuration.*', 'agency.name as agency_name', 'agency.agency_code']);
        $firstTransport = $this->configurationTransport($tenant, $first);
        $signature = $this->transportSignature($firstTransport);
        $configurations = $candidates->filter(fn (object $configuration): bool => $this->transportSignature(
            $this->configurationTransport($tenant, $configuration),
        ) === $signature)->values();
        if ($configurations->isEmpty() || $selected->pluck('id')->diff($configurations->pluck('id'))->isNotEmpty()) {
            throw ValidationException::withMessages(['configuration_ids' => 'Selecciona agencias del mismo vehículo y punto de descarga.']);
        }

        $origin = DB::table('Ope_Ubicaciones')->where(['tenant_id' => $tenant, 'id' => $first->origin_id, 'is_active' => true])->firstOrFail();
        $destination = DB::table('Ope_Ubicaciones')->where(['tenant_id' => $tenant, 'id' => $first->destination_id, 'is_active' => true])->firstOrFail();
        if (OperationAccess::key($origin->address) === 'pendiente' || OperationAccess::key($destination->address) === 'pendiente') {
            throw ValidationException::withMessages(['configuration_ids' => 'Completa las direcciones reales del origen y la descarga antes de programar esta guía.']);
        }
        $agencyNames = $configurations->pluck('agency_name')->unique()->values();
        $routeName = $agencyNames->count() === 1 ? $agencyNames->first() : $destination->name.' · '.$agencyNames->count().' agencias';
        $id = DB::table('Ope_ProgramacionSalidas')->insertGetId([
            'lot_id' => $lotId, 'departure_date' => $input['departure_date'],
            'name' => mb_substr($routeName.' · '.$input['name'], 0, 160),
            'role' => $first->role, 'origin_id' => $origin->id, 'destination_id' => $destination->id,
            'plate' => $firstTransport['transport']->plate,
            'driver_name' => $firstTransport['driver']?->name,
            'driver_rut' => $firstTransport['driver']?->rut,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($configurations as $configuration) {
            $packages = DB::table('Ope_Bultos')->where(['lot_id' => $lotId, 'coverage_id' => $configuration->coverage_id, 'excluded' => false])->get();
            $packageIds = $packages->pluck('id');
            if ($configuration->requires_customer_guide && $packages->contains(fn (object $package): bool => blank($package->customer_guide))) {
                throw ValidationException::withMessages(['configuration_ids' => 'Falta la guía del cliente en una de las coberturas seleccionadas.']);
            }
            if (DB::table('Ope_BultoTramos')->where('configuration_id', $configuration->id)->whereIn('package_id', $packageIds)->exists()) {
                throw ValidationException::withMessages(['configuration_ids' => 'Uno de los bultos ya está programado para este tramo.']);
            }
            $prior = DB::table('Ope_GuiaConfiguraciones')->where(['coverage_id' => $configuration->coverage_id, 'is_active' => true])
                ->where('sequence', '<', $configuration->sequence)->orderBy('sequence')->get(['id', 'sequence']);
            foreach ($prior as $previousLeg) {
                $members = DB::table('Ope_BultoTramos')->where('configuration_id', $previousLeg->id)
                    ->whereIn('package_id', $packageIds)->get(['package_id', 'departure_id']);
                if ($members->count() !== $packages->count()) {
                    throw ValidationException::withMessages(['configuration_ids' => 'Programa primero todos los tramos anteriores de la carga seleccionada.']);
                }
                if ($previousLeg->id === $prior->last()->id && ! str_starts_with($configuration->group_code, 'air:agency:')) {
                    $snapshots = DB::table('Ope_SalidaAgencias')->where('configuration_id', $previousLeg->id)
                        ->whereIn('departure_id', $members->pluck('departure_id')->unique())->pluck('snapshot', 'departure_id');
                    foreach ($members as $member) {
                        $previous = json_decode($snapshots->get($member->departure_id, 'null'), true);
                        if (! $previous || ($previous['destination']['id'] ?? null) !== $origin->id) {
                            throw ValidationException::withMessages(['configuration_ids' => 'El origen de esta posta no coincide con la descarga del tramo anterior.']);
                        }
                    }
                }
            }
            foreach ($packageIds->chunk(300) as $chunk) {
                DB::table('Ope_BultoTramos')->insert($chunk->map(fn (int $packageId): array => [
                    'departure_id' => $id, 'package_id' => $packageId, 'configuration_id' => $configuration->id,
                ])->all());
            }
            $transport = $this->configurationTransport($tenant, $configuration)['transport'];
            DB::table('Ope_SalidaAgencias')->insert([
                'departure_id' => $id, 'configuration_id' => $configuration->id,
                'snapshot' => OperationAccess::json([
                    'configuration' => $configuration, 'origin' => $origin, 'destination' => $destination,
                    'transport' => ['name' => $transport->name],
                ]),
            ]);
        }
        OperationAccess::audit($tenant, $user, 'Programar salida consolidada', 'salida', $id, $input);

        return $id;
    }

    /** @return array{transport: object, driver: object|null} */
    private function configurationTransport(int $tenant, object $configuration): array
    {
        $table = $configuration->transport_kind === 'trunk' ? 'Ope_Troncales' : 'Ope_Postas';
        $transport = DB::table($table)->where(['tenant_id' => $tenant, 'id' => $configuration->transport_id])->firstOrFail();
        $driver = $transport->driver_id ? DB::table('Ope_Choferes')->where(['tenant_id' => $tenant, 'id' => $transport->driver_id])->first() : null;

        return compact('transport', 'driver');
    }

    /** @param  array{transport: object, driver: object|null}  $assignment */
    private function transportSignature(array $assignment): string
    {
        return OperationAccess::json([
            $assignment['transport']->plate,
            $assignment['driver']?->rut,
            $assignment['driver']?->name,
        ]);
    }

    public function createDepartures(int $tenant, int $user, int $lotId, array $input): array
    {
        return DB::transaction(function () use ($tenant, $user, $lotId, $input): array {
            $selected = DB::table('Ope_GuiaConfiguraciones as configuration')
                ->join('PPR_coverages as coverage', 'coverage.id', '=', 'configuration.coverage_id')
                ->where('configuration.tenant_id', $tenant)
                ->where('coverage.tenant_id', $tenant)
                ->where('configuration.is_active', true)
                ->whereIn('configuration.id', $input['configuration_ids'])
                ->orderBy('configuration.sequence')
                ->orderBy('configuration.stop_order')
                ->orderBy('coverage.ID_ComunaMatrizAgencia')
                ->orderBy('configuration.id')
                ->get(['configuration.id', 'configuration.role', 'configuration.sequence', 'configuration.group_code',
                    'configuration.transport_kind', 'configuration.transport_id', 'coverage.ID_ComunaMatrizAgencia as agency_code']);
            if ($selected->count() !== count($input['configuration_ids'])) {
                throw ValidationException::withMessages(['configuration_ids' => 'Una de las agencias seleccionadas ya no está disponible. Actualiza la página.']);
            }
            $uniqueLegs = $selected->unique(fn ($configuration): string => ($configuration->agency_code ?? 'legacy-'.$configuration->id).'|'.$configuration->role);
            if ($uniqueLegs->count() !== $selected->count()) {
                throw ValidationException::withMessages(['configuration_ids' => 'Selecciona una sola fila por agencia y tramo.']);
            }

            $departures = [];
            $scheduledGroups = [];
            $signatures = [];
            foreach ($selected as $configuration) {
                $group = 'single:'.$configuration->id;
                if ($configuration->group_code !== null) {
                    $transportKey = $configuration->transport_kind.'|'.$configuration->transport_id;
                    $signatures[$transportKey] ??= $this->transportSignature($this->configurationTransport($tenant, $configuration));
                    $group = $configuration->sequence.'|'.$configuration->group_code.'|'.$signatures[$transportKey];
                }
                if (isset($scheduledGroups[$group])) {
                    continue;
                }
                $scheduledGroups[$group] = true;
                $departures[] = $this->createDeparture($tenant, $user, $lotId, [
                    'name' => $input['name'],
                    'departure_date' => $input['departure_date'],
                    'configuration_ids' => [$configuration->id],
                ]);
            }

            return $departures;
        });
    }

    public function saveAssignment(int $tenant, int $user, int $id, array $input): void
    {
        DB::transaction(function () use ($tenant, $user, $id, $input): void {
            $departure = $this->departure($tenant, $id);
            if ($departure->status !== 'draft') {
                throw ValidationException::withMessages(['departure' => 'Reabre la salida con un motivo antes de cambiar el transporte.']);
            }
            if (in_array(OperationAccess::key($input['driver_name']), ['pendiente', 'no aplica', 'n/a', 'por definir'], true) || preg_match('/\p{L}/u', $input['driver_name']) !== 1) {
                throw ValidationException::withMessages(['driver_name' => 'Ingresa el nombre real del chofer de esta salida.']);
            }
            $rut = mb_strtoupper(str_replace(['.', ' '], '', $input['driver_rut']));
            if (! OperationAccess::validRut($rut)) {
                throw ValidationException::withMessages(['driver_rut' => 'El RUT del chofer no tiene un dígito verificador válido.']);
            }
            $plate = mb_strtoupper(str_replace(['-', ' '], '', $input['plate']));
            if (preg_match('/^(?:[A-Z]{4}\d{2}|[A-Z]{2}\d{4})$/D', $plate) !== 1) {
                throw ValidationException::withMessages(['plate' => 'Ingresa una patente chilena válida: ABCD12 o AB1234.']);
            }
            $after = ['plate' => $plate, 'driver_name' => $input['driver_name'], 'driver_rut' => $rut, 'updated_at' => now()];
            DB::table('Ope_ProgramacionSalidas')->where('id', $id)->update($after);
            OperationAccess::audit($tenant, $user, 'Asignar transporte', 'salida', $id, $after, $departure);
        });
    }

    public function approveAll(int $tenant, int $user, int $lotId): int
    {
        return DB::transaction(function () use ($tenant, $user, $lotId): int {
            DB::table('Ope_Lotes')->where(['tenant_id' => $tenant, 'id' => $lotId])->lockForUpdate()->firstOrFail();
            $departures = DB::table('Ope_ProgramacionSalidas')
                ->where(['lot_id' => $lotId, 'status' => 'draft'])
                ->orderByRaw("CASE role WHEN 'troncal' THEN 1 WHEN 'posta1' THEN 2 WHEN 'posta2' THEN 3 WHEN 'posta3' THEN 4 ELSE 5 END")
                ->orderBy('departure_date')
                ->orderBy('id')
                ->get(['id', 'name']);
            if ($departures->isEmpty()) {
                throw ValidationException::withMessages(['approvals' => 'No hay salidas pendientes de aprobación en este proceso.']);
            }

            foreach ($departures as $departure) {
                try {
                    $this->approve($tenant, $user, $departure->id);
                } catch (ValidationException $exception) {
                    $reason = collect($exception->errors())->flatten()->first() ?? 'Revisa los datos de esta salida.';
                    throw ValidationException::withMessages(['approvals' => 'Salida #'.$departure->id.' · '.$departure->name.': '.$reason.' No se aprobó ninguna salida.']);
                }
            }
            OperationAccess::audit($tenant, $user, 'Aprobar salidas en bloque', 'lote', $lotId, [
                'departures' => $departures->pluck('id')->all(),
            ]);

            return $departures->count();
        });
    }

    public function approve(int $tenant, int $user, int $id): int
    {
        return DB::transaction(function () use ($tenant, $user, $id): int {
            DB::table('MBA_tenants')->where('id', $tenant)->lockForUpdate()->firstOrFail();
            $departure = $this->departure($tenant, $id);
            if ($departure->status === 'approved') {
                return (int) DB::table('Ope_Guias')->where(['departure_id' => $id, 'version' => $departure->version])->value('id');
            }
            if ($departure->status !== 'draft') {
                throw ValidationException::withMessages(['departure' => 'La salida está cancelada. Programa una nueva salida.']);
            }
            $this->assertReviewed($departure->lot_id);
            if (! $departure->plate || ! $departure->driver_name || ! $departure->driver_rut) {
                throw ValidationException::withMessages(['departure' => 'Completa patente, nombre y RUT del chofer antes de aprobar.']);
            }
            $snapshot = $this->preview($id);
            if ($snapshot['packages'] === []) {
                throw ValidationException::withMessages(['departure' => 'La salida no contiene bultos.']);
            }
            foreach ($snapshot['packages'] as $package) {
                $prior = DB::table('Ope_GuiaConfiguraciones')->where('coverage_id', $package['coverage_id'])->where('is_active', true)->where('sequence', '<', $package['sequence'])->pluck('id');
                foreach ($prior as $priorId) {
                    $priorDeparture = DB::table('Ope_BultoTramos')->where(['package_id' => $package['id'], 'configuration_id' => $priorId])->value('departure_id');
                    $priorState = DB::table('Ope_ProgramacionSalidas')->where('id', $priorDeparture)->first();
                    if (! $priorState || $priorState->status !== 'approved' || $priorState->departure_date > $departure->departure_date) {
                        throw ValidationException::withMessages(['departure' => 'Aprueba los tramos anteriores antes de esta posta y respeta sus fechas.']);
                    }
                }
                $other = DB::table('Ope_Bultos as b')->join('Ope_BultoTramos as t', 't.package_id', '=', 'b.id')->join('Ope_ProgramacionSalidas as d', 'd.id', '=', 't.departure_id')->join('Ope_Lotes as l', 'l.id', '=', 'b.lot_id')->where('l.tenant_id', $tenant)->where('b.tracking', $package['tracking'])->where('b.lot_id', '<>', $departure->lot_id)->where('d.status', 'approved');
                if ($other->exists()) {
                    $ancestors = $this->reservationAncestors($package['id']);
                    if ($ancestors === [] || $other->whereNotIn('b.id', $ancestors)->exists()) {
                        throw ValidationException::withMessages(['departure' => 'El paquete '.$package['tracking'].' ya tiene una salida aprobada en otro proceso.']);
                    }
                }
            }
            $encoded = OperationAccess::json($snapshot);
            $guide = DB::table('Ope_Guias')->insertGetId(['departure_id' => $id, 'version' => $departure->version, 'snapshot' => $encoded, 'sha256' => hash('sha256', $encoded), 'approved_by' => $user, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('Ope_ProgramacionSalidas')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $user, 'approved_at' => now(), 'updated_at' => now()]);
            OperationAccess::audit($tenant, $user, 'Aprobar guía interna', 'guia', $guide, ['departure_id' => $id, 'version' => $departure->version, 'sha256' => hash('sha256', $encoded)]);

            return $guide;
        });
    }

    /** @return array<int, int> */
    private function reservationAncestors(int $packageId): array
    {
        $ancestors = [];
        while ($sourceId = DB::table('Ope_Reservas')->where(['status' => 'included', 'included_package_id' => $packageId])->value('source_package_id')) {
            $sourceId = (int) $sourceId;
            if (in_array($sourceId, $ancestors, true)) {
                break;
            }
            $ancestors[] = $sourceId;
            $packageId = $sourceId;
        }

        return $ancestors;
    }

    public function reopen(int $tenant, int $user, int $id, string $reason): void
    {
        DB::transaction(function () use ($tenant, $user, $id, $reason): void {
            $departure = $this->departure($tenant, $id);
            if ($departure->status !== 'approved') {
                throw ValidationException::withMessages(['departure' => 'Solo se puede reabrir una salida aprobada.']);
            }
            $packageIds = DB::table('Ope_BultoTramos')->where('departure_id', $id)->pluck('package_id');
            $downstream = DB::table('Ope_BultoTramos as t')->join('Ope_GuiaConfiguraciones as c', 'c.id', '=', 't.configuration_id')->join('Ope_ProgramacionSalidas as d', 'd.id', '=', 't.departure_id')->whereIn('t.package_id', $packageIds)->where('d.status', 'approved')->where('c.sequence', '>', ['troncal' => 1, 'posta1' => 2, 'posta2' => 3, 'posta3' => 4][$departure->role])->exists();
            if ($downstream) {
                throw ValidationException::withMessages(['departure' => 'Reabre primero las postas posteriores que ya están aprobadas.']);
            }
            DB::table('Ope_ProgramacionSalidas')->where('id', $id)->update(['status' => 'draft', 'version' => $departure->version + 1, 'approved_by' => null, 'approved_at' => null, 'updated_at' => now()]);
            OperationAccess::audit($tenant, $user, 'Reabrir salida', 'salida', $id, ['reason' => $reason, 'version' => $departure->version + 1], $departure);
        });
    }

    public function cancel(int $tenant, int $user, int $id, string $reason): void
    {
        DB::transaction(function () use ($tenant, $user, $id, $reason): void {
            $departure = $this->departure($tenant, $id);
            if ($departure->status !== 'draft' || DB::table('Ope_Guias')->where('departure_id', $id)->exists()) {
                throw ValidationException::withMessages(['departure' => 'Solo se puede cancelar una salida que nunca ha sido aprobada.']);
            }
            $packageIds = DB::table('Ope_BultoTramos')->where('departure_id', $id)->pluck('package_id');
            $hasLater = DB::table('Ope_BultoTramos as t')->join('Ope_GuiaConfiguraciones as c', 'c.id', '=', 't.configuration_id')->whereIn('t.package_id', $packageIds)->where('c.sequence', '>', ['troncal' => 1, 'posta1' => 2, 'posta2' => 3, 'posta3' => 4][$departure->role])->exists();
            if ($hasLater) {
                throw ValidationException::withMessages(['departure' => 'Cancela primero las postas posteriores programadas.']);
            }
            DB::table('Ope_BultoTramos')->where('departure_id', $id)->delete();
            DB::table('Ope_ProgramacionSalidas')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            OperationAccess::audit($tenant, $user, 'Cancelar salida', 'salida', $id, ['reason' => $reason], $departure);
        });
    }

    public function preview(int $id): array
    {
        $departure = DB::table('Ope_ProgramacionSalidas')->where('id', $id)->firstOrFail();
        $agencies = DB::table('Ope_SalidaAgencias')->where('departure_id', $id)->get()->keyBy('configuration_id');
        $rows = DB::table('Ope_BultoTramos as t')->join('Ope_Bultos as b', 'b.id', '=', 't.package_id')->where('t.departure_id', $id)->orderBy('b.tracking')->select('b.*', 't.configuration_id')->get();
        $packages = [];
        $groups = [];
        $weightUnits = 0;
        foreach ($rows as $row) {
            $configuration = json_decode($agencies[$row->configuration_id]->snapshot, true)['configuration'];
            $package = (array) $row;
            $package['sequence'] = $configuration['sequence'];
            if ($row->weight === null) {
                $master = json_decode($row->snapshot, true)['master'] ?? [];
                $geolizeWeight = array_key_exists('geolize_weight', $master)
                    ? $master['geolize_weight']
                    : $this->legacyPackageMasterWeight($row);
                $package['weight'] = $this->wholeGeolizeWeight($geolizeWeight);
            }
            $packages[] = $package;
            $units = (int) round((float) $package['weight'] * 1000);
            $weightUnits += $units;
            $key = OperationAccess::json([$row->merchant, $row->service, $row->customer_guide, $row->reference, $row->configuration_id]);
            if (! isset($groups[$key])) {
                $groups[$key] = ['merchant' => $row->merchant, 'service' => $row->service, 'customer_guide' => $row->customer_guide, 'reference' => $row->reference, 'count' => 0, 'weight_units' => 0, 'template' => $configuration['template'], 'agency' => $configuration['name'], 'packages' => []];
            }
            $groups[$key]['count']++;
            $groups[$key]['weight_units'] += $units;
            $groups[$key]['packages'][] = $row->tracking;
        }
        $lines = [];
        foreach ($groups as $group) {
            $group['weight'] = number_format($group['weight_units'] / 1000, 3, '.', '');
            $group['description'] = strtr($group['template'], ['{cliente}' => $group['merchant'] ?? '', '{servicio}' => $group['service'] ?? '', '{bultos}' => (string) $group['count'], '{peso}' => $group['weight'], '{guia_cliente}' => $group['customer_guide'] ?? '', '{referencia}' => $group['reference'] ?? '']);
            unset($group['weight_units'], $group['template']);
            $lines[] = $group;
        }
        $agencyData = $agencies->map(fn ($row) => json_decode($row->snapshot, true))->values()->all();

        return ['departure' => (array) $departure, 'origin' => $agencyData[0]['origin'] ?? [], 'destination' => $agencyData[0]['destination'] ?? [], 'agencies' => $agencyData, 'count' => count($packages), 'weight' => number_format($weightUnits / 1000, 3, '.', ''), 'lines' => $lines, 'packages' => $packages];
    }

    public function departureSpreadsheetRows(int $tenant, int $lot): array
    {
        $departures = DB::table('Ope_ProgramacionSalidas as departure')
            ->join('Ope_Lotes as lot', 'lot.id', '=', 'departure.lot_id')
            ->where('lot.tenant_id', $tenant)
            ->where('lot.id', $lot)
            ->where('departure.status', '<>', 'cancelled')
            ->orderBy('departure.id')
            ->get(['departure.id', 'departure.status', 'departure.version', 'departure.departure_date']);
        $guides = DB::table('Ope_Guias')->whereIn('departure_id', $departures->pluck('id'))
            ->get(['departure_id', 'version', 'snapshot'])
            ->keyBy(fn ($guide): string => $guide->departure_id.':'.$guide->version);
        $catalog = DB::table('Ope_SalidaAgencias as assigned')
            ->join('Ope_GuiaConfiguraciones as configuration', 'configuration.id', '=', 'assigned.configuration_id')
            ->join('PPR_coverages as coverage', 'coverage.id', '=', 'configuration.coverage_id')
            ->leftJoin('Ope_Agencias as agency', function ($join): void {
                $join->on('agency.agency_code', '=', 'coverage.ID_ComunaMatrizAgencia')
                    ->on('agency.tenant_id', '=', 'coverage.tenant_id');
            })
            ->leftJoin('Ope_Troncales as trunk', 'trunk.id', '=', 'agency.trunk_id')
            ->leftJoin('Ope_Postas as first_post', 'first_post.id', '=', 'agency.post_id')
            ->leftJoin('Ope_Postas as second_post', 'second_post.id', '=', 'agency.second_post_id')
            ->whereIn('assigned.departure_id', $departures->pluck('id'))
            ->get(['assigned.departure_id', 'trunk.name as trunk_name', 'first_post.name as first_post_name', 'second_post.name as second_post_name'])
            ->keyBy('departure_id');
        $rows = [];

        foreach ($departures as $departure) {
            $guide = $guides->get($departure->id.':'.$departure->version);
            $snapshot = $departure->status === 'approved' && $guide
                ? json_decode($guide->snapshot, true)
                : $this->preview($departure->id);
            $origin = $snapshot['origin'] ?? [];
            $destination = $snapshot['destination'] ?? [];
            $transport = $snapshot['departure'] ?? [];
            $role = $transport['role'] ?? '';
            $roleLabel = match ($role) {
                'troncal' => 'Troncal',
                'posta1' => 'Posta 1',
                'posta2' => 'Posta 2',
                'posta3' => 'Posta 3',
                default => 'Transporte',
            };
            $currentCatalog = $catalog->get($departure->id);
            $catalogName = match ($role) {
                'troncal' => $currentCatalog?->trunk_name,
                'posta1' => $currentCatalog?->first_post_name,
                'posta2' => $currentCatalog?->second_post_name,
                default => null,
            };
            $transportName = $snapshot['agencies'][0]['transport']['name'] ?? $catalogName;
            $transportLabel = $transportName ? $roleLabel.' · '.$transportName : $roleLabel;

            foreach ($snapshot['lines'] ?? [] as $lineOrder => $line) {
                $wholeWeight = (int) (float) $line['weight'];
                $rows[] = [
                    'declared_date' => $transport['departure_date'] ?? $departure->departure_date,
                    'transport' => $transportLabel,
                    'number' => 0,
                    'origin_address' => $origin['address'] ?? '',
                    'origin_commune' => $origin['commune'] ?? '',
                    'plate' => $transport['plate'] ?? '',
                    'driver_rut' => $transport['driver_rut'] ?? '',
                    'driver_name' => $transport['driver_name'] ?? '',
                    'destination_address' => $destination['address'] ?? '',
                    'destination_commune' => $destination['commune'] ?? '',
                    'agency' => $line['agency'] ?? '',
                    'description' => str_replace((string) $line['weight'], (string) $wholeWeight, $line['description'] ?? ''),
                    'count' => (int) $line['count'],
                    'weight' => $wholeWeight,
                    '_role_order' => ['troncal' => 1, 'posta1' => 2, 'posta2' => 3, 'posta3' => 4][$role] ?? 5,
                    '_departure_id' => $departure->id,
                    '_line_order' => $lineOrder,
                ];
            }
        }

        usort($rows, fn (array $left, array $right): int => [$left['declared_date'], $left['_role_order'], $left['transport'], $left['agency'], $left['_departure_id'], $left['_line_order']]
            <=> [$right['declared_date'], $right['_role_order'], $right['transport'], $right['agency'], $right['_departure_id'], $right['_line_order']]);
        $routeNumbers = [];
        foreach ($rows as &$row) {
            $route = OperationAccess::json([$row['_departure_id'], $row['transport'], $row['agency']]);
            $row['number'] = $routeNumbers[$route] = ($routeNumbers[$route] ?? 0) + 1;
            unset($row['_role_order'], $row['_departure_id'], $row['_line_order']);
        }
        unset($row);

        return $rows;
    }

    private function departure(int $tenant, int $id): object
    {
        $departure = DB::table('Ope_ProgramacionSalidas')->whereIn('lot_id', DB::table('Ope_Lotes')->where('tenant_id', $tenant)->select('id'))->where('id', $id)->lockForUpdate()->firstOrFail();
        DB::table('Ope_Lotes')->where('id', $departure->lot_id)->lockForUpdate()->firstOrFail();

        return $departure;
    }

    private function legacyPackageMasterWeight(object $package): mixed
    {
        $masterLoad = $this->masterLoadsByLot[$package->lot_id] ??= (int) DB::table('Ope_Lotes')->where('id', $package->lot_id)->value('master_load_id');
        $masterRow = DB::table('Ope_FilasFuente')->where(['load_id' => $masterLoad, 'tracking' => $package->tracking])->first(['raw', 'data']);
        if (! $masterRow) {
            return null;
        }

        $data = json_decode($masterRow->data, true);
        if (array_key_exists('geolize_weight', $data)) {
            return $data['geolize_weight'];
        }

        return $this->legacyMasterWeight($masterLoad, $masterRow->raw);
    }

    private function legacyMasterWeight(int $masterLoad, string $raw): mixed
    {
        $column = $this->masterWeightColumn($masterLoad);

        return $column === null ? null : (json_decode($raw, true)[$column] ?? null);
    }

    private function masterWeightColumn(int $masterLoad): ?int
    {
        if (array_key_exists($masterLoad, $this->masterWeightColumns)) {
            return $this->masterWeightColumns[$masterLoad];
        }

        $load = DB::table('Ope_Cargas')->where('id', $masterLoad)->first(['path', 'sheet']);
        if (! $load || ! Storage::disk('local')->exists($load->path)) {
            return $this->masterWeightColumns[$masterLoad] = null;
        }

        $reader = new Reader;
        $opened = false;
        try {
            $reader->open(Storage::disk('local')->path($load->path));
            $opened = true;
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() !== $load->sheet) {
                    continue;
                }
                foreach ($sheet->getRowIterator() as $row) {
                    $headers = array_map(fn ($value): string => OperationAccess::key((string) $value), $row->toArray());
                    $column = array_search('peso', $headers, true);

                    return $this->masterWeightColumns[$masterLoad] = $column === false ? null : $column;
                }
            }
        } catch (Throwable $error) {
            report($error);
        } finally {
            if ($opened) {
                $reader->close();
            }
        }

        return $this->masterWeightColumns[$masterLoad] = null;
    }

    private function wholeGeolizeWeight(mixed $value): ?string
    {
        if (preg_match('/^([0-9]+)(?:[.,][0-9]+)?$/D', trim((string) $value), $matches) !== 1) {
            return null;
        }

        $whole = (int) $matches[1];
        if ($whole < 1 || $whole > 999999999) {
            return null;
        }

        return number_format($whole, 3, '.', '');
    }

    private function issue(int $lot, ?int $package, string $code, string $message, array $context): void
    {
        DB::table('Ope_Incidencias')->insert(['lot_id' => $lot, 'package_id' => $package, 'code' => $code, 'message' => $message, 'context' => OperationAccess::json($context), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function editable(int $lot): void
    {
        if (DB::table('Ope_ProgramacionSalidas')->where('lot_id', $lot)->exists()) {
            throw ValidationException::withMessages(['lot' => 'El proceso ya tiene salidas. Prepara otro proceso para corregir sus fuentes.']);
        }
    }

    private function assertReviewed(int $lot): void
    {
        if (DB::table('Ope_Incidencias')->where('lot_id', $lot)->whereNull('resolved_at')->exists()) {
            throw ValidationException::withMessages(['lot' => 'Resuelve todas las incidencias antes de programar o aprobar salidas.']);
        }
    }
}
