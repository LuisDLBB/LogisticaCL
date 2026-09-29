<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_types')->insertOrIgnore([
            'service_code' => 0,
            'name' => 'Servicio 4N',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('service_types')
            ->where('service_code', 0)
            ->where('name', 'Servicio 4N')
            ->delete();
    }
};
