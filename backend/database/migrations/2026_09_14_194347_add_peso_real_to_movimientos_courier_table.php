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
            $table->decimal('peso_real', 10, 3)->nullable()->after('weight_kg');
            $table->index(['tenant_id', 'peso_real']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movimientos_courier', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'peso_real']);
            $table->dropColumn('peso_real');
        });
    }
};
