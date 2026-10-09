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
        Schema::create('Ope_RecorridoDevolucionesBodega', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('MBA_tenants')->restrictOnDelete();
            $table->foreignId('journey_id')->constrained('Ope_Recorridos')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('MBA_users')->restrictOnDelete();
            $table->unsignedInteger('package_count');
            $table->string('photo_path');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->text('observation')->nullable();
            $table->timestamp('recorded_at');
            $table->uuid('request_key')->nullable()->unique();
            $table->timestamps();
            $table->index(['tenant_id', 'journey_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('Ope_RecorridoDevolucionesBodega');
    }
};
