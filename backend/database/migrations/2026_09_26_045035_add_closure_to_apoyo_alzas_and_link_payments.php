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
        Schema::table('apoyo_alzas', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable();
        });

        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table) {
            $table->foreignId('apoyo_alza_id')->nullable()->constrained('apoyo_alzas')->restrictOnDelete();
            $table->unique('apoyo_alza_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table) {
            $table->dropUnique(['apoyo_alza_id']);
            $table->dropConstrainedForeignId('apoyo_alza_id');
        });

        Schema::table('apoyo_alzas', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });
    }
};
