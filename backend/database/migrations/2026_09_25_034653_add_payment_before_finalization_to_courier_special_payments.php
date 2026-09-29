<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_special_payments', function (Blueprint $table) {
            $table->json('payment_before_finalization')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courier_special_payments', function (Blueprint $table) {
            $table->dropColumn('payment_before_finalization');
        });
    }
};
