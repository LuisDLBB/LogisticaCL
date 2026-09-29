<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table): void {
            $table->dropUnique('fixed_pickup_occurrence_unique');
            $table->string('fixed_occurrence_key', 120)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table): void {
            $table->dropUnique(['fixed_occurrence_key']);
            $table->dropColumn('fixed_occurrence_key');
            $table->unique(['fixed_pickup_id', 'service_date', 'window_start', 'window_end'], 'fixed_pickup_occurrence_unique');
        });
    }
};
