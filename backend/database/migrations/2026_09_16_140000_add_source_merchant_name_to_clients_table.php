<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('source_merchant_name', 255)
                ->nullable()
                ->after('tax_id_check_digit');
            $table->index(['tenant_id', 'source_merchant_name']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'source_merchant_name']);
            $table->dropColumn('source_merchant_name');
        });
    }
};
