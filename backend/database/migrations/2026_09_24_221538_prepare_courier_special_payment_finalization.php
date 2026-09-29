<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('service_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('service_code')->nullable();
            $table->string('service_name', 160)->nullable();
        });

        Schema::table('courier_special_payments', function (Blueprint $table) {
            $table->string('finalized_tracking_number', 100)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->unique(['tenant_id', 'finalized_tracking_number'], 'special_finalized_tracking_unique');
        });
    }

    public function down(): void
    {
        Schema::table('courier_special_payments', function (Blueprint $table) {
            $table->dropUnique('special_finalized_tracking_unique');
            $table->dropColumn(['finalized_tracking_number', 'finalized_at']);
        });

        Schema::table('Pago_Movimientos_Courier', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_type_id');
            $table->dropConstrainedForeignId('provider_id');
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['service_code', 'service_name']);
        });
    }
};
