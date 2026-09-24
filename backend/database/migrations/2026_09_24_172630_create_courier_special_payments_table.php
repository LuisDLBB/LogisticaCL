<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_special_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('periodo', 18);
            $table->date('fecha');
            $table->string('usuario_ingresa', 160);
            $table->string('autoriza', 160);
            $table->string('agente', 255);
            $table->string('zona_tipo', 40);
            $table->string('codigo_seguimiento', 100)->nullable();
            $table->string('localidad', 160);
            $table->string('cliente', 255)->nullable();
            $table->text('descripcion')->nullable();
            $table->unsignedInteger('monto');
            $table->string('archivo_origen');
            $table->string('hash_archivo', 64);
            $table->unsignedInteger('fila_origen');
            $table->timestamps();
            $table->unique(['tenant_id', 'hash_archivo', 'fila_origen'], 'special_payment_source_row_unique');
            $table->index(['tenant_id', 'periodo']);
            $table->index(['tenant_id', 'codigo_seguimiento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_special_payments');
    }
};
