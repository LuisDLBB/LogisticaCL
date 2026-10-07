<?php

use Database\Seeders\OperationsTransportSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Ope_Choferes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('MBA_tenants')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('MBA_users')->restrictOnDelete();
            $table->string('rut', 15);
            $table->string('name', 160);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'rut']);
        });

        Schema::create('Ope_Troncales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('MBA_tenants')->restrictOnDelete();
            $table->unsignedSmallInteger('trunk_code');
            $table->string('name', 160);
            $table->string('origin_address');
            $table->string('origin_commune', 150);
            $table->string('destination_address');
            $table->string('destination_commune', 150);
            $table->string('plate', 12)->nullable();
            $table->foreignId('vehicle_id')->nullable()->constrained('MBA_vehicles')->restrictOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('Ope_Choferes')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'trunk_code']);
        });

        Schema::create('Ope_Postas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('MBA_tenants')->restrictOnDelete();
            $table->unsignedSmallInteger('post_code');
            $table->string('name', 160);
            $table->string('plate', 12)->nullable();
            $table->foreignId('vehicle_id')->nullable()->constrained('MBA_vehicles')->restrictOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('Ope_Choferes')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'post_code']);
        });

        Schema::create('Ope_Agencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('MBA_tenants')->restrictOnDelete();
            $table->unsignedSmallInteger('agency_code');
            $table->string('name', 160);
            $table->string('address');
            $table->string('commune', 150);
            $table->foreignId('trunk_id')->constrained('Ope_Troncales')->restrictOnDelete();
            $table->foreignId('post_id')->constrained('Ope_Postas')->restrictOnDelete();
            $table->foreignId('second_post_id')->nullable()->constrained('Ope_Postas')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'agency_code']);
            $table->index(['tenant_id', 'trunk_id']);
        });

        app(OperationsTransportSeeder::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('Ope_Agencias');
        Schema::dropIfExists('Ope_Postas');
        Schema::dropIfExists('Ope_Troncales');
        Schema::dropIfExists('Ope_Choferes');
    }
};
