<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $periods = DB::table('Pago_Movimientos_Courier')->where('tipo_pago', 'Retornos')
            ->where('nombre_proceso', 'Retornos')->distinct()->pluck('periodo');
        foreach ($periods as $period) {
            DB::table('Pago_Movimientos_Courier')->where('tipo_pago', 'Retornos')
                ->where('nombre_proceso', 'Retornos')->where('periodo', $period)
                ->update(['nombre_proceso' => $period.'-Retornos']);
        }
    }

    public function down(): void
    {
        // Historical process-name corrections are intentionally retained.
    }
};
