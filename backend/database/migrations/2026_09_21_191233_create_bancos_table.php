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
        Schema::create('bancos', function (Blueprint $table) {
            $table->unsignedSmallInteger('id_banco')->primary();
            $table->string('banco', 100)->unique();
            $table->unsignedSmallInteger('codigo_sbif')->unique();
            $table->string('nombre_entidad_financiera', 180);
            $table->string('marcas_productos_asociados', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bancos');
    }
};
