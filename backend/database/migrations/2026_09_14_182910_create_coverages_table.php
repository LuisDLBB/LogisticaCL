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
        Schema::create('coverages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('commune_name', 150);
            $table->string('commune_key', 150);
            $table->string('matrix_commune_name', 150)->nullable();
            $table->string('provider_tax_id', 15)->nullable();
            $table->string('provider_name_source', 255)->nullable();
            $table->string('zone', 20);

            $table->boolean('return_payment_applies')->default(false);
            $table->decimal('return_value', 12, 2)->nullable();
            $table->string('delivery_frequency', 120)->nullable();
            $table->string('delivery_type', 120)->nullable();
            $table->unsignedTinyInteger('region_code')->nullable();
            $table->string('route_code', 100)->nullable();
            $table->unsignedSmallInteger('consideration_code')->nullable();

            $table->string('aerial_commune_name', 150)->nullable();
            $table->string('aerial_route_code', 100)->nullable();
            $table->string('base_commune_name', 150)->nullable();
            $table->string('trunk_name', 160)->nullable();
            $table->string('post_name', 160)->nullable();
            $table->unsignedSmallInteger('trunk_delivery_order')->nullable();

            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'commune_key', 'is_active']);
            $table->index(['tenant_id', 'zone', 'region_code']);
            $table->index(['provider_id', 'is_active']);
            $table->index(['tenant_id', 'route_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coverages');
    }
};
