<?php

use App\Models\CourierPaymentMovement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $names = DB::table('Pago_Movimientos_Courier')
            ->where('nombre_proceso', 'like', '______-%')
            ->select('nombre_proceso')->distinct()->pluck('nombre_proceso');

        foreach ($names as $name) {
            $normalized = CourierPaymentMovement::withoutPeriodPrefix($name);
            if ($normalized !== $name) {
                DB::table('Pago_Movimientos_Courier')->where('nombre_proceso', $name)
                    ->update(['nombre_proceso' => $normalized]);
            }
        }
    }

    public function down(): void
    {
        // No se puede reconstruir qué filas tenían el período duplicado originalmente.
    }
};
