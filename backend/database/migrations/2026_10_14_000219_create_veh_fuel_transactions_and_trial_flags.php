<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_maintenances', function (Blueprint $table): void {
            $table->boolean('is_test')->default(false)->index();
        });
        if (app()->environment('local')) {
            DB::table('vehicle_maintenances')->whereNotIn('id', DB::table('VEH_maintenance_imports')->select('vehicle_maintenance_id'))->update(['is_test' => true]);
        }
        Schema::create('VEH_fuel_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('MBA_users')->nullOnDelete();
            $table->string('filename', 200);
            $table->unsignedInteger('inserted_count');
            $table->unsignedInteger('duplicate_count');
            $table->boolean('is_test')->default(false);
            $table->timestamps();
        });
        Schema::create('VEH_fuel_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('MBA_tenants')->restrictOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('MBA_vehicles')->nullOnDelete();
            $table->foreignId('import_id')->constrained('VEH_fuel_imports')->restrictOnDelete();
            $table->string('source_key', 64);
            $table->string('payload_hash', 64);
            $table->string('plate', 20);
            $table->date('transaction_date');
            $table->string('transaction_time', 8);
            $table->string('guide', 80);
            $table->string('product', 100);
            $table->string('station', 250);
            $table->string('driver', 180)->nullable();
            $table->decimal('price', 15, 3);
            $table->decimal('volume', 15, 3);
            $table->decimal('amount', 15, 2);
            $table->unsignedInteger('odometer_km')->nullable();
            $table->json('source_data');
            $table->boolean('is_test')->default(false)->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'source_key'], 'VEH_fuel_identity');
            $table->index(['tenant_id', 'transaction_date'], 'VEH_fuel_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('VEH_fuel_transactions');
        Schema::dropIfExists('VEH_fuel_imports');
        Schema::table('vehicle_maintenances', fn (Blueprint $table) => $table->dropColumn('is_test'));
    }
};
