<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Pago_Movimientos_Courier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('courier_movement_id')->constrained('movimientos_courier')->cascadeOnDelete();
            $table->string('zona', 20)->nullable();
            $table->string('tipo_pago', 50);
            $table->string('nombre_proceso', 100);
            $table->string('periodo', 6);
            $table->string('codigo_seguimiento', 100)->nullable();
            $table->date('fecha')->nullable();
            $table->text('direccion')->nullable();
            $table->string('comuna_destino', 150)->nullable();
            $table->string('comerciante_pila', 255)->nullable();
            $table->string('rut_cliente', 15)->nullable();
            $table->string('razon_social_cliente', 255)->nullable();
            $table->unsignedInteger('peso_final');
            $table->string('estado_envio', 50)->nullable();
            $table->string('razon_social_proveedor', 255)->nullable();
            $table->string('rut_proveedor', 15)->nullable();
            $table->string('nombre_operacional', 255)->nullable();
            $table->string('tipo_documento', 100)->nullable();
            $table->string('nombre_repartidor', 160)->nullable();
            $table->string('usuario_entrega', 160)->nullable();
            $table->string('empresa_mandante', 20)->default('4N');
            $table->timestamps();
            $table->unique(['tenant_id', 'courier_movement_id']);
            $table->index(['tenant_id', 'periodo', 'tipo_pago']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Pago_Movimientos_Courier');
    }
};
