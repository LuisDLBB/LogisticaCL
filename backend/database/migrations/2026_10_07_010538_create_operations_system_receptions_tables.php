<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Ope_RecepcionesSistema', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('MBA_tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('MBA_users')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('MBA_clients')->restrictOnDelete();
            $table->string('document_type', 10);
            $table->string('document_number', 100);
            $table->text('observations')->nullable();
            $table->unsignedSmallInteger('qr_start_position')->nullable();
            $table->unsignedSmallInteger('qr_length')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status', 20)->default('awaiting_photo');
            $table->foreignId('load_id')->nullable()->constrained('Ope_Cargas')->restrictOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('Ope_RecepcionSistemaBultos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reception_id')->constrained('Ope_RecepcionesSistema')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('MBA_users')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('tracking', 100);
            $table->string('raw_code', 500);
            $table->string('scan_source', 10)->default('reader');
            $table->date('scanned_on');
            $table->decimal('weight', 12, 3);
            $table->decimal('height_cm', 10, 2);
            $table->decimal('length_cm', 10, 2);
            $table->decimal('width_cm', 10, 2);
            $table->timestamps();
            $table->unique(['reception_id', 'sequence']);
            $table->unique(['reception_id', 'tracking']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Ope_RecepcionSistemaBultos');
        Schema::dropIfExists('Ope_RecepcionesSistema');
    }
};
