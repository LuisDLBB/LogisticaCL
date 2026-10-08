<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Ope_GuiasBsale', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('MBA_tenants')->restrictOnDelete();
            $table->unsignedBigInteger('guide_id');
            $table->unsignedInteger('version');
            $table->string('estado', 20);
            $table->unsignedBigInteger('shipping_id')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('numero', 100)->nullable();
            $table->text('url_pdf')->nullable();
            $table->text('url_publica')->nullable();
            $table->json('respuesta')->nullable();
            $table->foreignId('user_id')->constrained('MBA_users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['guide_id', 'version']);
            $table->index(['tenant_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Ope_GuiasBsale');
    }
};
