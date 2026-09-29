<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Visitas_Diarias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('periodo', 6);
            $table->string('nombre_proceso', 40);
            $table->string('visit_key', 64);
            $table->string('agente_original', 255)->nullable();
            $table->string('local', 100);
            $table->string('nombre_local', 255);
            $table->string('direccion', 255);
            $table->string('comuna', 160);
            $table->string('frecuencia', 80);
            $table->string('sla_operador', 100)->nullable();
            $table->string('sla_cliente', 100)->nullable();
            $table->string('sla_local_cd', 100)->nullable();
            $table->string('estatus_origen', 40)->nullable();
            $table->string('razon_social_proveedor_origen', 255)->nullable();
            $table->string('nombre_pila_proveedor_origen', 255)->nullable();
            $table->string('rut_proveedor_origen', 20)->nullable();
            $table->foreignId('provider_id')->nullable()->constrained('providers')->restrictOnDelete();
            $table->string('razon_social_cliente_origen', 255)->nullable();
            $table->string('rut_cliente_origen', 20)->nullable();
            $table->string('comerciante_pila_origen', 255)->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->string('zona', 40)->nullable();
            $table->unsignedInteger('valor_dia');
            $table->json('dias');
            $table->unsignedBigInteger('total_mensual')->default(0);
            $table->string('origen', 20)->default('manual');
            $table->unsignedInteger('fila_origen')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'periodo', 'visit_key'], 'visitas_periodo_key_unique');
            $table->index(['tenant_id', 'periodo', 'provider_id']);
            $table->index(['tenant_id', 'periodo', 'client_id']);
        });

        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table): void {
            $table->foreignId('visita_diaria_id')->nullable()->unique()->constrained('Visitas_Diarias')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('visita_diaria_id');
        });
        Schema::dropIfExists('Visitas_Diarias');
    }
};
