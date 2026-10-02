<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'PPR_coverages';

    private const COLUMN = 'ID_ComunaMatrizAgencia';

    public function up(): void
    {
        $source = json_decode(
            file_get_contents(database_path('data/coverage_matrix_agency_20261001.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $existingCount = DB::table(self::TABLE)->count();
        if ($existingCount > 0) {
            $coverages = DB::table(self::TABLE)
                ->whereIn('id', array_column($source, 0))
                ->get(['id', 'tenant_id', 'commune_name', 'route_code'])
                ->keyBy('id');

            if ($coverages->count() !== count($source)) {
                throw new RuntimeException('No coinciden las 598 coberturas del archivo con la base. No se actualizó ningún ID de agencia.');
            }

            foreach ($source as [$id, $tenantId, $communeName, $routeCode, $agencyId]) {
                $coverage = $coverages->get($id);
                if ($coverage === null || (int) $coverage->tenant_id !== $tenantId || $coverage->commune_name !== $communeName || ($coverage->route_code ?? '') !== ($routeCode ?? '')) {
                    throw new RuntimeException("La cobertura {$id} difiere del archivo. No se actualizó ningún ID de agencia.");
                }
            }
        }

        if (! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedSmallInteger(self::COLUMN)->nullable();
            });
        }

        if ($existingCount === 0) {
            return;
        }

        foreach ($source as [$id, , , , $agencyId]) {
            DB::table(self::TABLE)->where('id', $id)->update([self::COLUMN => $agencyId]);
        }
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }
};
