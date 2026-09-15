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
            $table->decimal('weight_kg', 10, 3)->default(1)->change();
            $table->unsignedInteger('peso_real')->nullable()->change();
            $table->unsignedInteger('peso_transformado')->nullable()->change();
            $table->unsignedInteger('peso_final')->nullable()->after('peso_transformado');
            $table->index(['tenant_id', 'peso_final']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movimientos_courier', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'peso_final']);
            $table->dropColumn('peso_final');
            $table->decimal('weight_kg', 10, 3)->nullable()->change();
            $table->decimal('peso_real', 10, 3)->nullable()->change();
            $table->decimal('peso_transformado', 10, 3)->nullable()->change();
        });
    }
};
