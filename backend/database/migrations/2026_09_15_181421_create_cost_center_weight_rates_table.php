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
        Schema::create('cost_center_weight_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('cost_center_code');
            $table->unsignedTinyInteger('final_weight');
            $table->unsignedInteger('value');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['cost_center_code', 'final_weight']);
            $table->index(['cost_center_code', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cost_center_weight_rates');
    }
};
