<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('tax_id', 15)->nullable()->index();
            $table->string('username', 100)->nullable()->index();
            $table->string('area', 100)->nullable();
            $table->string('profile_name', 100)->nullable();
            $table->string('license_type', 80)->nullable();
            $table->string('locality_name', 100)->nullable();
            $table->date('driver_license_expires_at')->nullable();
            $table->string('phone', 40)->nullable();
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->text('document_link')->nullable();
            $table->string('company_source', 50)->nullable();
            $table->string('destination_name', 120)->nullable();
            $table->date('gas_certificate_expires_at')->nullable();
            $table->decimal('capacity_m3', 12, 3)->nullable();
            $table->decimal('capacity_m2', 12, 3)->nullable();
            $table->decimal('height_cm', 10, 2)->nullable();
            $table->decimal('length_cm', 10, 2)->nullable();
            $table->decimal('width_cm', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['tax_id', 'username', 'area', 'profile_name', 'license_type', 'locality_name', 'driver_license_expires_at', 'phone']);
        });
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['document_link', 'company_source', 'destination_name', 'gas_certificate_expires_at', 'capacity_m3', 'capacity_m2', 'height_cm', 'length_cm', 'width_cm']);
        });
    }
};
