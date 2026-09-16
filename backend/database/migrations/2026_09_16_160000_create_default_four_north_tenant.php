<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')->updateOrInsert(
            ['code' => '4N'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => '4 Nortes',
                'timezone' => 'America/Santiago',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('tenants')->where('code', '4N')->delete();
    }
};
