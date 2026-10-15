<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('MBA_vehicles', function (Blueprint $table): void {
            $table->text('availability_reason')->nullable();
        });
        Schema::create('VEH_vehicle_status_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('MBA_vehicles')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('MBA_tenants')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('MBA_users')->nullOnDelete();
            $table->string('target_state', 30);
            $table->text('reason');
            $table->json('before');
            $table->json('after');
            $table->json('affected_request_ids');
            $table->timestamps();
            $table->index(['vehicle_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('VEH_vehicle_status_events');
        Schema::table('MBA_vehicles', function (Blueprint $table): void {
            $table->dropColumn('availability_reason');
        });
    }
};
