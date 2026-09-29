<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_maintenance_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('warning_days')->default(30);
            $table->unsignedInteger('warning_km')->default(1000);
            $table->timestamps();
        });

        Schema::create('vehicle_maintenances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('maintenance_type', 100);
            $table->string('execution_type', 20);
            $table->string('status', 30)->default('pending');
            $table->dateTime('scheduled_at')->nullable();
            $table->unsignedInteger('reported_odometer_km')->nullable();
            $table->foreignId('provider_id')->nullable()->constrained('providers')->restrictOnDelete();
            $table->decimal('estimated_cost', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->date('next_due_at')->nullable();
            $table->unsignedInteger('next_due_km')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->unsignedInteger('closed_odometer_km')->nullable();
            $table->decimal('actual_cost', 14, 2)->nullable();
            $table->string('document_type', 30)->nullable();
            $table->string('document_number', 100)->nullable();
            $table->text('closing_notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'scheduled_at']);
            $table->index(['vehicle_id', 'status']);
        });

        Schema::create('vehicle_maintenance_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_maintenance_id')->constrained('vehicle_maintenances')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('previous_status', 30)->nullable();
            $table->string('new_status', 30)->nullable();
            $table->unsignedInteger('odometer_km')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at');
            $table->index(['vehicle_maintenance_id', 'created_at']);
        });

        Schema::create('fleet_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->string('documentable_type', 40);
            $table->unsignedBigInteger('documentable_id');
            $table->string('kind', 40);
            $table->string('disk', 30);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'documentable_type', 'documentable_id'], 'fleet_documents_subject_index');
        });

        $now = now();
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::table('fleet_maintenance_settings')->insert([
                'tenant_id' => $tenantId, 'warning_days' => 30, 'warning_km' => 1000,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (DB::table('fleet_profiles')->get(['id', 'code']) as $profile) {
            foreach (['operations.maintenance.close', 'operations.maintenance.correct'] as $permission) {
                $level = match (true) {
                    $profile->code === 'administrator' => 3,
                    $profile->code === 'operations' && $permission === 'operations.maintenance.close' => 2,
                    default => 0,
                };
                DB::table('fleet_profile_permissions')->insert([
                    'fleet_profile_id' => $profile->id,
                    'permission_code' => $permission,
                    'access_level' => $level,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_documents');
        Schema::dropIfExists('vehicle_maintenance_events');
        Schema::dropIfExists('vehicle_maintenances');
        Schema::dropIfExists('fleet_maintenance_settings');
    }
};
