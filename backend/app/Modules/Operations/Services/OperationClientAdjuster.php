<?php

namespace App\Modules\Operations\Services;

use Illuminate\Support\Facades\DB;

class OperationClientAdjuster
{
    public function preview(int $tenant, int $receptionLoad, int $masterLoad): array
    {
        $reception = DB::table('Ope_Cargas')->where(['id' => $receptionLoad, 'tenant_id' => $tenant, 'source_type' => 'reception', 'status' => 'completed'])->firstOrFail();
        DB::table('Ope_Cargas')->where(['id' => $masterLoad, 'tenant_id' => $tenant, 'source_type' => 'master', 'status' => 'completed'])->firstOrFail();

        $receptionRows = DB::table('Ope_FilasFuente')->where('load_id', $receptionLoad)->orderBy('line')->get(['id', 'line', 'tracking', 'raw', 'data']);
        $masterRows = collect();
        foreach ($receptionRows->pluck('tracking')->filter()->unique()->chunk(500) as $trackings) {
            $masterRows = $masterRows->concat(DB::table('Ope_FilasFuente')
                ->where('load_id', $masterLoad)
                ->whereIn('tracking', $trackings)
                ->get(['tracking', 'data', 'errors']));
        }
        $mastersByTracking = $masterRows->groupBy('tracking');
        $results = [];
        $counts = ['changed' => 0, 'unchanged' => 0, 'missing' => 0, 'ambiguous' => 0, 'invalid' => 0];

        foreach ($receptionRows as $row) {
            $data = json_decode($row->data, true) ?: [];
            $raw = json_decode($row->raw, true) ?: [];
            $current = trim((string) ($data['client_name'] ?? ''));
            if ($current === '' && (json_decode($reception->mapping, true)['profile'] ?? '') === 'legacy') {
                $current = trim((string) ($raw[3] ?? ''));
            }
            $matches = $mastersByTracking->get($row->tracking, collect());
            $merchants = $matches->map(fn ($match): string => trim((string) ((json_decode($match->data, true) ?: [])['merchant'] ?? '')))->unique()->values();
            $merchant = null;

            if ($matches->isEmpty()) {
                $status = 'missing';
            } elseif ($matches->contains(fn ($match): bool => (json_decode($match->errors, true) ?: []) !== []) || $merchants->contains('')) {
                $status = 'invalid';
            } elseif ($merchants->count() !== 1) {
                $status = 'ambiguous';
            } else {
                $merchant = $merchants->first();
                $status = $current === $merchant ? 'unchanged' : 'changed';
            }

            $counts[$status]++;
            $results[] = [
                'id' => $row->id,
                'line' => $row->line,
                'tracking' => $row->tracking,
                'current' => $current,
                'geolize' => $merchant,
                'status' => $status,
                'data' => $data,
            ];
        }

        return ['counts' => $counts, 'rows' => $results];
    }

    public function apply(int $tenant, int $user, int $receptionLoad, int $masterLoad): array
    {
        return DB::transaction(function () use ($tenant, $user, $receptionLoad, $masterLoad): array {
            DB::table('Ope_Cargas')->where(['id' => $receptionLoad, 'tenant_id' => $tenant, 'source_type' => 'reception', 'status' => 'completed'])->lockForUpdate()->firstOrFail();
            $preview = $this->preview($tenant, $receptionLoad, $masterLoad);

            foreach ($preview['rows'] as $row) {
                if ($row['status'] !== 'changed') {
                    continue;
                }

                $data = $row['data'];
                if (! array_key_exists('client_name_original', $data)) {
                    $data['client_name_original'] = $row['current'] ?: null;
                }
                $data['client_name'] = $row['geolize'];
                $data['client_name_source'] = 'geolize';
                $data['client_geolize_load_id'] = $masterLoad;
                DB::table('Ope_FilasFuente')->where(['id' => $row['id'], 'load_id' => $receptionLoad])->update(['data' => OperationAccess::json($data)]);
            }

            OperationAccess::audit($tenant, $user, 'Ajustar clientes con Geolize', 'carga', $receptionLoad, [
                'master_load_id' => $masterLoad,
                'updated' => $preview['counts']['changed'],
                'unresolved' => $preview['counts']['missing'] + $preview['counts']['ambiguous'] + $preview['counts']['invalid'],
            ]);

            return $preview['counts'];
        });
    }
}
