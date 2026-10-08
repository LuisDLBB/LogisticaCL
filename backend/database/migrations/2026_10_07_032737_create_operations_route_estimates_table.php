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
        Schema::create('Ope_RouteEstimates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('MBA_tenants')->restrictOnDelete();
            $table->foreignId('agency_id')->constrained('Ope_Agencias')->cascadeOnDelete();
            $table->string('segment', 32);
            $table->string('route_hash', 64);
            $table->decimal('distance_km', 10, 1);
            $table->unsignedInteger('duration_minutes');
            $table->string('source', 32);
            $table->timestamps();
            $table->unique(['tenant_id', 'agency_id', 'segment']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Ope_RouteEstimates');
    }
};
