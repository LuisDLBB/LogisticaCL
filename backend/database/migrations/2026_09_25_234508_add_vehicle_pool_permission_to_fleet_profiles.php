<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('fleet_profiles')->get(['id', 'code']) as $profile) {
            $level = match ($profile->code) {
                'administrator' => 3,
                'operations' => 2,
                default => 0,
            };

            DB::table('fleet_profile_permissions')->insertOrIgnore([
                'fleet_profile_id' => $profile->id,
                'permission_code' => 'operations.vehicle_pool',
                'access_level' => $level,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('fleet_profile_permissions')->where('permission_code', 'operations.vehicle_pool')->delete();
    }
};
