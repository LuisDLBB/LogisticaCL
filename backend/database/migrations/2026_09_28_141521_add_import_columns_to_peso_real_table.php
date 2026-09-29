<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('peso_real', function (Blueprint $table): void {
            $table->string('cliente_origen', 255)->nullable();
            $table->string('operario', 160)->nullable();
            $table->text('observacion')->nullable();
            $table->string('guia_cliente', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('peso_real', function (Blueprint $table): void {
            $table->dropColumn(['cliente_origen', 'operario', 'observacion', 'guia_cliente']);
        });
    }
};
