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
        Schema::table('Ope_GuiaConfiguraciones', function (Blueprint $table): void {
            $table->string('transport_kind', 10)->nullable();
            $table->unsignedBigInteger('transport_id')->nullable();
            $table->string('group_code', 100)->nullable();
            $table->unsignedSmallInteger('stop_order')->nullable();
            $table->index(['tenant_id', 'group_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Ope_GuiaConfiguraciones', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'group_code']);
            $table->dropColumn(['transport_kind', 'transport_id', 'group_code', 'stop_order']);
        });
    }
};
