<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table) {
            $table->renameColumn('codigo_seguimiento', 'seguimiento_paquete');
        });
        DB::table('Pago_Movimientos_Courier')->update([
            'seguimiento_paquete' => DB::raw('(SELECT tracking_number FROM movimientos_courier WHERE movimientos_courier.id = Pago_Movimientos_Courier.courier_movement_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table) {
            $table->renameColumn('seguimiento_paquete', 'codigo_seguimiento');
        });
    }
};
