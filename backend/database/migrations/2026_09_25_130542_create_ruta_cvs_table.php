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
        Schema::create('Rutas_CV', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('periodo', 6);
            $table->string('route_key', 64);
            $table->string('proceso', 40)->default('Ruta CV');
            $table->string('zona', 40);
            $table->string('servicio', 100)->default('Ruta CV');
            $table->string('frecuencia', 80);
            $table->string('facturador', 255);
            $table->string('usuario', 255);
            $table->string('detalle_ruta', 255);
            $table->string('comuna', 160);
            $table->string('producto', 255)->nullable();
            $table->unsignedInteger('valor');
            $table->string('agente', 160)->nullable();
            $table->foreignId('provider_id')->nullable()->constrained('providers')->restrictOnDelete();
            $table->string('rut_proveedor', 20)->nullable();
            $table->string('razon_social_proveedor', 255)->nullable();
            $table->string('nombre_pila_proveedor', 255)->nullable();
            $table->json('dias');
            $table->unsignedTinyInteger('inasistencia')->default(0);
            $table->string('tipo_cobro', 12)->default('diario');
            $table->unsignedInteger('monto_fijo')->nullable();
            $table->unsignedBigInteger('total_mensual')->default(0);
            $table->text('observacion')->nullable();
            $table->string('origen', 16)->default('manual');
            $table->unsignedInteger('fila_origen')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'periodo', 'route_key'], 'rutas_cv_periodo_ruta_unique');
            $table->index(['tenant_id', 'periodo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Rutas_CV');
    }
};
