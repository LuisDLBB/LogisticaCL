<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Ope_Postas', function (Blueprint $table): void {
            $table->string('origin_address')->nullable();
            $table->string('origin_commune', 150)->nullable();
        });

        $tenant = DB::table('MBA_tenants')->where('code', '4N')->value('id');
        if ($tenant === null) {
            return;
        }

        $airports = [
            1 => ['Aeropuerto Andrés Sabella Gálvez', 'Antofagasta'],
            2 => ['Aeropuerto Chacalluta', 'Arica'],
            3 => ['Aeródromo El Loa', 'Calama'],
            4 => ['Aeropuerto Diego Aracena', 'Iquique'],
            5 => ['Aeropuerto Mataveri', 'Isla de Pascua'],
            6 => ['Aeródromo Balmaceda', 'Coyhaique'],
            7 => ['Aeropuerto Presidente Carlos Ibáñez del Campo', 'Punta Arenas'],
        ];
        foreach ($airports as $code => [$address, $commune]) {
            DB::table('Ope_Postas')->where(['tenant_id' => $tenant, 'post_code' => $code])->update([
                'origin_address' => $address,
                'origin_commune' => $commune,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('Ope_Postas', function (Blueprint $table): void {
            $table->dropColumn(['origin_address', 'origin_commune']);
        });
    }
};
