<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Ope_Reservas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('MBA_tenants')->restrictOnDelete();
            $table->foreignId('source_lot_id')->constrained('Ope_Lotes')->restrictOnDelete();
            $table->foreignId('source_package_id')->unique()->constrained('Ope_Bultos')->restrictOnDelete();
            $table->uuid('batch_id');
            $table->string('route_label', 200);
            $table->string('role', 20);
            $table->string('status', 20)->default('pending');
            $table->foreignId('included_lot_id')->nullable()->constrained('Ope_Lotes')->restrictOnDelete();
            $table->foreignId('included_package_id')->nullable()->unique()->constrained('Ope_Bultos')->restrictOnDelete();
            $table->foreignId('reserved_by')->constrained('MBA_users')->restrictOnDelete();
            $table->foreignId('included_by')->nullable()->constrained('MBA_users')->restrictOnDelete();
            $table->timestamp('included_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Ope_Reservas');
    }
};
