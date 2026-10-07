<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Ope_Cargas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 30);
            $table->string('filename');
            $table->string('path');
            $table->string('sha256', 64);
            $table->string('sheet', 80);
            $table->json('mapping');
            $table->string('status', 30)->default('completed');
            $table->text('error')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'source_type', 'sha256']);
        });
        Schema::create('Ope_FilasFuente', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('load_id')->constrained('Ope_Cargas')->restrictOnDelete();
            $table->unsignedInteger('line');
            $table->string('tracking', 100)->nullable();
            $table->json('raw');
            $table->json('data');
            $table->json('errors');
            $table->unique(['load_id', 'line']);
            $table->index(['load_id', 'tracking']);
        });
        Schema::create('Ope_Lotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('master_load_id')->constrained('Ope_Cargas')->restrictOnDelete();
            $table->date('operation_date');
            $table->string('name', 160);
            $table->string('status', 30)->default('review');
            $table->timestamps();
            $table->index(['tenant_id', 'operation_date']);
        });
        Schema::create('Ope_LoteFuentes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lot_id')->constrained('Ope_Lotes')->restrictOnDelete();
            $table->foreignId('load_id')->constrained('Ope_Cargas')->restrictOnDelete();
            $table->unique(['lot_id', 'load_id']);
        });
        Schema::create('Ope_Bultos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lot_id')->constrained('Ope_Lotes')->restrictOnDelete();
            $table->string('tracking', 100);
            $table->foreignId('reading_id')->nullable()->constrained('Ope_FilasFuente')->restrictOnDelete();
            $table->decimal('weight', 12, 3)->nullable();
            $table->string('operator', 160)->nullable();
            $table->string('customer_guide', 160)->nullable();
            $table->string('reference', 160)->nullable();
            $table->string('merchant')->nullable();
            $table->string('service', 160)->nullable();
            $table->string('commune', 150)->nullable();
            $table->foreignId('coverage_id')->nullable()->constrained('coverages')->restrictOnDelete();
            $table->json('snapshot');
            $table->boolean('excluded')->default(false);
            $table->timestamps();
            $table->unique(['lot_id', 'tracking']);
        });
        Schema::create('Ope_Incidencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lot_id')->constrained('Ope_Lotes')->restrictOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('Ope_Bultos')->restrictOnDelete();
            $table->string('code', 40);
            $table->text('message');
            $table->json('context');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['lot_id', 'resolved_at']);
        });
        Schema::create('Ope_Ubicaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('address');
            $table->string('commune', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'is_active']);
        });
        Schema::create('Ope_GuiaConfiguraciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('coverage_id')->constrained('coverages')->restrictOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->string('role', 20);
            $table->string('name', 160);
            $table->foreignId('origin_id')->constrained('Ope_Ubicaciones')->restrictOnDelete();
            $table->foreignId('destination_id')->constrained('Ope_Ubicaciones')->restrictOnDelete();
            $table->string('template', 500)->default('{cliente} / {servicio}: {bultos} bultos, {peso} kg');
            $table->boolean('requires_customer_guide')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['coverage_id', 'sequence']);
        });
        Schema::create('Ope_ProgramacionSalidas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lot_id')->constrained('Ope_Lotes')->restrictOnDelete();
            $table->date('departure_date');
            $table->string('name', 160);
            $table->string('role', 20);
            $table->foreignId('origin_id')->constrained('Ope_Ubicaciones')->restrictOnDelete();
            $table->foreignId('destination_id')->constrained('Ope_Ubicaciones')->restrictOnDelete();
            $table->string('plate', 10)->nullable();
            $table->string('driver_name', 160)->nullable();
            $table->string('driver_rut', 15)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 30)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['lot_id', 'status']);
        });
        Schema::create('Ope_SalidaAgencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('departure_id')->constrained('Ope_ProgramacionSalidas')->restrictOnDelete();
            $table->foreignId('configuration_id')->constrained('Ope_GuiaConfiguraciones')->restrictOnDelete();
            $table->json('snapshot');
            $table->unique(['departure_id', 'configuration_id']);
        });
        Schema::create('Ope_BultoTramos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('departure_id')->constrained('Ope_ProgramacionSalidas')->restrictOnDelete();
            $table->foreignId('package_id')->constrained('Ope_Bultos')->restrictOnDelete();
            $table->foreignId('configuration_id')->constrained('Ope_GuiaConfiguraciones')->restrictOnDelete();
            $table->unique(['package_id', 'configuration_id']);
        });
        Schema::create('Ope_Guias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('departure_id')->constrained('Ope_ProgramacionSalidas')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('sha256', 64);
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['departure_id', 'version']);
        });
        Schema::create('Ope_Auditoria', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 100);
            $table->string('entity', 50);
            $table->unsignedBigInteger('entity_id');
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'entity', 'entity_id']);
        });
    }

    public function down(): void
    {
        foreach (['Ope_Auditoria', 'Ope_Guias', 'Ope_BultoTramos', 'Ope_SalidaAgencias', 'Ope_ProgramacionSalidas', 'Ope_GuiaConfiguraciones', 'Ope_Ubicaciones', 'Ope_Incidencias', 'Ope_Bultos', 'Ope_LoteFuentes', 'Ope_Lotes', 'Ope_FilasFuente', 'Ope_Cargas'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
