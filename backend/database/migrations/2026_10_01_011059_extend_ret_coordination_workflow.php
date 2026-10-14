<?php

use App\Fleet\RetVehicleEligibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ret_license_types', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('description');
            $table->string('source_url');
        });
        foreach (RetVehicleEligibility::CLASSES as $code => $description) {
            DB::table('ret_license_types')->insert(['code' => $code, 'description' => $description, 'source_url' => 'https://www.bcn.cl/leychile/navegar?idNorma=1007469']);
        }
        Schema::table('MBA_vehicles', fn (Blueprint $table) => $table->unsignedInteger('payload_capacity_kg')->nullable());
        Schema::table('service_requests', function (Blueprint $table): void {
            $table->decimal('requested_volume_m3', 12, 3)->nullable();
            $table->decimal('requested_weight_kg', 12, 2)->nullable();
            $table->string('pickup_name')->nullable();
            $table->string('commune_snapshot')->nullable();
            $table->text('access_reference_snapshot')->nullable();
            $table->string('contact_name_snapshot')->nullable();
            $table->string('contact_phone_snapshot')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('is_test')->default(false)->index();
            $table->string('driver_token_hash', 64)->nullable()->unique();
            $table->timestamp('driver_token_expires_at')->nullable();
            $table->string('guide_number')->nullable();
            $table->unsignedInteger('actual_packages')->nullable();
            $table->text('route_notes')->nullable();
        });
        Schema::table('fixed_pickups', function (Blueprint $table): void {
            $table->string('category')->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('is_test')->default(false)->index();
        });
        Schema::create('ret_feasibilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('MBA_tenants');
            $table->foreignId('client_id')->constrained('MBA_clients');
            $table->foreignId('service_type_id')->constrained();
            $table->foreignId('created_by_user_id')->constrained('MBA_users');
            $table->foreignId('service_request_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('status')->default('operations');
            $table->string('operational_result')->nullable();
            $table->boolean('requires_finance');
            $table->boolean('is_test')->default(false);
            $table->json('request_data');
            $table->json('original_request_data');
            $table->json('operations_data')->nullable();
            $table->json('original_operations_data')->nullable();
            $table->json('finance_data')->nullable();
            $table->json('original_finance_data')->nullable();
            $table->decimal('proposed_price', 15, 2)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
        Schema::create('ret_feasibility_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ret_feasibility_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('MBA_users');
            $table->string('action');
            $table->json('details');
            $table->timestamp('created_at');
        });
        Schema::create('ret_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->string('dedupe_key', 64)->unique();
            $table->string('fingerprint', 64);
            $table->text('recipients');
            $table->text('cc')->nullable();
            $table->string('sender');
            $table->string('subject');
            $table->text('body');
            $table->string('status')->default('prepared');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
        Schema::create('ret_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('MBA_tenants');
            $table->text('postventa_cc')->nullable();
            $table->string('sender')->nullable();
            $table->timestamps();
        });
        foreach (DB::table('MBA_tenants')->pluck('id') as $tenantId) {
            DB::table('ret_settings')->insert([
                'tenant_id' => $tenantId, 'postventa_cc' => 'Hansdelabarra@4nlogistica.cl',
                'sender' => 'retiros@4nlogistica.cl', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Schema::table('PPR_client_branches', function (Blueprint $table): void {
            $table->text('postventa_cc')->nullable();
        });
        Schema::create('ret_cleanup_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('MBA_tenants');
            $table->foreignId('actor_user_id')->constrained('MBA_users');
            $table->json('counts');
            $table->json('record_ids');
            $table->timestamp('created_at');
        });
        foreach (['operations.feasibility', 'finance.feasibility.validate', 'commercial.feasibility.approve'] as $code) {
            foreach (DB::table('fleet_profiles')->where('code', 'administrator')->get() as $profile) {
                DB::table('fleet_profile_permissions')->insertOrIgnore([
                    'fleet_profile_id' => $profile->id, 'permission_code' => $code, 'access_level' => 3,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ret_license_types');
        Schema::table('MBA_vehicles', fn (Blueprint $table) => $table->dropColumn('payload_capacity_kg'));
        Schema::table('service_requests', fn (Blueprint $table) => $table->dropColumn(['requested_volume_m3', 'requested_weight_kg']));
        Schema::table('PPR_client_branches', fn (Blueprint $table) => $table->dropColumn('postventa_cc'));
        foreach (['ret_cleanup_audits', 'ret_settings', 'ret_notifications', 'ret_feasibility_events', 'ret_feasibilities'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('fixed_pickups', fn (Blueprint $table) => $table->dropColumn(['category', 'instructions', 'is_test']));
        Schema::table('service_requests', function (Blueprint $table): void {
            $table->dropUnique(['driver_token_hash']);
            $table->dropIndex(['is_test']);
            $table->dropColumn(['pickup_name', 'commune_snapshot', 'access_reference_snapshot', 'contact_name_snapshot', 'contact_phone_snapshot', 'cancellation_reason', 'cancelled_at', 'is_test', 'driver_token_hash', 'driver_token_expires_at', 'guide_number', 'actual_packages', 'route_notes']);
        });
        DB::table('fleet_profile_permissions')->whereIn('permission_code', ['operations.feasibility', 'finance.feasibility.validate', 'commercial.feasibility.approve'])->delete();
    }
};
