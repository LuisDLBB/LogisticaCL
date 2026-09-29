<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Maestro_Pagos', function (Blueprint $table): void {
            $table->string('oc', 10)->nullable();
            $table->index(['tenant_id', 'periodo', 'oc'], 'maestro_pagos_oc_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('Maestro_Pagos', function (Blueprint $table): void {
            $table->dropIndex('maestro_pagos_oc_lookup');
            $table->dropColumn('oc');
        });
    }
};
