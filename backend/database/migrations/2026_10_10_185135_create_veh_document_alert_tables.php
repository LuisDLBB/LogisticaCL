<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('VEH_document_alert_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('warning_days')->default(7);
            $table->json('recipients');
            $table->timestamps();
        });
        Schema::create('VEH_document_alert_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('document_field', 60);
            $table->date('expires_at');
            $table->string('recipient', 254);
            $table->timestamp('sent_at');
            $table->unique(['tenant_id', 'vehicle_id', 'document_field', 'expires_at', 'recipient'], 'veh_document_delivery_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('VEH_document_alert_deliveries');
        Schema::dropIfExists('VEH_document_alert_settings');
    }
};
