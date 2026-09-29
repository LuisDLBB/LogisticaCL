<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Maestro_Pagos', function (Blueprint $table): void {
            $table->string('impuesto', 30)->nullable();
            $table->decimal('porcentaje_impuesto', 5, 2)->nullable();
            $table->unsignedBigInteger('valor_impuesto')->nullable();
            $table->unsignedBigInteger('valor_final_total')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('Maestro_Pagos', function (Blueprint $table): void {
            $table->dropColumn(['impuesto', 'porcentaje_impuesto', 'valor_impuesto', 'valor_final_total']);
        });
    }
};
