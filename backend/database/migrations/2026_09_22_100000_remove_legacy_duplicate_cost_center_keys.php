<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $legacyGroups = DB::table('llave_centro_costos')
                ->whereNull('client_id')
                ->select(['tenant_id', 'merchant_name', 'service_code'])
                ->distinct()
                ->get();

            foreach ($legacyGroups as $group) {
                $client = DB::table('clients')
                    ->where('tenant_id', $group->tenant_id)
                    ->whereRaw('LOWER(TRIM(source_merchant_name)) = ?', [mb_strtolower(trim((string) $group->merchant_name))])
                    ->first(['id']);

                if (! $client) {
                    continue;
                }

                $hasConfiguredKeys = DB::table('llave_centro_costos')
                    ->where('tenant_id', $group->tenant_id)
                    ->where('client_id', $client->id)
                    ->where('service_code', $group->service_code)
                    ->exists();

                if ($hasConfiguredKeys) {
                    DB::table('llave_centro_costos')
                        ->where('tenant_id', $group->tenant_id)
                        ->whereNull('client_id')
                        ->where('service_code', $group->service_code)
                        ->whereRaw('LOWER(TRIM(merchant_name)) = ?', [mb_strtolower(trim((string) $group->merchant_name))])
                        ->delete();
                }
            }
        });
    }

    public function down(): void
    {
        // Los registros heredados eliminados ya contaban con reemplazos configurados.
    }
};
