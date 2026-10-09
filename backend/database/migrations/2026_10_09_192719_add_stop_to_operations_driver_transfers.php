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
        Schema::table('Ope_RecorridoTraspasos', function (Blueprint $table): void {
            $table->foreignId('stop_id')->nullable()->constrained('Ope_RecorridoParadas')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Ope_RecorridoTraspasos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stop_id');
        });
    }
};
