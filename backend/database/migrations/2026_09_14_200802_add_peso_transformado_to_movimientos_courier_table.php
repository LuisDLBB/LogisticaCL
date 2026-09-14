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
            $table->decimal('peso_transformado', 10, 3)->nullable()->after('peso_real');
            $table->index(['tenant_id', 'peso_transformado']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movimientos_courier', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'peso_transformado']);
            $table->dropColumn('peso_transformado');
        });
    }
};
