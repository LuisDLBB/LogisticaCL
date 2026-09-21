<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->uuid('batch_id');
            $table->string('file_name');
            $table->string('category', 40);
            $table->string('source_key', 500);
            $table->json('source_values');
            $table->unsignedInteger('affected_records');
            $table->text('action');
            $table->string('status', 30)->default('PENDIENTE');
            $table->boolean('exclude_from_import')->default(false);
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'category', 'source_key']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_import_errors');
    }
};
