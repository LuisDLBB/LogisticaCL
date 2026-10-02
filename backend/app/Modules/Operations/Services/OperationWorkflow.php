<?php

namespace App\Modules\Operations\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationWorkflow
{
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
                ->get()->groupBy(fn ($coverage): string => OperationAccess::key($coverage->commune_name));
            foreach ($valid->groupBy('tracking') as $tracking => $rows) {
                $readingData = $rows->map(fn ($row): array => json_decode($row->data, true));
                $distinct = $readingData->unique(fn ($data): string => OperationAccess::json(array_intersect_key($data, array_flip(['weight', 'operator', 'customer_guide', 'reference']))));
                $reading = $distinct->count() === 1 ? $rows->first() : null;
                $data = $reading ? json_decode($reading->data, true) : [];
                $masterRows = $masters->get($tracking, collect());
                $masterData = $masterRows->count() === 1 && json_decode($masterRows->first()->errors, true) === [] ? json_decode($masterRows->first()->data, true) : [];
                $coverageMatches = $coverages->get(OperationAccess::key($masterData['commune'] ?? ''), collect());
                $coverage = $coverageMatches->count() === 1 ? $coverageMatches->first() : null;
                $package = DB::table('Ope_Bultos')->insertGetId(['lot_id' => $lot, 'tracking' => $tracking, 'reading_id' => $reading?->id, 'weight' => $data['weight'] ?? null, 'operator' => $data['operator'] ?? null, 'customer_guide' => $data['customer_guide'] ?? null, 'reference' => $data['reference'] ?? null, 'merchant' => $masterData['merchant'] ?? null, 'service' => $masterData['service'] ?? null, 'commune' => $masterData['commune'] ?? null, 'coverage_id' => $coverage?->id, 'snapshot' => OperationAccess::json(['master' => $masterData, 'coverage' => $coverage]), 'created_at' => now(), 'updated_at' => now()]);
                if (! $reading) {
                    $this->issue($lot, $package, 'reading_conflict', 'Lecturas de Recepción distintas para el mismo paquete. Selecciona la lectura correcta.', ['row_ids' => $rows->pluck('id')->all(), 'readings' => $readingData->all()]);
                }
                if ($masterRows->count() !== 1 || json_decode($masterRows->first()?->errors ?? '[]', true) !== []) {
                    $this->issue($lot, $package, 'master_missing', 'Paquete ausente, repetido o inválido en el Maestro seleccionado. Corrige las fuentes y prepara un nuevo proceso, o excluye justificadamente el bulto.', ['row_ids' => $masterRows->pluck('id')->all()]);
                }
                if (! $coverage) {
                    $this->issue($lot, $package, 'coverage_conflict', 'Cobertura ausente o ambigua para '.$tracking.'. Selecciona la cobertura vigente correcta.', ['commune' => $masterData['commune'] ?? null, 'candidate_ids' => $coverageMatches->pluck('id')->all()]);
                }
            }
            if ($valid->isEmpty()) {
                $this->issue($lot, null, 'empty_lot', 'El proceso no tiene lecturas válidas. Corrige el Excel y prepara un nuevo proceso.', []);
            }
            OperationAccess::audit($tenant, $user, 'Preparar proceso', 'lote', $lot, $input);

            return $lot;
        });
    }

    public function resolve(int $tenant, int $user, int $lotId, int $issueId, array $input): void
    {
        DB::transaction(function () use ($tenant, $user, $lotId, $issueId, $input): void {
            $lot = DB::table('Ope_Lotes')->where(['tenant_id' => $tenant, 'id' => $lotId])->lockForUpdate()->firstOrFail();
            $this->editable($lotId);
            $issue = DB::table('Ope_Incidencias')->where(['lot_id' => $lotId, 'id' => $issueId])->whereNull('resolved_at')->firstOrFail();
            $context = json_decode($issue->context, true);
            $package = $issue->package_id ? DB::table('Ope_Bultos')->where('id', $issue->package_id)->firstOrFail() : null;
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
                DB::table('Ope_Bultos')->where('id', $package->id)->update(['reading_id' => $row->id, 'weight' => $data['weight'], 'operator' => $data['operator'], 'customer_guide' => $data['customer_guide'], 'reference' => $data['reference'], 'updated_at' => now()]);
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
        });
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
            foreach ($configurations as $configuration) {
                if ($configuration->role !== $first->role || $configuration->origin_id !== $first->origin_id || $configuration->destination_id !== $first->destination_id) {
                    throw ValidationException::withMessages(['configuration_ids' => 'Una salida comparte tramo, origen y destino. Separa las agencias con destinos distintos.']);
                }
            }
            $id = DB::table('Ope_ProgramacionSalidas')->insertGetId(['lot_id' => $lotId, 'departure_date' => $input['departure_date'], 'name' => $input['name'], 'role' => $first->role, 'origin_id' => $first->origin_id, 'destination_id' => $first->destination_id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($configurations as $configuration) {
                $origin = DB::table('Ope_Ubicaciones')->where(['tenant_id' => $tenant, 'id' => $configuration->origin_id, 'is_active' => true])->firstOrFail();
                $destination = DB::table('Ope_Ubicaciones')->where(['tenant_id' => $tenant, 'id' => $configuration->destination_id, 'is_active' => true])->firstOrFail();
                $packages = DB::table('Ope_Bultos')->where(['lot_id' => $lotId, 'coverage_id' => $configuration->coverage_id, 'excluded' => false])->get();
                if ($packages->isEmpty()) {
                    throw ValidationException::withMessages(['configuration_ids' => 'Una de las agencias seleccionadas no tiene bultos disponibles.']);
                }
                foreach ($packages as $package) {
                    if (DB::table('Ope_BultoTramos')->where(['package_id' => $package->id, 'configuration_id' => $configuration->id])->exists()) {
                        throw ValidationException::withMessages(['configuration_ids' => 'Un bulto ya está programado para este tramo.']);
                    }
                    if ($configuration->requires_customer_guide && ! $package->customer_guide) {
                        throw ValidationException::withMessages(['configuration_ids' => 'Falta el número de guía cliente requerido para '.$package->tracking.'.']);
                    }
                    $prior = DB::table('Ope_GuiaConfiguraciones')->where('coverage_id', $configuration->coverage_id)->where('is_active', true)->where('sequence', '<', $configuration->sequence)->orderBy('sequence')->pluck('id');
                    foreach ($prior as $priorId) {
                        $priorDeparture = DB::table('Ope_BultoTramos')->where(['package_id' => $package->id, 'configuration_id' => $priorId])->value('departure_id');
                        if (! $priorDeparture) {
                            throw ValidationException::withMessages(['configuration_ids' => 'Programa primero los tramos anteriores de '.$package->tracking.'.']);
                        }
                        $previous = json_decode(DB::table('Ope_SalidaAgencias')->where(['departure_id' => $priorDeparture, 'configuration_id' => $priorId])->value('snapshot'), true);
                        if ($prior->last() === $priorId && ($previous['destination']['id'] !== $origin->id || OperationAccess::key($previous['destination']['address']) !== OperationAccess::key($origin->address) || OperationAccess::key($previous['destination']['commune']) !== OperationAccess::key($origin->commune))) {
                            throw ValidationException::withMessages(['configuration_ids' => 'El origen de la posta no coincide con el destino guardado en la salida anterior.']);
                        }
                    }
                    DB::table('Ope_BultoTramos')->insert(['departure_id' => $id, 'package_id' => $package->id, 'configuration_id' => $configuration->id]);
                }
                DB::table('Ope_SalidaAgencias')->insert(['departure_id' => $id, 'configuration_id' => $configuration->id, 'snapshot' => OperationAccess::json(['configuration' => $configuration, 'origin' => $origin, 'destination' => $destination])]);
            }
            OperationAccess::audit($tenant, $user, 'Programar salida', 'salida', $id, $input);

            return $id;
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
            if (! $this->validRut($rut)) {
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
                $other = DB::table('Ope_Bultos as b')->join('Ope_BultoTramos as t', 't.package_id', '=', 'b.id')->join('Ope_ProgramacionSalidas as d', 'd.id', '=', 't.departure_id')->join('Ope_Lotes as l', 'l.id', '=', 'b.lot_id')->where('l.tenant_id', $tenant)->where('b.tracking', $package['tracking'])->where('b.lot_id', '<>', $departure->lot_id)->where('d.status', 'approved')->exists();
                if ($other) {
                    throw ValidationException::withMessages(['departure' => 'El paquete '.$package['tracking'].' ya tiene una salida aprobada en otro proceso.']);
                }
            }
            $encoded = OperationAccess::json($snapshot);
            $guide = DB::table('Ope_Guias')->insertGetId(['departure_id' => $id, 'version' => $departure->version, 'snapshot' => $encoded, 'sha256' => hash('sha256', $encoded), 'approved_by' => $user, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('Ope_ProgramacionSalidas')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $user, 'approved_at' => now(), 'updated_at' => now()]);
            OperationAccess::audit($tenant, $user, 'Aprobar guía interna', 'guia', $guide, ['departure_id' => $id, 'version' => $departure->version, 'sha256' => hash('sha256', $encoded)]);

            return $guide;
        });
    }

    public function reopen(int $tenant, int $user, int $id, string $reason): void
    {
        DB::transaction(function () use ($tenant, $user, $id, $reason): void {
            $departure = $this->departure($tenant, $id);
            if ($departure->status !== 'approved') {
                throw ValidationException::withMessages(['departure' => 'Solo se puede reabrir una salida aprobada.']);
            }
            $packageIds = DB::table('Ope_BultoTramos')->where('departure_id', $id)->pluck('package_id');
            $downstream = DB::table('Ope_BultoTramos as t')->join('Ope_GuiaConfiguraciones as c', 'c.id', '=', 't.configuration_id')->join('Ope_ProgramacionSalidas as d', 'd.id', '=', 't.departure_id')->whereIn('t.package_id', $packageIds)->where('d.status', 'approved')->where('c.sequence', '>', ['troncal' => 1, 'posta1' => 2, 'posta2' => 3][$departure->role])->exists();
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
            $hasLater = DB::table('Ope_BultoTramos as t')->join('Ope_GuiaConfiguraciones as c', 'c.id', '=', 't.configuration_id')->whereIn('t.package_id', $packageIds)->where('c.sequence', '>', ['troncal' => 1, 'posta1' => 2, 'posta2' => 3][$departure->role])->exists();
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
            $packages[] = $package;
            $units = (int) round((float) $row->weight * 1000);
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

    private function departure(int $tenant, int $id): object
    {
        $departure = DB::table('Ope_ProgramacionSalidas')->whereIn('lot_id', DB::table('Ope_Lotes')->where('tenant_id', $tenant)->select('id'))->where('id', $id)->lockForUpdate()->firstOrFail();
        DB::table('Ope_Lotes')->where('id', $departure->lot_id)->lockForUpdate()->firstOrFail();

        return $departure;
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

    private function validRut(string $rut): bool
    {
        if (preg_match('/^([1-9]\d{6,7})-([0-9K])$/D', $rut, $parts) !== 1) {
            return false;
        }
        $sum = 0;
        $multiplier = 2;
        foreach (str_split(strrev($parts[1])) as $digit) {
            $sum += (int) $digit * $multiplier;
            $multiplier = $multiplier === 7 ? 2 : $multiplier + 1;
        }
        $check = 11 - $sum % 11;

        return ($check === 11 ? '0' : ($check === 10 ? 'K' : (string) $check)) === $parts[2];
    }
}
