<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Ope_Reservas', function (Blueprint $table): void {
            $table->timestamp('warehouse_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('Ope_Reservas', function (Blueprint $table): void {
            $table->dropColumn('warehouse_confirmed_at');
        });
    }
};
