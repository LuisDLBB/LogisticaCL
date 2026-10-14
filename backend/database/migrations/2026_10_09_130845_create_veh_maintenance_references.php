<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('VEH_maintenance_parameters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('MBA_vehicles')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('MBA_tenants')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('MBA_users')->nullOnDelete();
            $table->date('effective_from');
            $table->unsignedInteger('interval_km')->nullable();
            $table->string('source_key', 191)->nullable()->unique();
            $table->text('reason');
            $table->timestamps();
            $table->index(['vehicle_id', 'effective_from', 'id'], 'VEH_parameter_vehicle_date');
        });
        Schema::create('VEH_maintenance_baselines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('MBA_vehicles')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('MBA_tenants')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('MBA_users')->nullOnDelete();
            $table->date('observed_week');
            $table->unsignedInteger('last_maintenance_km')->nullable();
            $table->string('source_key', 191)->unique();
            $table->text('source');
            $table->timestamps();
            $table->index(['vehicle_id', 'observed_week', 'id'], 'VEH_baseline_vehicle_week');
        });
        Schema::table('VEH_weekly_readings', function (Blueprint $table): void {
            $table->string('source_key', 191)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('VEH_weekly_readings', function (Blueprint $table): void {
            $table->dropUnique(['source_key']);
            $table->dropColumn('source_key');
        });
        Schema::dropIfExists('VEH_maintenance_baselines');
        Schema::dropIfExists('VEH_maintenance_parameters');
    }
};
