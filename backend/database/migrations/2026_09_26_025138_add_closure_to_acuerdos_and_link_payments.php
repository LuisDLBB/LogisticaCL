<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acuerdos', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable();
            $table->string('zona', 20)->nullable();
        });

        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table): void {
            $table->foreignId('acuerdo_id')->nullable()->unique()->constrained('acuerdos')->restrictOnDelete();
            $table->string('empresa_mandante', 100)->nullable()->default('4N')->change();
        });
    }

    public function down(): void
    {
        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('acuerdo_id');
            $table->string('empresa_mandante', 20)->default('4N')->change();
        });

        Schema::table('acuerdos', function (Blueprint $table): void {
            $table->dropColumn(['closed_at', 'zona']);
        });
    }
};
