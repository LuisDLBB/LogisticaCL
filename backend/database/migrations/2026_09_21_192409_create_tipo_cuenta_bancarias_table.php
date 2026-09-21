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
        Schema::create('tipos_cuenta_bancaria', function (Blueprint $table) {
            $table->unsignedTinyInteger('id_tipo_cuenta')->primary();
            $table->string('tipo_cuenta', 80)->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tipos_cuenta_bancaria');
    }
};
