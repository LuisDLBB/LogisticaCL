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
        Schema::create('Base_Servicios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('periodo', 6);
            $table->string('periodo_origen', 100);
            $table->string('nombre_proceso', 40);
            $table->string('zona', 40);
            $table->string('tipo_pago', 40);
            $table->string('seguimiento_paquete', 100)->nullable();
            $table->date('fecha_carga');
            $table->text('direccion');
            $table->string('numero_destino', 50)->nullable();
            $table->string('depto_destino', 50)->nullable();
            $table->string('comuna_destino', 160);
            $table->string('cliente_origen', 255);
            $table->string('rut_cliente_origen', 20)->nullable();
            $table->string('servicio', 160);
            $table->decimal('peso', 10, 3);
            $table->string('estado_envio', 60);
            $table->unsignedBigInteger('valor_final');
            $table->string('operador', 255);
            $table->string('usuario', 255);
            $table->string('usuario2', 255)->nullable();
            $table->string('transportista', 255);
            $table->string('razon_social_proveedor_origen', 255);
            $table->string('rut_proveedor_origen', 20)->nullable();
            $table->string('empresa', 100);
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->string('rut_cliente', 20)->nullable();
            $table->string('nombre_cliente', 255)->nullable();
            $table->string('razon_social_cliente', 255)->nullable();
            $table->foreignId('provider_id')->nullable()->constrained('providers')->restrictOnDelete();
            $table->string('rut_proveedor', 20)->nullable();
            $table->string('nombre_operacional', 255)->nullable();
            $table->string('razon_social_proveedor', 255)->nullable();
            $table->string('tipo_documento', 100)->nullable();
            $table->string('archivo_origen', 255);
            $table->string('hash_archivo', 64);
            $table->unsignedInteger('fila_origen');
            $table->timestamps();
            $table->unique(['tenant_id', 'hash_archivo', 'fila_origen'], 'base_servicios_archivo_fila_unique');
            $table->index(['tenant_id', 'periodo']);
            $table->index(['tenant_id', 'client_id', 'provider_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Base_Servicios');
    }
};
