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
        Schema::create('movimientos_courier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_system', 50)->default('Geolize');

            $table->string('tracking_number', 100);
            $table->string('tracking_code', 100)->nullable();
            $table->string('external_code', 100)->nullable();
            $table->string('cost_center', 100)->nullable();
            $table->string('purchase_order', 100)->nullable();
            $table->string('dispatch_guide', 100)->nullable();

            $table->decimal('weight_kg', 10, 3)->nullable();
            $table->decimal('length_cm', 10, 2)->nullable();
            $table->decimal('width_cm', 10, 2)->nullable();
            $table->decimal('height_cm', 10, 2)->nullable();
            $table->string('status', 50)->nullable();
            $table->unsignedSmallInteger('delivery_attempts')->default(0);

            $table->string('merchant_name', 255)->nullable();
            $table->string('service_name', 160)->nullable();
            $table->string('campaign_name', 255)->nullable();
            $table->text('recipient_name')->nullable();
            $table->text('recipient_company_name')->nullable();
            $table->text('recipient_address')->nullable();
            $table->string('destination_commune_name', 150)->nullable();
            $table->text('recipient_phone')->nullable();
            $table->text('recipient_email')->nullable();

            $table->decimal('declared_value', 14, 2)->default(0);
            $table->timestamp('received_at')->nullable();
            $table->date('estimated_delivery_date')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->boolean('merchant_pickup')->default(false);
            $table->string('pickup_warehouse_name', 160)->nullable();
            $table->string('delivery_route_code', 100)->nullable();
            $table->string('courier_name', 160)->nullable();
            $table->text('courier_phone')->nullable();
            $table->string('delivery_user_name', 160)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'tracking_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'destination_commune_name']);
            $table->index(['tenant_id', 'received_at']);
            $table->index(['tenant_id', 'delivered_at']);
            $table->index(['client_id', 'service_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimientos_courier');
    }
};
