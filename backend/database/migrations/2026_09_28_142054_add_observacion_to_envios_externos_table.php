<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envios_externos', function (Blueprint $table): void {
            $table->text('observacion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('envios_externos', function (Blueprint $table): void {
            $table->dropColumn('observacion');
        });
    }
};
