<?php

namespace App\Modules\Operations\Services;

use Illuminate\Support\Facades\DB;

class OperationMasterComparator
{
    private const FIELDS = ['commune', 'merchant', 'service', 'recipient', 'address', 'geolize_guide', 'geolize_weight'];

    public function compare(int $tenant, int $load): ?array
    {
        $previous = DB::table('Ope_Cargas')
            ->where('tenant_id', $tenant)
            ->where('source_type', 'master')
            ->where('status', 'completed')
            ->where('id', '<', $load)
            ->orderByDesc('id')
            ->first(['id', 'filename', 'invalid_count']);
        if ($previous === null) {
            return null;
        }

        $duplicateCodes = fn (int $id): array => DB::table('Ope_FilasFuente')
            ->where('load_id', $id)
            ->where('errors', '[]')
            ->whereNotNull('tracking')
            ->groupBy('tracking')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('tracking')
            ->flip()
            ->all();
        $duplicates = $duplicateCodes($load) + $duplicateCodes($previous->id);

        $result = [
            'previous_load_id' => $previous->id,
            'previous_filename' => $previous->filename,
            'new_count' => 0,
            'changed_count' => 0,
            'unchanged_count' => 0,
            'missing_count' => 0,
            'ambiguous_count' => count($duplicates),
            'previous_invalid_count' => $previous->invalid_count,
            'new_samples' => [],
            'changed_samples' => [],
            'missing_samples' => [],
        ];

        $rows = fn (int $id) => DB::table('Ope_FilasFuente')
            ->where('load_id', $id)
            ->where('errors', '[]')
            ->whereNotNull('tracking')
            ->select(['id', 'tracking', 'data']);

        $rows($load)->chunkById(500, function ($chunk) use ($previous, $duplicates, &$result): void {
            $chunk = $chunk->reject(fn ($row): bool => isset($duplicates[$row->tracking]));
            $prior = DB::table('Ope_FilasFuente')
                ->where('load_id', $previous->id)
                ->where('errors', '[]')
                ->whereIn('tracking', $chunk->pluck('tracking')->all())
                ->select(['tracking', 'data'])
                ->get()
                ->keyBy('tracking');

            foreach ($chunk as $row) {
                $old = $prior->get($row->tracking);
                if ($old === null) {
                    $result['new_count']++;
                    if (count($result['new_samples']) < 10) {
                        $result['new_samples'][] = $row->tracking;
                    }

                    continue;
                }

                $before = json_decode($old->data, true) ?: [];
                $after = json_decode($row->data, true) ?: [];
                $changes = [];
                foreach (self::FIELDS as $field) {
                    if (($before[$field] ?? '') !== ($after[$field] ?? '')) {
                        $changes[$field] = [
                            'before' => mb_strimwidth((string) ($before[$field] ?? ''), 0, 120, '…'),
                            'after' => mb_strimwidth((string) ($after[$field] ?? ''), 0, 120, '…'),
                        ];
                    }
                }
                if ($changes === []) {
                    $result['unchanged_count']++;
                } else {
                    $result['changed_count']++;
                    if (count($result['changed_samples']) < 10) {
                        $result['changed_samples'][] = ['tracking' => $row->tracking, 'fields' => $changes];
                    }
                }
            }
        });

        $rows($previous->id)->chunkById(500, function ($chunk) use ($load, $duplicates, &$result): void {
            $chunk = $chunk->reject(fn ($row): bool => isset($duplicates[$row->tracking]));
            $current = DB::table('Ope_FilasFuente')
                ->where('load_id', $load)
                ->where('errors', '[]')
                ->whereIn('tracking', $chunk->pluck('tracking')->all())
                ->pluck('tracking')
                ->flip()
                ->all();
            foreach ($chunk as $row) {
                if (isset($current[$row->tracking])) {
                    continue;
                }
                $result['missing_count']++;
                if (count($result['missing_samples']) < 10) {
                    $result['missing_samples'][] = $row->tracking;
                }
            }
        });

        return $result;
    }
}
