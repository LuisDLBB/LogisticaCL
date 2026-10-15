<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_scheduling_settings', function (Blueprint $table): void {
            $table->time('pickup_cutoff_time')->default('12:30:00');
            $table->unsignedInteger('pickup_authorization_revision')->default(1);
        });
        Schema::create('fleet_pickup_approvers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('MBA_tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('MBA_users')->restrictOnDelete();
            $table->string('area', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'area']);
        });
        foreach (DB::table('MBA_tenants')->get() as $tenant) {
            DB::table('fleet_scheduling_settings')->insertOrIgnore(['tenant_id' => $tenant->id, 'max_concurrent_pickups' => 3, 'created_at' => now(), 'updated_at' => now()]);
            $members = DB::table('MBA_tenant_users')->join('MBA_users', 'MBA_users.id', '=', 'MBA_tenant_users.user_id')
                ->join('fleet_membership_profiles', 'fleet_membership_profiles.tenant_user_id', '=', 'MBA_tenant_users.id')
                ->join('fleet_profiles', 'fleet_profiles.id', '=', 'fleet_membership_profiles.fleet_profile_id')
                ->where('MBA_tenant_users.tenant_id', $tenant->id)->where('MBA_tenant_users.is_active', true)
                ->where('fleet_membership_profiles.is_active', true)->where('fleet_profiles.is_active', true)
                ->get(['MBA_users.id', 'MBA_users.email', 'fleet_profiles.code as role_code']);
            foreach ($members as $member) {
                $areas = [];
                if (in_array($member->role_code, ['operations', 'operaciones'], true)) {
                    $areas[] = 'operations';
                }
                if (mb_strtolower(trim($member->email)) === 'hansdelabarra@4nlogistica.cl') {
                    $areas[] = 'commercial';
                }
                foreach ($areas as $area) {
                    DB::table('fleet_pickup_approvers')->insert(['tenant_id' => $tenant->id, 'user_id' => $member->id, 'area' => $area, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_pickup_approvers');
        Schema::table('fleet_scheduling_settings', function (Blueprint $table): void {
            $table->dropColumn(['pickup_cutoff_time', 'pickup_authorization_revision']);
        });
    }
};
