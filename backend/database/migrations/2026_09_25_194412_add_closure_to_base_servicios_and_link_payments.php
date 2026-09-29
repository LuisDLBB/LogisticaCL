<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Base_Servicios', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable();
        });

        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table): void {
            $table->foreignId('base_servicio_id')->nullable()->unique()->constrained('Base_Servicios')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('base_servicio_id');
        });

        Schema::table('Base_Servicios', function (Blueprint $table): void {
            $table->dropColumn('closed_at');
        });
    }
};
