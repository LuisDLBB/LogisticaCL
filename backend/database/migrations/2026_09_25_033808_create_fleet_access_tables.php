<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('fleet_profile_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fleet_profile_id')->constrained('fleet_profiles')->cascadeOnDelete();
            $table->string('permission_code', 100);
            $table->unsignedTinyInteger('access_level')->default(0);
            $table->timestamps();
            $table->unique(['fleet_profile_id', 'permission_code']);
        });

        Schema::create('fleet_user_permission_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_user_id')->constrained('tenant_users')->cascadeOnDelete();
            $table->string('permission_code', 100);
            $table->unsignedTinyInteger('access_level');
            $table->timestamps();
            $table->unique(['tenant_user_id', 'permission_code']);
        });

        Schema::create('fleet_permission_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->json('previous_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'created_at']);
        });

        $profiles = [
            'administrator' => 'Administrador',
            'management' => 'Gerencia',
            'finance' => 'Finanzas',
            'operations' => 'Operaciones',
            'after_sales' => 'Postventa / Coordinación',
            'commercial' => 'Comercial',
            'read_only' => 'Solo lectura',
        ];
        $scopes = [
            'admin.area', 'admin.users', 'admin.permissions', 'admin.masters', 'admin.procedures',
            'management.area', 'management.executive', 'management.consolidated',
            'coordination.area', 'coordination.requests', 'coordination.fixed-pickups', 'coordination.feasibility', 'coordination.reprogramming.approve',
            'operations.area', 'operations.fleet', 'operations.maintenance', 'operations.scheduling', 'operations.fuel', 'operations.mail', 'operations.tag',
            'commercial.area', 'commercial.feasibility', 'finance.feasibility',
        ];
        $defaults = [
            'management' => ['management.area' => 1, 'management.executive' => 1, 'management.consolidated' => 1],
            'finance' => ['coordination.area' => 1, 'coordination.feasibility' => 1, 'finance.feasibility' => 2],
            'operations' => ['operations.area' => 2, 'operations.fleet' => 2, 'operations.maintenance' => 2, 'operations.scheduling' => 2, 'operations.fuel' => 2, 'operations.mail' => 2, 'operations.tag' => 2, 'coordination.requests' => 2, 'coordination.fixed-pickups' => 2, 'coordination.feasibility' => 2],
            'after_sales' => ['coordination.area' => 2, 'coordination.requests' => 2, 'coordination.fixed-pickups' => 2, 'coordination.feasibility' => 2, 'coordination.reprogramming.approve' => 3],
            'commercial' => ['commercial.area' => 2, 'commercial.feasibility' => 2, 'coordination.requests' => 2],
            'read_only' => ['management.area' => 1, 'management.executive' => 1, 'coordination.area' => 1, 'coordination.requests' => 1, 'coordination.fixed-pickups' => 1, 'coordination.feasibility' => 1, 'operations.area' => 1, 'operations.fleet' => 1, 'operations.maintenance' => 1, 'operations.scheduling' => 1, 'operations.fuel' => 1, 'operations.mail' => 1, 'operations.tag' => 1, 'commercial.area' => 1, 'commercial.feasibility' => 1],
        ];
        $now = now();
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            foreach ($profiles as $code => $name) {
                $profileId = DB::table('fleet_profiles')->insertGetId([
                    'tenant_id' => $tenantId, 'code' => $code, 'name' => $name,
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($scopes as $scope) {
                    $level = $code === 'administrator' ? 3 : ($defaults[$code][$scope] ?? 0);
                    DB::table('fleet_profile_permissions')->insert([
                        'fleet_profile_id' => $profileId, 'permission_code' => $scope,
                        'access_level' => $level, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_permission_audits');
        Schema::dropIfExists('fleet_user_permission_overrides');
        Schema::dropIfExists('fleet_profile_permissions');
        Schema::dropIfExists('fleet_profiles');
    }
};
