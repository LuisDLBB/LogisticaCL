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

        $coverages = DB::table(self::TABLE)->get(['id', 'tenant_id', 'commune_name', 'route_code'])
            ->groupBy(fn (object $coverage): string => $this->key(
                (int) $coverage->tenant_id,
                $coverage->commune_name,
                $coverage->route_code,
            ));

        $updates = [];
        foreach ($source as [$id, $tenantId, $communeName, $routeCode, $agencyId]) {
            $matches = $coverages->get($this->key($tenantId, $communeName, $routeCode));
            if ($matches?->count() > 1) {
                throw new RuntimeException("La cobertura {$id} coincide con varias filas. No se actualizó ningún ID de agencia.");
            }
            if ($matches?->count() === 1) {
                $updates[] = [$matches->first()->id, $agencyId];
            }
        }

        if ($coverages->isNotEmpty() && $updates === []) {
            throw new RuntimeException('Ninguna cobertura coincide con el archivo. No se actualizó ningún ID de agencia.');
        }

        if (! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedSmallInteger(self::COLUMN)->nullable();
            });
        }

        foreach ($updates as [$id, $agencyId]) {
            DB::table(self::TABLE)->where('id', $id)->update([self::COLUMN => $agencyId]);
        }
    }

    private function key(int $tenantId, string $communeName, ?string $routeCode): string
    {
        return json_encode([$tenantId, $communeName, $routeCode ?? ''], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }
};
