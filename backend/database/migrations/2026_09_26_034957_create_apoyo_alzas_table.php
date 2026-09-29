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
        Schema::create('apoyo_alzas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('periodo', 6);
            $table->string('nombre_proceso', 40);
            $table->string('proveedor_origen', 255);
            $table->string('rut_proveedor_origen', 25)->nullable();
            $table->foreignId('provider_id')->nullable()->constrained('providers')->restrictOnDelete();
            $table->string('proceso_base', 20);
            $table->string('servicio_acuerdo', 160)->nullable();
            $table->string('factor', 30);
            $table->decimal('porcentaje', 8, 6)->nullable();
            $table->unsignedBigInteger('monto_dia')->nullable();
            $table->string('empresa_mandante', 100)->nullable();
            $table->string('agencia', 150)->nullable();
            $table->unsignedInteger('registros_base')->default(0);
            $table->unsignedBigInteger('monto_base')->nullable();
            $table->unsignedInteger('dias_base')->nullable();
            $table->unsignedBigInteger('monto_apoyo')->nullable();
            $table->string('estado_calculo', 40)->default('sin_calcular');
            $table->timestamp('calculado_at')->nullable();
            $table->string('archivo_origen', 255)->nullable();
            $table->string('hash_archivo', 64)->nullable();
            $table->unsignedInteger('fila_origen');
            $table->timestamps();
            $table->unique(['tenant_id', 'periodo', 'fila_origen']);
            $table->index(['tenant_id', 'periodo', 'proceso_base']);
            $table->index(['tenant_id', 'periodo', 'estado_calculo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apoyo_alzas');
    }
};
