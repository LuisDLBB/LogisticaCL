<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table) {
            $table->index('courier_movement_id', 'pago_courier_movement_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table) {
            $table->dropIndex('pago_courier_movement_id_index');
        });
    }
};
