<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table) {
            $table->unsignedBigInteger('valor')->nullable()->after('peso_final');
        });
    }

    public function down(): void
    {
        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table) {
            $table->dropColumn('valor');
        });
    }
};
