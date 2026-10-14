<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_scheduling_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('MBA_tenants')->restrictOnDelete();
            $table->unsignedInteger('max_concurrent_pickups')->default(3);
            $table->timestamps();
        });

        Schema::table('service_requests', function (Blueprint $table): void {
            $table->foreignId('driver_user_id')->nullable()->constrained('MBA_users')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('MBA_vehicles')->nullOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('MBA_users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->text('email_to_snapshot')->nullable();
            $table->text('email_cc_snapshot')->nullable();
            $table->timestamp('email_prepared_at')->nullable();
            $table->index(['driver_user_id', 'service_date', 'status'], 'service_requests_driver_day_status');
            $table->index(['vehicle_id', 'service_date', 'status'], 'service_requests_vehicle_day_status');
        });

        Schema::create('service_request_overload_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_user_id')->constrained('MBA_users')->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained('MBA_vehicles')->restrictOnDelete();
            $table->date('service_date');
            $table->time('window_start');
            $table->time('window_end');
            $table->unsignedInteger('driver_concurrences');
            $table->unsignedInteger('vehicle_concurrences');
            $table->unsignedInteger('limit_snapshot');
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by_user_id')->constrained('MBA_users')->restrictOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('MBA_users')->nullOnDelete();
            $table->text('decision_notes')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['service_request_id', 'status'], 'overload_request_status');
            $table->index(['status', 'created_at'], 'overload_status_created');
        });

        $now = now();
        foreach (DB::table('MBA_tenants')->pluck('id') as $tenantId) {
            DB::table('fleet_scheduling_settings')->insert([
                'tenant_id' => $tenantId, 'max_concurrent_pickups' => 3,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach (DB::table('fleet_profiles')->get(['id', 'code']) as $profile) {
            DB::table('fleet_profile_permissions')->insertOrIgnore([
                'fleet_profile_id' => $profile->id,
                'permission_code' => 'operations.scheduling.overload.approve',
                'access_level' => $profile->code === 'administrator' ? 3 : 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('fleet_profile_permissions')->where('permission_code', 'operations.scheduling.overload.approve')->delete();
        Schema::dropIfExists('service_request_overload_authorizations');
        Schema::table('service_requests', function (Blueprint $table): void {
            $table->dropIndex('service_requests_driver_day_status');
            $table->dropIndex('service_requests_vehicle_day_status');
            $table->dropConstrainedForeignId('driver_user_id');
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropConstrainedForeignId('assigned_by_user_id');
            $table->dropColumn(['assigned_at', 'email_to_snapshot', 'email_cc_snapshot', 'email_prepared_at']);
        });
        Schema::dropIfExists('fleet_scheduling_settings');
    }
};
