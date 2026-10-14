<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ret_coordination_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users');
            $table->string('kind');
            $table->string('status')->default('pending');
            $table->string('previous_status');
            $table->string('reason_code')->nullable();
            $table->text('reason');
            $table->date('proposed_date')->nullable();
            $table->time('proposed_start')->nullable();
            $table->time('proposed_end')->nullable();
            $table->text('customer_response')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['assigned_to_user_id', 'status']);
            $table->index(['service_request_id', 'status']);
        });
        Schema::create('ret_customer_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('subject');
            $table->text('html');
            $table->text('recipients')->nullable();
            $table->text('cc')->nullable();
            $table->string('reply_to')->nullable();
            $table->string('status')->default('prepared');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['service_request_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ret_customer_notifications');
        Schema::dropIfExists('ret_coordination_tasks');
    }
};
