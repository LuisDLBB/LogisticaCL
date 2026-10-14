<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::table('fleet_profiles')->insertOrIgnore([
                'tenant_id' => $tenantId, 'code' => 'app_conductor', 'name' => 'App conductor',
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Assigned profiles must remain available to avoid orphaning user access settings.
        $profiles = DB::table('fleet_profiles')->where('code', 'app_conductor')->get();
        foreach ($profiles as $profile) {
            if (! DB::table('tenant_users')->where('tenant_id', $profile->tenant_id)->where('role_code', $profile->code)->exists()) {
                DB::table('fleet_profiles')->where('id', $profile->id)->delete();
            }
        }
    }
};
