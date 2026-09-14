<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();

            $table->string('tax_id', 15);
            $table->string('tax_id_number', 12);
            $table->char('tax_id_check_digit', 1);
            $table->string('commercial_name', 160);
            $table->string('legal_name', 255);

            $table->string('billing_address', 255)->nullable();
            $table->string('billing_commune_name', 100)->nullable();
            $table->text('business_activity')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'tax_id_number']);
            $table->index(['tenant_id', 'commercial_name']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
