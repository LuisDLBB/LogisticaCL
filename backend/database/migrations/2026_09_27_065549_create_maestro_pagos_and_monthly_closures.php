<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Cierres_Pagos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('periodo', 6);
            $table->unsignedInteger('registros');
            $table->unsignedBigInteger('total');
            $table->timestamp('closed_at');
            $table->unique(['tenant_id', 'periodo']);
        });

        Schema::create('Maestro_Pagos', function (Blueprint $table): void {
            $table->string('seguimiento_paquete', 100)->primary();
            $table->unsignedBigInteger('pago_movimiento_id')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('courier_movement_id');
            $table->string('zona', 20)->nullable();
            $table->string('comuna_matriz', 150)->nullable();
            $table->string('tipo_pago', 50);
            $table->string('nombre_proceso', 100);
            $table->string('periodo', 6);
            $table->date('fecha')->nullable();
            $table->text('direccion')->nullable();
            $table->string('comuna_destino', 150)->nullable();
            $table->string('comerciante_pila', 255)->nullable();
            $table->string('rut_cliente', 15)->nullable();
            $table->string('razon_social_cliente', 255)->nullable();
            $table->unsignedInteger('peso_final');
            $table->unsignedBigInteger('valor')->nullable();
            $table->string('estado_envio', 50)->nullable();
            $table->string('condicion_pago', 2);
            $table->string('razon_social_proveedor', 255)->nullable();
            $table->string('rut_proveedor', 15)->nullable();
            $table->string('nombre_operacional', 255)->nullable();
            $table->string('tipo_documento', 100)->nullable();
            $table->string('nombre_repartidor', 160)->nullable();
            $table->string('usuario_entrega', 160)->nullable();
            $table->string('empresa_mandante', 100)->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('provider_id')->nullable();
            $table->unsignedBigInteger('service_type_id')->nullable();
            $table->unsignedSmallInteger('service_code')->nullable();
            $table->string('service_name', 160)->nullable();
            $table->unsignedBigInteger('ruta_cv_id')->nullable();
            $table->unsignedBigInteger('base_servicio_id')->nullable();
            $table->unsignedBigInteger('acuerdo_id')->nullable();
            $table->unsignedBigInteger('apoyo_alza_id')->nullable();
            $table->unsignedBigInteger('visita_diaria_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('closed_at');
            $table->index(['tenant_id', 'periodo']);
        });

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteGuards();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('Maestro_Pagos');
        Schema::dropIfExists('Cierres_Pagos');
    }

    private function createSqliteGuards(): void
    {
        $master = static fn (string $tracking): string => "EXISTS (SELECT 1 FROM Maestro_Pagos WHERE seguimiento_paquete = UPPER(TRIM({$tracking})))";
        $closed = static fn (string $period, string $tenant): string => "EXISTS (SELECT 1 FROM Cierres_Pagos WHERE periodo = {$period} AND tenant_id = {$tenant})";
        $linked = static fn (string $movementId): string => "EXISTS (SELECT 1 FROM Pago_Movimientos_Courier AS pago JOIN Cierres_Pagos AS cierre ON cierre.tenant_id = pago.tenant_id AND cierre.periodo = pago.periodo WHERE pago.courier_movement_id = {$movementId})";
        $guards = [
            ['maestro_movimiento_insert', 'INSERT', 'movimientos_courier', $master('NEW.tracking_number')],
            ['maestro_movimiento_update', 'UPDATE', 'movimientos_courier', $master('OLD.tracking_number').' OR '.$master('NEW.tracking_number').' OR '.$linked('OLD.id')],
            ['maestro_movimiento_delete', 'DELETE', 'movimientos_courier', $master('OLD.tracking_number').' OR '.$linked('OLD.id')],
            ['maestro_pago_insert', 'INSERT', 'Pago_Movimientos_Courier', $master('NEW.seguimiento_paquete').' OR '.$closed('NEW.periodo', 'NEW.tenant_id')],
            ['maestro_pago_update', 'UPDATE', 'Pago_Movimientos_Courier', $master('OLD.seguimiento_paquete').' OR '.$master('NEW.seguimiento_paquete').' OR '.$closed('OLD.periodo', 'OLD.tenant_id').' OR '.$closed('NEW.periodo', 'NEW.tenant_id')],
            ['maestro_pago_delete', 'DELETE', 'Pago_Movimientos_Courier', $master('OLD.seguimiento_paquete').' OR '.$closed('OLD.periodo', 'OLD.tenant_id')],
            ['maestro_final_update', 'UPDATE', 'Maestro_Pagos', '1 = 1'],
            ['maestro_final_delete', 'DELETE', 'Maestro_Pagos', '1 = 1'],
        ];

        foreach ($guards as [$name, $event, $table, $condition]) {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Seguimiento o período cerrado en Maestro_Pagos'); END");
        }
    }
};
