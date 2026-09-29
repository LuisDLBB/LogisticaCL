<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_branches', function (Blueprint $table): void {
            $table->text('operational_emails')->nullable();
            $table->boolean('operational_email_pending')->default(false);
            $table->string('address_status', 30)->default('ready');
            $table->string('source_street', 255)->nullable();
            $table->string('source_number', 255)->nullable();
        });

        Schema::create('fixed_pickups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('service_type_id')->constrained()->restrictOnDelete();
            $table->string('source_client_name', 255);
            $table->string('source_point_name', 160);
            $table->string('association_status', 30)->default('linked');
            $table->json('weekdays');
            $table->string('frequency_label', 50)->nullable();
            $table->string('shift', 20);
            $table->time('window_start');
            $table->time('window_end');
            $table->string('material', 160);
            $table->unsignedInteger('usual_packages')->nullable();
            $table->text('operational_emails')->nullable();
            $table->boolean('operational_email_pending')->default(false);
            $table->foreignId('usual_driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('usual_vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('source_row')->nullable()->unique();
            $table->timestamps();
            $table->index(['tenant_id', 'is_active']);
            $table->index(['client_id', 'client_branch_id']);
        });

        Schema::create('service_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('ret_code', 32)->nullable()->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('fixed_pickup_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 30)->default('requested');
            $table->date('requested_date_original');
            $table->date('service_date');
            $table->string('shift', 20);
            $table->time('window_start');
            $table->time('window_end');
            $table->unsignedInteger('packages');
            $table->string('material', 160);
            $table->string('client_name_snapshot', 255);
            $table->string('legal_name_snapshot', 255);
            $table->string('point_name_snapshot', 160);
            $table->string('address_snapshot', 255);
            $table->string('effective_address', 255);
            $table->text('operational_emails_snapshot')->nullable();
            $table->text('address_override_reason')->nullable();
            $table->foreignId('address_override_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('address_override_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->string('email_status', 30)->default('not_prepared');
            $table->string('email_subject', 255)->nullable();
            $table->text('email_body')->nullable();
            $table->timestamps();
            $table->unique(['fixed_pickup_id', 'service_date', 'window_start', 'window_end'], 'fixed_pickup_occurrence_unique');
            $table->index(['tenant_id', 'service_date', 'status']);
            $table->index(['client_id', 'service_date']);
            $table->index(['client_branch_id', 'service_date']);
        });

        Schema::create('service_request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50);
            $table->json('details')->nullable();
            $table->timestamp('created_at');
            $table->index(['service_request_id', 'created_at']);
        });

        Schema::create('service_request_reprogrammings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('pending');
            $table->text('reason');
            $table->date('previous_date');
            $table->date('proposed_date');
            $table->time('previous_window_start');
            $table->time('previous_window_end');
            $table->time('proposed_window_start');
            $table->time('proposed_window_end');
            $table->foreignId('previous_driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('proposed_driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('previous_vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('proposed_vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('customer_response')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['service_request_id', 'status']);
        });

        foreach (DB::table('fleet_profiles')->get() as $profile) {
            DB::table('fleet_profile_permissions')->insertOrIgnore([
                'fleet_profile_id' => $profile->id,
                'permission_code' => 'coordination.reprogramming.request',
                'access_level' => match ($profile->code) {
                    'administrator' => 3,
                    'operations' => 2,
                    default => 0,
                },
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('fleet_profile_permissions')->where('permission_code', 'coordination.reprogramming.request')->delete();
        Schema::dropIfExists('service_request_reprogrammings');
        Schema::dropIfExists('service_request_events');
        Schema::dropIfExists('service_requests');
        Schema::dropIfExists('fixed_pickups');
        Schema::table('client_branches', function (Blueprint $table): void {
            $table->dropColumn(['operational_emails', 'operational_email_pending', 'address_status', 'source_street', 'source_number']);
        });
    }
};
