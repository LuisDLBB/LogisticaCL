<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_branch_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_branch_id')->constrained()->restrictOnDelete();

            $table->string('name', 160);
            $table->string('position', 100)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('mobile_phone', 40)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['client_branch_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_branch_contacts');
    }
};
