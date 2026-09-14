<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();

            $table->string('internal_code', 50);
            $table->string('plate', 12);
            $table->string('vehicle_type', 50);
            $table->string('ownership_type', 30);
            $table->string('operational_status', 30)->default('available');

            $table->string('brand', 80)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedSmallInteger('manufacture_year')->nullable();
            $table->string('color', 40)->nullable();
            $table->string('vin', 50)->nullable();

            $table->unsignedInteger('max_weight_kg')->nullable();
            $table->unsignedInteger('max_volume_liters')->nullable();
            $table->unsignedSmallInteger('max_pallets')->nullable();
            $table->unsignedInteger('odometer_km')->nullable();

            $table->date('technical_inspection_expires_at')->nullable();
            $table->date('circulation_permit_expires_at')->nullable();
            $table->date('insurance_expires_at')->nullable();
            $table->date('next_maintenance_at')->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'internal_code']);
            $table->unique(['tenant_id', 'plate']);
            $table->index(['tenant_id', 'operational_status']);
            $table->index(['tenant_id', 'vehicle_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
