<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weight_transformations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('source_weight', 100);
            $table->string('comparison_key', 100);
            $table->unsignedInteger('transformed_weight');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'source_weight']);
            $table->index(['tenant_id', 'comparison_key', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weight_transformations');
    }
};
