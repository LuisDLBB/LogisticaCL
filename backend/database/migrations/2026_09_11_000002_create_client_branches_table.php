<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();

            $table->string('code', 50);
            $table->string('name', 160);
            $table->string('branch_type', 50)->default('branch');
            $table->string('address', 255);
            $table->string('commune_name', 100);
            $table->string('region_name', 100)->nullable();
            $table->string('location_reference', 255)->nullable();

            $table->string('service_schedule', 255)->nullable();
            $table->text('delivery_instructions')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['client_id', 'code']);
            $table->index(['client_id', 'commune_name']);
            $table->index(['client_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_branches');
    }
};
