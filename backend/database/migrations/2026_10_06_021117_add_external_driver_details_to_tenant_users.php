<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->string('driver_type', 20)->nullable();
            $table->string('external_vehicle_plate', 20)->nullable();
            $table->string('external_vehicle_type', 80)->nullable();
            $table->decimal('external_payload_capacity_kg', 12, 2)->nullable();
            $table->decimal('external_capacity_m3', 12, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->dropColumn(['driver_type', 'external_vehicle_plate', 'external_vehicle_type', 'external_payload_capacity_kg', 'external_capacity_m3']);
        });
    }
};
