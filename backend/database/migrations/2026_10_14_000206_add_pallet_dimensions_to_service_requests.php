<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table): void {
            $table->string('pallet_type', 20)->nullable();
            $table->decimal('pallet_height_cm', 8, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table): void {
            $table->dropColumn(['pallet_type', 'pallet_height_cm']);
        });
    }
};
