<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

class FleetTestMembership
{
    public static function insert(array $attributes): bool
    {
        self::insertGetId($attributes);

        return true;
    }

    public static function insertGetId(array $attributes): int
    {
        $id = DB::table('MBA_tenant_users')->insertGetId($attributes);
        $role = $attributes['role_code'];
        $profile = match ($role) {
            'driver', 'conductor', 'conductor_a5' => 'app_conductor',
            'postventa' => 'after_sales',
            'operaciones' => 'operations',
            'gerencia' => 'management',
            'finanzas' => 'finance',
            'comercial' => 'commercial',
            default => $role,
        };
        $profileId = DB::table('fleet_profiles')
            ->where('tenant_id', $attributes['tenant_id'])->where('code', $profile)->value('id');
        if ($profileId !== null) {
            DB::table('fleet_membership_profiles')->insert([
                'tenant_user_id' => $id, 'fleet_profile_id' => $profileId,
                'is_active' => $attributes['is_active'] ?? true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }
}
