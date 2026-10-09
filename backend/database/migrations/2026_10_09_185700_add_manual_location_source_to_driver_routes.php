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
        Schema::table('Ope_Recorridos', function (Blueprint $table): void {
            $table->string('start_location_source', 10)->nullable();
        });
        Schema::table('Ope_RecorridoParadas', function (Blueprint $table): void {
            $table->string('arrival_location_source', 10)->nullable();
        });
        Schema::table('Ope_RecorridoDevolucionesBodega', function (Blueprint $table): void {
            $table->string('location_source', 10)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Ope_RecorridoDevolucionesBodega', function (Blueprint $table): void {
            $table->dropColumn('location_source');
        });
        Schema::table('Ope_RecorridoParadas', function (Blueprint $table): void {
            $table->dropColumn('arrival_location_source');
        });
        Schema::table('Ope_Recorridos', function (Blueprint $table): void {
            $table->dropColumn('start_location_source');
        });
    }
};
