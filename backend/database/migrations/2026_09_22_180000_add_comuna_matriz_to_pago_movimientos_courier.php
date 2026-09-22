<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table) {
            $table->string('comuna_matriz', 150)->nullable()->after('zona');
        });

        $coverages = DB::table('coverages')->where('is_active', true)
            ->select('tenant_id', 'commune_name', 'matrix_commune_name')->get()
            ->groupBy(fn ($coverage): string => $coverage->tenant_id.'|'.$this->communeKey($coverage->commune_name));
        $communes = DB::table('Pago_Movimientos_Courier')
            ->select('tenant_id', 'comuna_destino')->whereNotNull('comuna_destino')->distinct()->get();
        foreach ($communes as $commune) {
            $matrices = $coverages->get($commune->tenant_id.'|'.$this->communeKey($commune->comuna_destino), collect())
                ->pluck('matrix_commune_name')->filter()->unique();
            if ($matrices->count() === 1) {
                DB::table('Pago_Movimientos_Courier')->where('tenant_id', $commune->tenant_id)
                    ->where('comuna_destino', $commune->comuna_destino)
                    ->update(['comuna_matriz' => $matrices->first()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table) {
            $table->dropColumn('comuna_matriz');
        });
    }

    private function communeKey(string $commune): string
    {
        return Str::of($commune)->squish()->lower()->ascii()->toString();
    }
};
