<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('VEH_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sha256', 64)->unique();
            $table->string('kind', 40);
            $table->string('title');
            $table->string('filename');
            $table->string('storage_path');
            $table->string('policy_number')->nullable();
            $table->string('insurer')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->json('details');
            $table->timestamps();
            $table->index(['tenant_id', 'kind']);
        });
        Schema::create('VEH_document_vehicles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('VEH_documents')->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->json('details');
            $table->timestamps();
            $table->unique(['document_id', 'vehicle_id']);
            $table->index('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('VEH_document_vehicles');
        Schema::dropIfExists('VEH_documents');
    }
};
