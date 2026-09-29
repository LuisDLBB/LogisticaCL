<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('peso_real', function (Blueprint $table): void {
            $table->unsignedInteger('peso_real')->nullable()->change();
            $table->string('talla', 255)->nullable();
        });
    }

    public function down(): void
    {
        DB::table('peso_real')->whereNull('peso_real')->update(['peso_real' => 0]);
        Schema::table('peso_real', function (Blueprint $table): void {
            $table->dropColumn('talla');
            $table->unsignedInteger('peso_real')->nullable(false)->change();
        });
    }
};
