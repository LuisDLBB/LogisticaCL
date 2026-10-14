<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('VEH_weekly_readings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('week_start');
            $table->unsignedInteger('odometer_km')->nullable();
            $table->unsignedInteger('last_maintenance_km')->nullable();
            $table->unsignedInteger('interval_km')->nullable();
            $table->boolean('accepted')->default(false);
            $table->json('issues')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['vehicle_id', 'week_start', 'id'], 'VEH_weekly_vehicle_week');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('VEH_weekly_readings');
    }
};
