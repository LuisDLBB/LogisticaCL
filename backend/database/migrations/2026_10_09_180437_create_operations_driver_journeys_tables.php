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
        Schema::table('MBA_users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
        });

        Schema::table('Ope_Choferes', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('Ope_Recorridos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('MBA_tenants')->restrictOnDelete();
            $table->foreignId('driver_id')->constrained('Ope_Choferes')->restrictOnDelete();
            $table->date('departure_date');
            $table->string('transport_kind', 10);
            $table->unsignedBigInteger('transport_id');
            $table->string('name', 160);
            $table->string('plate', 12);
            $table->string('status', 20)->default('assigned');
            $table->unsignedInteger('start_odometer')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->decimal('start_latitude', 10, 7)->nullable();
            $table->decimal('start_longitude', 10, 7)->nullable();
            $table->text('vehicle_observation')->nullable();
            $table->boolean('vehicle_no_observations')->default(false);
            $table->unsignedInteger('end_odometer')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'driver_id', 'departure_date']);
        });

        Schema::create('Ope_RecorridoParadas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journey_id')->constrained('Ope_Recorridos')->cascadeOnDelete();
            $table->unsignedBigInteger('departure_id');
            $table->unsignedBigInteger('guide_id');
            $table->unsignedInteger('guide_version');
            $table->unsignedSmallInteger('sequence');
            $table->string('name', 160);
            $table->string('address');
            $table->string('commune', 150);
            $table->json('guide_snapshot');
            $table->string('status', 20)->default('pending');
            $table->timestamp('leg_started_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->decimal('arrival_latitude', 10, 7)->nullable();
            $table->decimal('arrival_longitude', 10, 7)->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->text('observation')->nullable();
            $table->unsignedInteger('return_count')->default(0);
            $table->text('return_observation')->nullable();
            $table->string('signature_type', 20)->nullable();
            $table->timestamps();
            $table->unique(['departure_id', 'guide_version']);
            $table->unique(['journey_id', 'sequence']);
        });

        Schema::create('Ope_RecorridoEvidencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journey_id')->constrained('Ope_Recorridos')->cascadeOnDelete();
            $table->foreignId('stop_id')->nullable()->constrained('Ope_RecorridoParadas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('MBA_users')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('path');
            $table->timestamps();
            $table->index(['journey_id', 'type']);
        });

        Schema::create('Ope_RecorridoTraspasos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('MBA_tenants')->restrictOnDelete();
            $table->foreignId('from_journey_id')->constrained('Ope_Recorridos')->restrictOnDelete();
            $table->foreignId('to_driver_id')->constrained('Ope_Choferes')->restrictOnDelete();
            $table->foreignId('to_journey_id')->nullable()->constrained('Ope_Recorridos')->restrictOnDelete();
            $table->unsignedInteger('package_count');
            $table->uuid('request_key')->nullable()->unique();
            $table->text('observation')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'to_driver_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Ope_RecorridoTraspasos');
        Schema::dropIfExists('Ope_RecorridoEvidencias');
        Schema::dropIfExists('Ope_RecorridoParadas');
        Schema::dropIfExists('Ope_Recorridos');
        Schema::table('Ope_Choferes', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'user_id']);
        });
        Schema::table('MBA_users', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->change();
        });
    }
};
