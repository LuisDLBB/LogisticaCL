<?php

namespace App\Modules\Operations\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OperationDataCleaner
{
    public function plan(int $tenant, string $source, string $selector, string $value): array
    {
        $systemIds = [];
        $loads = DB::table('Ope_Cargas')->where('tenant_id', $tenant);

        if ($source === 'system') {
            $receptions = DB::table('Ope_RecepcionesSistema')->where('tenant_id', $tenant);
            if ($selector === 'process') {
                $process = DB::table('Ope_Lotes')->where(['tenant_id' => $tenant, 'id' => (int) $value])->first();
                if (! $process) {
                    throw ValidationException::withMessages(['value' => 'Selecciona un proceso válido.']);
                }
                $receptions->whereIn('load_id', DB::table('Ope_LoteFuentes')->where('lot_id', $process->id)->select('load_id'));
            } else {
                $receptions->whereDate('created_at', $value);
            }
            $selected = $receptions->orderBy('id')->get(['id', 'load_id', 'photo_path']);
            $systemIds = $selected->pluck('id')->all();
            $loadIds = $selected->pluck('load_id')->filter()->map(fn ($id): int => (int) $id)->all();
        } elseif ($source === 'excel') {
            $loads->where('source_type', 'reception')->where('path', 'like', 'operations/'.$tenant.'/%');
            $loadIds = $selector === 'all' ? $loads->pluck('id')->all() : $loads->where('id', (int) $value)->pluck('id')->all();
        } else {
            $loads->where('source_type', 'master');
            $loadIds = $selector === 'all' ? $loads->pluck('id')->all() : $loads->where('id', (int) $value)->pluck('id')->all();
        }

        sort($loadIds);
        $lotIds = DB::table('Ope_Lotes')->where('tenant_id', $tenant)
            ->where(function ($query) use ($loadIds): void {
                $query->whereIn('master_load_id', $loadIds)
                    ->orWhereIn('id', DB::table('Ope_LoteFuentes')->whereIn('load_id', $loadIds)->select('lot_id'));
            })->orderBy('id')->pluck('id')->all();
        $departureIds = DB::table('Ope_ProgramacionSalidas')->whereIn('lot_id', $lotIds)->orderBy('id')->pluck('id')->all();
        $issueIds = DB::table('Ope_Incidencias')->whereIn('lot_id', $lotIds)->orderBy('id')->pluck('id')->all();
        $paths = DB::table('Ope_Cargas')->whereIn('id', $loadIds)->pluck('path')
            ->merge($source === 'system' ? $selected->pluck('photo_path') : [])
            ->filter(fn ($path): bool => is_string($path) && str_starts_with($path, 'operations/'.$tenant.'/') && ! str_contains($path, '..'))
            ->unique()->values()->all();
        sort($paths);

        $counts = [
            'loads' => count($loadIds),
            'system' => count($systemIds),
            'rows' => DB::table('Ope_FilasFuente')->whereIn('load_id', $loadIds)->count(),
            'scans' => DB::table('Ope_RecepcionSistemaBultos')->whereIn('reception_id', $systemIds)->count(),
            'processes' => count($lotIds),
            'packages' => DB::table('Ope_Bultos')->whereIn('lot_id', $lotIds)->count(),
            'issues' => count($issueIds),
            'departures' => count($departureIds),
            'guides' => DB::table('Ope_Guias')->whereIn('departure_id', $departureIds)->count(),
            'reservations' => DB::table('Ope_Reservas')->where('tenant_id', $tenant)
                ->where(fn ($query) => $query->whereIn('source_lot_id', $lotIds)->orWhereIn('included_lot_id', $lotIds))->count(),
        ];

        return [
            'source' => $source, 'selector' => $selector, 'value' => $value,
            'load_ids' => $loadIds, 'system_ids' => $systemIds, 'lot_ids' => $lotIds,
            'departure_ids' => $departureIds, 'issue_ids' => $issueIds, 'paths' => $paths,
            'counts' => $counts,
            'fingerprint' => hash('sha256', json_encode([$loadIds, $systemIds, $lotIds, $departureIds, $counts, $paths])),
        ];
    }

    public function clean(int $tenant, int $user, string $source, string $selector, string $value, string $fingerprint): array
    {
        $plan = DB::transaction(function () use ($tenant, $user, $source, $selector, $value, $fingerprint): array {
            $plan = $this->plan($tenant, $source, $selector, $value);
            if (! hash_equals($plan['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['selection' => 'Los datos cambiaron. Revisa nuevamente el resumen antes de limpiar.']);
            }
            if ($plan['counts']['loads'] === 0 && $plan['counts']['system'] === 0) {
                throw ValidationException::withMessages(['selection' => 'No hay datos para limpiar en esta selección.']);
            }
            if (DB::table('Ope_Cargas')->whereIn('id', $plan['load_ids'])->whereIn('status', ['queued', 'processing'])->exists()) {
                throw ValidationException::withMessages(['selection' => 'Hay una importación en curso. Espera a que termine antes de limpiar.']);
            }
            if ($plan['counts']['reservations'] > 0) {
                throw ValidationException::withMessages(['selection' => 'Esta selección contiene procesos vinculados a reservas. Consérvalos para no perder la trazabilidad de los bultos reservados o incluidos.']);
            }

            foreach ([
                'carga' => $plan['load_ids'],
                'lote' => $plan['lot_ids'],
                'incidencia' => $plan['issue_ids'],
                'salida' => $plan['departure_ids'],
                'recepcion_sistema' => $plan['system_ids'],
            ] as $entity => $ids) {
                if ($ids !== []) {
                    DB::table('Ope_Auditoria')->where('tenant_id', $tenant)->where('entity', $entity)->whereIn('entity_id', $ids)->delete();
                }
            }
            DB::table('Ope_Guias')->whereIn('departure_id', $plan['departure_ids'])->delete();
            DB::table('Ope_BultoTramos')->whereIn('departure_id', $plan['departure_ids'])->delete();
            DB::table('Ope_BultoTramos')->whereIn('package_id', DB::table('Ope_Bultos')->whereIn('lot_id', $plan['lot_ids'])->select('id'))->delete();
            DB::table('Ope_SalidaAgencias')->whereIn('departure_id', $plan['departure_ids'])->delete();
            DB::table('Ope_ProgramacionSalidas')->whereIn('id', $plan['departure_ids'])->delete();
            DB::table('Ope_Incidencias')->whereIn('lot_id', $plan['lot_ids'])->delete();
            DB::table('Ope_Bultos')->whereIn('lot_id', $plan['lot_ids'])->delete();
            DB::table('Ope_LoteFuentes')->whereIn('lot_id', $plan['lot_ids'])->delete();
            DB::table('Ope_Lotes')->whereIn('id', $plan['lot_ids'])->delete();
            DB::table('Ope_RecepcionSistemaBultos')->whereIn('reception_id', $plan['system_ids'])->delete();
            DB::table('Ope_RecepcionesSistema')->whereIn('id', $plan['system_ids'])->delete();
            DB::table('Ope_FilasFuente')->whereIn('load_id', $plan['load_ids'])->delete();
            DB::table('Ope_Cargas')->whereIn('id', $plan['load_ids'])->delete();
            OperationAccess::audit($tenant, $user, 'Limpiar datos', 'limpieza', 0, [
                'source' => $source, 'selector' => $selector, 'value' => $value, 'counts' => $plan['counts'],
            ]);

            return $plan;
        });

        $plan['files_removed'] = $plan['paths'] === [] || Storage::disk('local')->delete($plan['paths']);

        return $plan;
    }
}
