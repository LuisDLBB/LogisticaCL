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
        Schema::table('movimientos_courier', function (Blueprint $table) {
            $table->string('tipo_pago', 30)->default('Variables')->after('peso_final');
            $table->index(['tenant_id', 'tipo_pago']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movimientos_courier', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'tipo_pago']);
            $table->dropColumn('tipo_pago');
        });
    }
};
