<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('VEH_maintenance_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_maintenance_id')->unique()->constrained('vehicle_maintenances')->cascadeOnDelete();
            $table->string('source_key', 100)->unique();
            $table->unsignedInteger('source_line');
            $table->date('requested_at');
            $table->date('document_issued_at')->nullable();
            $table->decimal('net_amount', 15, 2)->nullable();
            $table->decimal('vat_amount', 15, 2)->nullable();
            $table->string('billed_company')->nullable();
            $table->json('source_data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('VEH_maintenance_imports');
    }
};
