<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('VEH_board_priorities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('MBA_users')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('MBA_tenants')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('MBA_vehicles')->cascadeOnDelete();
            $table->string('state', 20);
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->unique(['user_id', 'tenant_id', 'state', 'vehicle_id'], 'veh_board_priority_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('VEH_board_priorities');
    }
};
