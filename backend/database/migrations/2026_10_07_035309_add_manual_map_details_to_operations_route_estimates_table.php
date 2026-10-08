<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Ope_RouteEstimates', function (Blueprint $table): void {
            $table->unsignedInteger('duration_minutes')->nullable()->change();
            $table->text('maps_url')->nullable();
        });

        $tenant = DB::table('MBA_tenants')->where('code', '4N')->value('id');
        if ($tenant === null) {
            return;
        }

        DB::table('Ope_Postas')->where([
            'tenant_id' => $tenant,
            'post_code' => 1,
            'origin_address' => 'Aeropuerto Andrés Sabella Gálvez',
        ])->update(['origin_address' => 'Camino a Mejillones S/N (Aeropuerto)', 'updated_at' => now()]);

        $anto = DB::table('Ope_Agencias as agency')
            ->join('Ope_Troncales as trunk', 'trunk.id', '=', 'agency.trunk_id')
            ->join('Ope_Postas as post', 'post.id', '=', 'agency.post_id')
            ->where('agency.tenant_id', $tenant)->where('agency.agency_code', 1)
            ->select('agency.id', 'agency.address', 'agency.commune', 'trunk.origin_address as trunk_origin_address',
                'trunk.origin_commune as trunk_origin_commune', 'trunk.destination_address as trunk_destination_address',
                'trunk.destination_commune as trunk_destination_commune', 'post.origin_address as post_origin_address',
                'post.origin_commune as post_origin_commune')
            ->first();
        if ($anto === null) {
            return;
        }

        $trunkOrigin = $this->address($anto->trunk_origin_address, $anto->trunk_origin_commune);
        $trunkDestination = $this->address($anto->trunk_destination_address, $anto->trunk_destination_commune);
        if ($trunkOrigin === 'Galvarino 8481, Bodega 17, Quilicura'
            && $trunkDestination === 'Av. Armando Cortínez Ote. 1704, Pudahuel') {
            $this->saveInitialEstimate((int) $tenant, (int) $anto->id, 'troncal', $trunkOrigin, $trunkDestination, 18.6,
                'https://www.google.cl/maps/dir/Galvarino+8481,+Quilicura,+Regi%C3%B3n+Metropolitana/Aeropuerto+de+Santiago+-+Av.+Armando+Cort%C3%ADnez+Ote.+1704,+Pudahuel,+Regi%C3%B3n+Metropolitana/@-33.3827849,-70.7830032,13z/am=t/data=!4m14!4m13!1m5!1m1!1s0x9662c7475857f475:0xe9d0a4d88e964a2d!2m2!1d-70.7002455!2d-33.3436956!1m5!1m1!1s0x9662c1ec3efd9ad9:0x562f19a86f795461!2m2!1d-70.7936148!2d-33.3969306!3e0');
        }

        $postOrigin = $this->address($anto->post_origin_address, $anto->post_origin_commune);
        $postDestination = $this->address($anto->address, $anto->commune);
        if ($postOrigin === 'Camino a Mejillones S/N (Aeropuerto), Antofagasta'
            && $postDestination === 'Manutara 1090, Antofagasta') {
            $this->saveInitialEstimate((int) $tenant, (int) $anto->id, 'posta1', $postOrigin, $postDestination, 25.9,
                'https://www.google.cl/maps/dir/Aeropuerto+Andr%C3%A9s+Sabella+G%C3%A1lvez+de+Antofagasta+-+Camino+a+mejillones+s%2Fnumero,+Antofagasta/Manutara+1090,+1242831+Antofagasta/@-23.5453433,-70.4905693,12z/am=t/data=!4m14!4m13!1m5!1m1!1s0x96b1d81d3ecf9d17:0xcab322fba8b0ccf9!2m2!1d-70.4409794!2d-23.448653!1m5!1m1!1s0x96afd4df8b0d08e1:0xb203d4f916fd289!2m2!1d-70.3834135!2d-23.6423376!3e0');
        }
    }

    public function down(): void
    {
        DB::table('Ope_RouteEstimates')->whereNull('duration_minutes')->update(['duration_minutes' => 0]);
        Schema::table('Ope_RouteEstimates', function (Blueprint $table): void {
            $table->dropColumn('maps_url');
            $table->unsignedInteger('duration_minutes')->nullable(false)->change();
        });

        $tenant = DB::table('MBA_tenants')->where('code', '4N')->value('id');
        if ($tenant !== null) {
            DB::table('Ope_Postas')->where([
                'tenant_id' => $tenant,
                'post_code' => 1,
                'origin_address' => 'Camino a Mejillones S/N (Aeropuerto)',
            ])->update(['origin_address' => 'Aeropuerto Andrés Sabella Gálvez', 'updated_at' => now()]);
        }
    }

    private function address(?string $address, ?string $commune): string
    {
        return trim((string) $address).', '.trim((string) $commune);
    }

    private function saveInitialEstimate(int $tenant, int $agency, string $segment, string $origin, string $destination, float $kilometres, string $mapsUrl): void
    {
        if (DB::table('Ope_RouteEstimates')->where(['tenant_id' => $tenant, 'agency_id' => $agency, 'segment' => $segment])->exists()) {
            return;
        }

        $parts = [['origin' => $origin, 'destination' => $destination, 'air' => false]];
        DB::table('Ope_RouteEstimates')->insert([
            'tenant_id' => $tenant,
            'agency_id' => $agency,
            'segment' => $segment,
            'route_hash' => hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE)),
            'distance_km' => $kilometres,
            'duration_minutes' => null,
            'maps_url' => $mapsUrl,
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
