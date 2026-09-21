<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_courier', function (Blueprint $table): void {
            $table->string('nombre_proceso', 100)->nullable()->after('tipo_pago');
            $table->index(['tenant_id', 'nombre_proceso']);
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_courier', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'nombre_proceso']);
            $table->dropColumn('nombre_proceso');
        });
    }
};
