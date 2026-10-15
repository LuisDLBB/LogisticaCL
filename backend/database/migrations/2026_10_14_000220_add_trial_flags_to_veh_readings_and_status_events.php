<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['VEH_weekly_readings', 'VEH_vehicle_status_events'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->boolean('is_test')->default(false)->index());
        }
        if (app()->environment('local')) {
            DB::table('VEH_weekly_readings')->whereNull('source_key')->update(['is_test' => true]);
            DB::table('VEH_vehicle_status_events')->where('reason', 'not like', 'Estado informado en planillas base%')->update(['is_test' => true]);
        }
    }

    public function down(): void
    {
        foreach (['VEH_weekly_readings', 'VEH_vehicle_status_events'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('is_test'));
        }
    }
};
