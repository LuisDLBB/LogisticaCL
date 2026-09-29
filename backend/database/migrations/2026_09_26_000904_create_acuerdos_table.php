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
        Schema::create('acuerdos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('periodo', 6);
            $table->string('nombre_proceso', 40);
            $table->unsignedBigInteger('source_agreement_id')->nullable();
            $table->string('proveedor_origen', 255);
            $table->string('rut_proveedor_origen', 25)->nullable();
            $table->foreignId('provider_id')->nullable()->constrained('providers')->restrictOnDelete();
            $table->string('agencia', 100)->nullable();
            $table->string('tipo_servicio', 50)->nullable();
            $table->string('marca', 255)->nullable();
            $table->string('servicio', 160);
            $table->unsignedBigInteger('costo');
            $table->unsignedSmallInteger('dias_calendario')->default(0);
            $table->unsignedSmallInteger('inasistencias')->default(0);
            $table->unsignedSmallInteger('adicionales')->default(0);
            $table->unsignedSmallInteger('cantidad')->default(0);
            $table->string('glosa_factor', 255)->nullable();
            $table->unsignedSmallInteger('factor')->default(1);
            $table->unsignedBigInteger('total')->default(0);
            $table->string('razon_social_cliente_origen', 255)->nullable();
            $table->string('comerciante_pila_origen', 255)->nullable();
            $table->string('rut_cliente_origen', 25)->nullable();
            $table->string('nombre_comercial_origen', 255)->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->string('empresa_mandante', 100)->nullable();
            $table->string('archivo_origen', 255)->nullable();
            $table->string('hash_archivo', 64)->nullable();
            $table->unsignedInteger('fila_origen')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'periodo']);
            $table->index(['tenant_id', 'periodo', 'servicio']);
            $table->index(['tenant_id', 'periodo', 'provider_id']);
            $table->index(['tenant_id', 'periodo', 'client_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acuerdos');
    }
};
