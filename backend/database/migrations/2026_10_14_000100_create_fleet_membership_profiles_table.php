<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_membership_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_user_id')->unique()->constrained('MBA_tenant_users')->cascadeOnDelete();
            $table->foreignId('fleet_profile_id')->constrained('fleet_profiles')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $profiles = DB::table('fleet_profiles')->get()->keyBy(
            fn (object $profile): string => $profile->tenant_id.':'.$profile->code,
        );
        $memberships = DB::table('MBA_tenant_users as memberships')
            ->join('MBA_users as users', 'users.id', '=', 'memberships.user_id')
            ->get(['memberships.id', 'memberships.tenant_id', 'memberships.role_code', 'users.profile_name']);

        foreach ($memberships as $membership) {
            $role = Str::slug((string) $membership->role_code, '_');
            $profileName = Str::slug((string) $membership->profile_name, '_');
            $profileCode = match (true) {
                in_array($role, ['administrator', 'administrador', 'admin'], true)
                    || in_array($profileName, ['administrator', 'administrador', 'admin'], true) => 'administrator',
                in_array($role, ['management', 'gerencia', 'gerente'], true)
                    || in_array($profileName, ['management', 'gerencia', 'gerente'], true) => 'management',
                in_array($role, ['finance', 'finanzas'], true)
                    || in_array($profileName, ['finance', 'finanzas'], true) => 'finance',
                in_array($role, ['operations', 'operaciones', 'supervisor', 'supervisor_operaciones'], true)
                    || in_array($profileName, ['operations', 'operaciones', 'supervisor', 'supervisor_operaciones'], true) => 'operations',
                in_array($role, ['after_sales', 'postventa', 'post_venta', 'coordinacion'], true)
                    || in_array($profileName, ['after_sales', 'postventa', 'post_venta', 'coordinacion'], true) => 'after_sales',
                in_array($role, ['commercial', 'comercial'], true)
                    || in_array($profileName, ['commercial', 'comercial'], true) => 'commercial',
                in_array($role, ['read_only', 'solo_lectura'], true) => 'read_only',
                default => null,
            };

            $profile = $profileCode === null ? null : $profiles->get($membership->tenant_id.':'.$profileCode);
            if ($profile !== null) {
                DB::table('fleet_membership_profiles')->insert([
                    'tenant_user_id' => $membership->id,
                    'fleet_profile_id' => $profile->id,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_membership_profiles');
    }
};
