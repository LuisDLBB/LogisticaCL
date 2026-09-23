<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $provider = DB::table('providers')->where('tax_id', '77946757-0')->first(['id', 'operational_name']);
        if (! $provider || ! filled($provider->operational_name)) {
            return;
        }

        DB::table('llave_centro_costos')
            ->where('provider_id', $provider->id)
            ->where('provider_tax_id', '77946757-0')
            ->where('agent_name', 'Operador Curacavi')
            ->orderBy('id')
            ->chunkById(100, function ($keys) use ($provider): void {
                foreach ($keys as $key) {
                    DB::table('llave_centro_costos')->where('id', $key->id)->update([
                        'agent_name' => $provider->operational_name,
                        'key_text' => trim($provider->operational_name).$key->merchant_name.$key->service_name,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // La corrección de datos históricos no debe revertir nombres editados posteriormente.
    }
};
