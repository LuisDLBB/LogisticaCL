<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('business_activity')->nullable()->after('tax_id');
        });

        $now = now();
        DB::table('tenants')->where('code', '4N')->update([
            'name' => '4N',
            'legal_name' => '4 Nortes Logistica SPA',
            'tax_id' => '77346078-7',
            'business_activity' => 'Logistica',
            'is_active' => true,
            'updated_at' => $now,
        ]);

        DB::table('tenants')->updateOrInsert(
            ['code' => 'PMCB'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'PMCB',
                'legal_name' => 'Transportes y Distribucion PMCB SPA',
                'tax_id' => '77639015-1',
                'business_activity' => 'Transporte de Carga por Carretera',
                'timezone' => 'America/Santiago',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table('tenants')->where('code', 'PMCB')->delete();

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('business_activity');
        });
    }
};
