<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Rutas_CV', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable();
        });

        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table): void {
            $table->foreignId('ruta_cv_id')->nullable()->unique()->constrained('Rutas_CV')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ruta_cv_id');
        });

        Schema::table('Rutas_CV', function (Blueprint $table): void {
            $table->dropColumn('closed_at');
        });
    }
};
