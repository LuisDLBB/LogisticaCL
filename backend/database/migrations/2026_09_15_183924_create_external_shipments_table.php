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
        Schema::create('envios_externos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('fecha');
            $table->string('tracking_number', 100);
            $table->string('external_order_number', 100)->nullable();
            $table->string('external_courier_name', 100)->default('Blue');
            $table->string('destination_locality_name', 150)->nullable();
            $table->string('delivery_point', 100)->nullable();
            $table->string('client_name_source', 255)->nullable();
            $table->boolean('exclude_provider_payment')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'tracking_number']);
            $table->index(['tenant_id', 'fecha']);
            $table->index(['tenant_id', 'exclude_provider_payment']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('envios_externos');
    }
};
