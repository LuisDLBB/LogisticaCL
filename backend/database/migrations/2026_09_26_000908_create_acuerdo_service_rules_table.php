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
        Schema::create('acuerdo_service_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('periodo', 6);
            $table->string('servicio', 160);
            $table->string('modo', 20);
            $table->json('dias_semana')->nullable();
            $table->unsignedSmallInteger('cantidad_fija')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'periodo', 'servicio'], 'acuerdo_regla_periodo_servicio_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acuerdo_service_rules');
    }
};
