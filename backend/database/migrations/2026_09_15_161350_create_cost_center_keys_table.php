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
        Schema::create('llave_centro_costos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('service_type_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('provider_tax_id', 15)->nullable();
            $table->string('agent_name', 160)->nullable();
            $table->string('client_tax_id', 15)->nullable();
            $table->string('merchant_name', 255)->nullable();
            $table->unsignedSmallInteger('service_code');
            $table->string('service_name', 160)->nullable();
            $table->string('key_code', 80)->nullable();
            $table->text('key_text')->nullable();
            $table->string('payment_status', 20)->default('REVISAR');
            $table->unsignedInteger('cost_center_code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'provider_tax_id', 'client_tax_id', 'service_code']);
            $table->index(['tenant_id', 'payment_status']);
            $table->index(['tenant_id', 'cost_center_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('llave_centro_costos');
    }
};
