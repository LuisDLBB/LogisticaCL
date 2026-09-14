<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('tax_id', 15);
            $table->string('tax_id_number', 12);
            $table->char('tax_id_check_digit', 1);
            $table->string('legal_name', 255);
            $table->string('operational_name', 160)->nullable();
            $table->string('operator_type', 20);
            $table->string('tax_document_type', 80)->nullable();
            $table->string('commercial_address', 255)->nullable();
            $table->string('commercial_commune_name', 100)->nullable();
            $table->string('contact_name', 160)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('contact_email', 160)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'tax_id_number']);
            $table->index(['tenant_id', 'operator_type']);
            $table->index(['tenant_id', 'operational_name']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
