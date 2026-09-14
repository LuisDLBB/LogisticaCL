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
            $table->date('fecha')->nullable()->after('source_system');
            $table->index(['tenant_id', 'fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movimientos_courier', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'fecha']);
            $table->dropColumn('fecha');
        });
    }
};
