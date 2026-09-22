<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Proveedores_usuarios_4N', function (Blueprint $table) {
            $table->id();
            $table->string('RutProveedor', 15);
            $table->string('ComunaMatriz', 150);
            $table->string('NombreRepartidor', 255);
            $table->string('NuevoRutProveedor', 15);
            $table->unique(['RutProveedor', 'ComunaMatriz', 'NombreRepartidor'], 'proveedores_usuarios_4n_identidad');
            $table->index(['RutProveedor', 'ComunaMatriz'], 'proveedores_usuarios_4n_cruce');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Proveedores_usuarios_4N');
    }
};
