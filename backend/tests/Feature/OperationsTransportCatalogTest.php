<?php

namespace Tests\Feature;

use App\Models\Coverage;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Operations\Services\OperationRouteEstimator;
use Database\Seeders\OperationsTransportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OperationsTransportCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_show_ordered_agency_legs_and_allow_manual_estimates(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => 'Administrador']);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($user);
        $agency = DB::table('Ope_Agencias')->where(['tenant_id' => $tenant->id, 'agency_code' => 21])->firstOrFail();

        $this->get('/operaciones/rutas')->assertOk()
            ->assertSee('Programación de Rutas')
            ->assertSee('Troncal Sur (Santiago-Chillan)')
            ->assertSee('Troncal Sur (Chillan-Concepcion)')
            ->assertSee('Retorno estimado al origen de la troncal')
            ->assertSee('✈');

        $this->put('/operaciones/rutas/'.$agency->id.'/estimaciones', [
            'segment' => 'posta2', 'distance_km' => 225.4, 'duration_minutes' => 185,
            'maps_url' => 'https://www.google.cl/maps/dir/Temuco/PuertoMontt',
        ])->assertRedirect(route('operations.routes').'#agency-'.$agency->id);
        $this->assertDatabaseHas('Ope_RouteEstimates', [
            'tenant_id' => $tenant->id, 'agency_id' => $agency->id, 'segment' => 'posta2',
            'distance_km' => 225.4, 'duration_minutes' => 185, 'source' => 'manual',
            'maps_url' => 'https://www.google.cl/maps/dir/Temuco/PuertoMontt',
        ]);
        $this->get('/operaciones/rutas')->assertSee('225,4 km')->assertSee('3 h 5 min')
            ->assertSee('https://www.google.cl/maps/dir/Temuco/PuertoMontt');

        $this->put('/operaciones/rutas/'.$agency->id.'/estimaciones', [
            'segment' => 'posta2', 'distance_km' => 225.4, 'duration_minutes' => '',
            'maps_url' => 'https://www.google.cl/maps/dir/Temuco/PuertoMontt',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('Ope_RouteEstimates', ['agency_id' => $agency->id, 'segment' => 'posta2', 'duration_minutes' => null]);
        $this->get('/operaciones/rutas')->assertSee('Tiempo pendiente');

        $this->put('/operaciones/rutas/'.$agency->id.'/estimaciones', [
            'segment' => 'posta2', 'distance_km' => 225.4, 'maps_url' => 'https://example.com/incorrecto',
        ])->assertSessionHasErrors('maps_url');
    }

    public function test_route_programming_shows_ordered_visual_branches_and_all_ground_stops(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => 'Administrador']);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($user);

        $html = $this->get('/operaciones/rutas')->assertOk()->getContent();
        $southTrunkId = DB::table('Ope_Troncales')->where(['tenant_id' => $tenant->id, 'trunk_code' => 4])->value('id');
        $this->assertTrue(strpos($html, 'id="route-hub-title"') < strpos($html, 'id="route-air-map-title"'));
        $this->assertStringContainsString('Bodega 4 Nortes', $html);
        $this->assertStringContainsString('href="#visual-trunk-'.$southTrunkId.'"', $html);
        $this->assertStringContainsString('id="visual-trunk-'.$southTrunkId.'"', $html);
        foreach ([1, 2, 3] as $airCode) {
            $this->assertStringContainsString('href="#air-branch-'.$airCode.'"', $html);
            $this->assertStringContainsString('id="air-branch-'.$airCode.'"', $html);
        }
        $visual = substr($html, strpos($html, 'id="route-air-map-title"'), strpos($html, '<dialog class="route-visual-dialog"') - strpos($html, 'id="route-air-map-title"'));

        $this->assertNotFalse($visual);
        $this->assertTrue(strpos($visual, 'Aéreo Norte') < strpos($visual, 'Aéreo Pacífico'));
        $this->assertTrue(strpos($visual, 'Aéreo Pacífico') < strpos($visual, 'Aéreo Sur'));
        $this->assertTrue(strpos($visual, 'Ver datos de Antofagasta') < strpos($visual, 'Ver datos de Calama'));
        $this->assertTrue(strpos($visual, 'Ver datos de Calama') < strpos($visual, 'Ver datos de Iquique'));
        $this->assertTrue(strpos($visual, 'Ver datos de Iquique') < strpos($visual, 'Ver datos de Arica'));
        foreach (['Buin', 'Rancagua', 'San Fernando', 'Curico', 'Talca', 'Chillan', 'Consolidado a Chillan', 'Consolidado a Temuco', 'Consolidado a Puerto Montt', 'Chonchi', 'Vallenar', 'Copiapo'] as $stop) {
            $this->assertStringContainsString('Ver datos de '.$stop, $visual, $stop.' debe figurar en el recorrido visual.');
        }
        foreach (['Nombre chofer', 'RUT chofer', 'Patente asignada', 'Dirección origen', 'Dirección destino'] as $field) {
            $this->assertStringContainsString($field, $html);
        }
        $this->assertStringContainsString('data-route-driver=', $visual);
        $this->assertStringContainsString('data-route-plate=', $visual);
        $this->assertStringContainsString('<svg viewBox="0 0 48 48"', $visual);
        $this->assertStringNotContainsString("@include('operations::route-truck-icon')", $visual);
        $south = substr($visual, strpos($visual, 'id="visual-trunk-'.$southTrunkId.'"'));
        $firstPost = substr($south, strpos($south, '<h3>Posta 1</h3>'), strpos($south, '<h3>Posta 2</h3>') - strpos($south, '<h3>Posta 1</h3>'));
        $this->assertTrue(strpos($firstPost, 'Ver datos de Concepcion') < strpos($firstPost, 'Ver datos de Los Angeles'));

        $vRegionId = DB::table('Ope_Troncales')->where(['tenant_id' => $tenant->id, 'trunk_code' => 5])->value('id');
        $regionStart = strpos($visual, 'id="visual-trunk-'.$vRegionId.'"');
        $vRegion = substr($visual, $regionStart, strpos($visual, 'id="visual-trunk-6"', $regionStart) - $regionStart);
        $previousPosition = -1;
        foreach (['Viña del Mar', 'Litoral', 'Casa Blanca', 'Curacavi', 'Talagante'] as $stop) {
            $position = strpos($vRegion, 'Ver datos de '.$stop);
            $this->assertNotFalse($position, $stop.' debe figurar en Posta 1 de V Región.');
            $this->assertGreaterThan($previousPosition, $position);
            $previousPosition = $position;
        }
    }

    public function test_ground_route_arrows_show_the_distance_between_consecutive_agencies(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => 'Administrador']);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($user);
        $trunk = DB::table('Ope_Troncales')->where(['tenant_id' => $tenant->id, 'trunk_code' => 5])->firstOrFail();
        $estimator = new class extends OperationRouteEstimator
        {
            public array $calls = [];

            public function configured(): bool
            {
                return true;
            }

            public function estimate(string $origin, string $destination, bool $air = false, ?string $mapsUrl = null): array
            {
                $this->calls[] = [$origin, $destination];

                return ['distance_km' => 12.3, 'duration_minutes' => 18, 'source' => 'openrouteservice'];
            }
        };
        app()->instance(OperationRouteEstimator::class, $estimator);

        $this->post('/operaciones/rutas/troncal/'.$trunk->id.'/tramos')->assertSessionHasNoErrors();

        $this->assertContains(['Avenida Valparaiso 34, Viña del Mar', 'Los Zorzales 76, El Tabo'], $estimator->calls);
        $this->get('/operaciones/rutas')->assertOk()->assertSee('12,3 km')->assertSee('Calcular km entre paradas');

        $vina = DB::table('Ope_Agencias')->where(['tenant_id' => $tenant->id, 'agency_code' => 22])->firstOrFail();
        $litoral = DB::table('Ope_Agencias')->where(['tenant_id' => $tenant->id, 'agency_code' => 25])->firstOrFail();
        $gap = 'gap:posta1:'.$vina->id.':'.$litoral->id;
        $this->put('/operaciones/rutas/troncal/'.$trunk->id.'/tramos', ['distances' => [$gap => '31,4']])->assertSessionHasNoErrors();
        $this->get('/operaciones/rutas')->assertOk()->assertSee('31,4 km');
        $this->put('/operaciones/rutas/troncal/'.$trunk->id.'/tramos', ['distances' => ['gap:inexistente' => 10]])->assertSessionHasErrors('distances');
    }

    public function test_ground_distance_calculation_stops_after_the_first_map_failure(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => 'Administrador']);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($user);
        $trunk = DB::table('Ope_Troncales')->where(['tenant_id' => $tenant->id, 'trunk_code' => 5])->firstOrFail();
        $estimator = new class extends OperationRouteEstimator
        {
            public int $calls = 0;

            public function estimate(string $origin, string $destination, bool $air = false, ?string $mapsUrl = null): array
            {
                $this->calls++;

                throw new \RuntimeException('Dirección no reconocida.');
            }
        };
        app()->instance(OperationRouteEstimator::class, $estimator);

        $this->post('/operaciones/rutas/troncal/'.$trunk->id.'/tramos')->assertSessionHasErrors('mapas');
        $this->assertSame(1, $estimator->calls);
    }

    public function test_air_routes_share_one_warehouse_to_airport_leg_and_keep_each_destination_post(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => 'Administrador']);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($user);
        $arica = DB::table('Ope_Agencias')->where(['tenant_id' => $tenant->id, 'agency_code' => 2])->firstOrFail();

        $page = $this->get('/operaciones/rutas')->assertOk()
            ->assertSee('Aéreos · un tramo común')
            ->assertSee('7 destinos')
            ->assertSeeInOrder(['Antofagasta', 'Arica', 'Calama', 'Iquique', 'Isla de Pascua', 'Coyhaique', 'Punta Arenas'])
            ->assertSee('Camino a Mejillones S/N (Aeropuerto)')
            ->assertSee('Aeropuerto Chacalluta')
            ->assertSee('Aereo Norte')
            ->assertSee('Aereo Pacifico')
            ->assertSee('Aereo Sur');
        $this->assertSame(1, substr_count($page->getContent(), 'Tramo común · Bodega → Aeropuerto'));
        $this->assertSame(0, substr_count($page->getContent(), 'Troncal · Aereo Norte'));
        $page->assertSee('Aeropuerto Arturo Merino Benítez, Carga Nacional')
            ->assertSee('18,6 km')->assertSee('25,9 km')->assertSee('Tiempo pendiente');

        $this->put('/operaciones/rutas/'.$arica->id.'/estimaciones', [
            'segment' => 'troncal', 'distance_km' => 26.5, 'duration_minutes' => 45,
        ])->assertRedirect();
        $sharedPage = $this->get('/operaciones/rutas')->assertOk()->assertSee('26,5 km');
        $this->assertSame(1, substr_count($sharedPage->getContent(), '26,5 km'));
    }

    public function test_routes_calculate_road_metrics_with_the_configured_map_service(): void
    {
        config(['services.openrouteservice.key' => 'fake-map-key']);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => 'Administrador']);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($user);
        $agency = DB::table('Ope_Agencias')->where(['tenant_id' => $tenant->id, 'agency_code' => 15])->firstOrFail();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/pelias/v1/search')) {
                $isOrigin = str_contains($request->url(), 'Galvarino');
                $longitude = $isOrigin ? -70.7 : -72.1;
                $label = $isOrigin ? 'Galvarino 8481, Quilicura' : 'Cinco de Abril 399, Chillan';

                return Http::response(['features' => [['properties' => ['label' => $label], 'geometry' => ['coordinates' => [$longitude, -33.4]]]]]);
            }

            return Http::response(['routes' => [['summary' => ['distance' => 123450, 'duration' => 7200]]]]);
        });

        $this->post('/operaciones/rutas/'.$agency->id.'/calcular', ['segment' => 'troncal'])
            ->assertRedirect(route('operations.routes').'#agency-'.$agency->id);
        $this->assertDatabaseHas('Ope_RouteEstimates', [
            'agency_id' => $agency->id, 'segment' => 'troncal', 'distance_km' => 123.5,
            'duration_minutes' => 120, 'source' => 'openrouteservice',
        ]);
        Http::assertSentCount(3);
    }

    public function test_calculation_uses_saved_map_coordinates_without_geocoding_and_preserves_the_link(): void
    {
        config(['services.openrouteservice.key' => 'fake-map-key']);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => 'Administrador']);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($user);
        $agency = DB::table('Ope_Agencias')->where(['tenant_id' => $tenant->id, 'agency_code' => 1])->firstOrFail();
        $savedMapUrl = DB::table('Ope_RouteEstimates')->where(['agency_id' => $agency->id, 'segment' => 'troncal'])->value('maps_url');
        Http::fake(['https://api.heigit.org/openrouteservice/v2/directions/driving-car' => Http::response([
            'routes' => [['summary' => ['distance' => 17525.9, 'duration' => 1149.8]]],
        ])]);

        $this->post('/operaciones/rutas/'.$agency->id.'/calcular', ['segment' => 'troncal'])
            ->assertRedirect(route('operations.routes').'#agency-'.$agency->id);
        $this->assertDatabaseHas('Ope_RouteEstimates', [
            'agency_id' => $agency->id, 'segment' => 'troncal', 'distance_km' => 17.5,
            'duration_minutes' => 20, 'source' => 'openrouteservice', 'maps_url' => $savedMapUrl,
        ]);
        Http::assertSentCount(1);
    }

    public function test_calculation_does_not_save_an_imprecisely_geocoded_route(): void
    {
        config(['services.openrouteservice.key' => 'fake-map-key']);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => 'Administrador']);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($user);
        $agency = DB::table('Ope_Agencias')->where(['tenant_id' => $tenant->id, 'agency_code' => 15])->firstOrFail();
        Http::fake(['https://api.heigit.org/pelias/v1/search*' => Http::response(['features' => [
            ['properties' => ['label' => 'Galvarino, Chile Chico, AI, Chile'], 'geometry' => ['coordinates' => [-71.72, -46.54]]],
        ]])]);

        $this->post('/operaciones/rutas/'.$agency->id.'/calcular', ['segment' => 'troncal'])
            ->assertSessionHasErrors('mapas');
        $this->assertDatabaseMissing('Ope_RouteEstimates', ['agency_id' => $agency->id, 'segment' => 'troncal']);
        Http::assertSentCount(1);
    }

    public function test_agencies_link_to_trunks_and_both_post_relays_without_duplicating_drivers(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();

        $this->assertSame(14, DB::table('Ope_Choferes')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(8, DB::table('Ope_Troncales')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(26, DB::table('Ope_Postas')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(34, DB::table('Ope_Agencias')->where('tenant_id', $tenant->id)->count());

        $concepcion = DB::table('Ope_Agencias as agency')
            ->join('Ope_Troncales as trunk', 'trunk.id', '=', 'agency.trunk_id')
            ->join('Ope_Postas as post', 'post.id', '=', 'agency.post_id')
            ->join('Ope_Postas as second_post', 'second_post.id', '=', 'agency.second_post_id')
            ->where('agency.tenant_id', $tenant->id)
            ->where('agency.agency_code', 15)
            ->select('trunk.trunk_code', 'post.post_code', 'second_post.post_code as second_post_code')
            ->firstOrFail();

        $this->assertSame(4, $concepcion->trunk_code);
        $this->assertSame(8, $concepcion->post_code);
        $this->assertSame(9, $concepcion->second_post_code);

        $cdQuilicura = DB::table('Ope_Agencias as agency')
            ->join('Ope_Postas as post', 'post.id', '=', 'agency.post_id')
            ->where('agency.tenant_id', $tenant->id)
            ->where('agency.agency_code', 34)
            ->select('post.post_code', 'post.is_active')
            ->firstOrFail();

        $this->assertSame(26, $cdQuilicura->post_code);
        $this->assertFalse((bool) $cdQuilicura->is_active);

        $trunks = DB::table('Ope_Troncales')->where('tenant_id', $tenant->id)->whereIn('trunk_code', [5, 6])->orderBy('trunk_code')->get(['plate', 'driver_id']);
        $this->assertSame('TJGR16', $trunks[0]->plate);
        $this->assertSame($trunks[0]->plate, $trunks[1]->plate);
        $this->assertNotSame($trunks[0]->driver_id, $trunks[1]->driver_id);

        app(OperationsTransportSeeder::class)->run();
        $this->assertSame(34, DB::table('Ope_Agencias')->where('tenant_id', $tenant->id)->count());
    }

    public function test_setup_shows_agency_routes_and_their_coverage_count(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        Coverage::factory()->create(['tenant_id' => $tenant->id, 'ID_ComunaMatrizAgencia' => 15]);
        $user = User::factory()->create(['profile_name' => 'Administrador']);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);

        $this->actingAs($user)->get('/operaciones/configuracion')
            ->assertOk()
            ->assertSee('Relación de agencias, troncales y postas')
            ->assertSee('Concepcion')
            ->assertSee('Troncal Sur (Chillan-Concepcion)');
    }

    public function test_post_origins_have_airport_defaults_and_other_posts_can_be_edited_by_a_supervisor(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $airports = [
            1 => 'Camino a Mejillones S/N (Aeropuerto)',
            2 => 'Aeropuerto Chacalluta',
            3 => 'Aeródromo El Loa',
            4 => 'Aeropuerto Diego Aracena',
            5 => 'Aeropuerto Mataveri',
            6 => 'Aeródromo Balmaceda',
            7 => 'Aeropuerto Presidente Carlos Ibáñez del Campo',
        ];
        foreach ($airports as $code => $address) {
            $this->assertDatabaseHas('Ope_Postas', ['tenant_id' => $tenant->id, 'post_code' => $code, 'origin_address' => $address]);
        }

        $post = DB::table('Ope_Postas')->where(['tenant_id' => $tenant->id, 'post_code' => 9])->firstOrFail();
        $operator = User::factory()->create(['profile_name' => 'Operario']);
        $operator->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($operator)->get('/operaciones/origenes-postas')->assertOk()->assertSee('Aeropuerto Chacalluta');
        $this->put('/operaciones/origenes-postas/'.$post->id, [
            'origin_address' => 'Terminal Concepción', 'origin_commune' => 'Concepción',
        ])->assertForbidden();

        $supervisor = User::factory()->create(['profile_name' => 'Supervisor']);
        $supervisor->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($supervisor)->put('/operaciones/origenes-postas/'.$post->id, [
            'origin_address' => 'Terminal Concepción', 'origin_commune' => 'Concepción',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('Ope_Postas', ['id' => $post->id, 'origin_address' => 'Terminal Concepción', 'origin_commune' => 'Concepción']);
        $this->assertDatabaseHas('Ope_Auditoria', ['tenant_id' => $tenant->id, 'entity' => 'posta', 'entity_id' => $post->id, 'action' => 'Actualizar origen de posta']);
    }

    public function test_administrator_can_edit_trunk_and_second_post_transport_from_the_submenu(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => 'Administrador']);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($user);

        $trunk = DB::table('Ope_Troncales')->where(['tenant_id' => $tenant->id, 'trunk_code' => 5])->firstOrFail();
        $post = DB::table('Ope_Postas')->where(['tenant_id' => $tenant->id, 'post_code' => 9])->firstOrFail();
        $this->get('/operaciones/transporte')->assertOk()->assertSee('Posta 1 y Posta 2')->assertSee('Troncal V Region');
        $internalDriver = User::factory()->create(['name' => 'Chofer Interno', 'tax_id' => '11111111-1']);
        $internalDriver->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->get('/operaciones/transporte?type=troncal&record='.$trunk->id)
            ->assertOk()
            ->assertSee('id="ope-transport-dialog"', false)
            ->assertSee('list="ope-plate-options"', false)
            ->assertSee('Chofer Interno · 11111111-1');

        $this->put("/operaciones/transporte/troncal/{$trunk->id}", [
            'plate' => 'AB-CD-12', 'driver_rut' => '12.345.678-5', 'driver_name' => 'Nuevo Chofer Troncal',
        ])->assertSessionHasNoErrors()->assertRedirect(route('operations.transport').'#troncales');

        $updatedTrunk = DB::table('Ope_Troncales')->where('id', $trunk->id)->firstOrFail();
        $this->assertSame('ABCD12', $updatedTrunk->plate);
        $this->assertDatabaseHas('Ope_Choferes', ['id' => $updatedTrunk->driver_id, 'tenant_id' => $tenant->id, 'rut' => '12345678-5', 'name' => 'Nuevo Chofer Troncal']);
        $this->assertDatabaseHas('Ope_Troncales', ['tenant_id' => $tenant->id, 'trunk_code' => 6, 'plate' => 'TJGR16']);
        foreach ([13, 14, 15, 16, 17] as $firstPostCode) {
            $this->assertDatabaseHas('Ope_Postas', ['tenant_id' => $tenant->id, 'post_code' => $firstPostCode, 'plate' => 'ABCD12', 'driver_id' => $updatedTrunk->driver_id]);
        }

        $this->put("/operaciones/transporte/posta/{$post->id}", [
            'plate' => 'EFGH34', 'driver_rut' => '12345678-5', 'driver_name' => 'Nuevo Chofer Troncal',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('Ope_Postas', ['id' => $post->id, 'plate' => 'EFGH34', 'driver_id' => $updatedTrunk->driver_id]);
        $this->assertDatabaseHas('Ope_Agencias', ['tenant_id' => $tenant->id, 'agency_code' => 15, 'second_post_id' => $post->id]);
        $this->get('/operaciones/transporte?type=posta&record='.$post->id)
            ->assertOk()
            ->assertSee('<option value="EFGH34"', false)
            ->assertSee('Nuevo Chofer Troncal · 12345678-5');
        $this->assertDatabaseHas('Ope_Auditoria', ['tenant_id' => $tenant->id, 'entity' => 'posta', 'entity_id' => $post->id, 'action' => 'Actualizar transporte']);
    }

    public function test_transport_changes_require_a_supervisor_and_valid_driver_identity(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $post = DB::table('Ope_Postas')->where(['tenant_id' => $tenant->id, 'post_code' => 9])->firstOrFail();
        $operator = User::factory()->create(['profile_name' => 'Operario']);
        $operator->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($operator)->get('/operaciones/transporte')->assertOk()->assertDontSee('Guardar transporte');
        $this->put("/operaciones/transporte/posta/{$post->id}", ['plate' => 'ABCD12'])->assertForbidden();

        $supervisor = User::factory()->create(['profile_name' => 'Supervisor']);
        $supervisor->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($supervisor)->put("/operaciones/transporte/posta/{$post->id}", [
            'plate' => 'PLLP96', 'driver_rut' => '12345678-0', 'driver_name' => 'Chofer Incorrecto',
        ])->assertSessionHasErrors('driver_rut');
        $this->assertDatabaseHas('Ope_Postas', ['id' => $post->id, 'plate' => 'PLLP96', 'driver_id' => $post->driver_id]);

        $this->put("/operaciones/transporte/posta/{$post->id}", [
            'plate' => 'PLLP96', 'driver_rut' => '19049607-4', 'driver_name' => 'Cristian Saldivia Guerrero',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('Ope_Choferes', ['id' => $post->driver_id, 'name' => 'Cristian Saldivia Guerrero']);
    }

    public function test_trunk_transport_updates_first_posts_used_by_the_routes_and_keeps_later_posts_editable(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => 'Administrador']);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($user);

        $expectations = [
            4 => ['plate' => 'ABCD12', 'rut' => '13012860-2', 'name' => 'Claudio Andres Castro Valenzuela', 'first_posts' => [8, 9, 10]],
            5 => ['plate' => 'EFGH34', 'rut' => '10124367-2', 'name' => 'Nelson Luis Cisternas Rivera', 'first_posts' => [13, 14, 15, 16, 17]],
            6 => ['plate' => 'IJKL56', 'rut' => '13172671-6', 'name' => 'Marcelo Alejandro Avendaño Tapia', 'first_posts' => [18, 19, 20, 22, 23, 24]],
        ];
        $secondPostsBefore = DB::table('Ope_Postas')->where('tenant_id', $tenant->id)->whereIn('post_code', [11, 12])->orderBy('post_code')->get(['post_code', 'plate', 'driver_id']);

        foreach ($expectations as $trunkCode => $expected) {
            $trunk = DB::table('Ope_Troncales')->where(['tenant_id' => $tenant->id, 'trunk_code' => $trunkCode])->firstOrFail();
            $this->put("/operaciones/transporte/troncal/{$trunk->id}", [
                'plate' => $expected['plate'], 'driver_rut' => $expected['rut'], 'driver_name' => $expected['name'],
            ])->assertSessionHasNoErrors();
            $driverId = DB::table('Ope_Troncales')->where('id', $trunk->id)->value('driver_id');
            foreach ($expected['first_posts'] as $postCode) {
                $this->assertDatabaseHas('Ope_Postas', ['tenant_id' => $tenant->id, 'post_code' => $postCode, 'plate' => $expected['plate'], 'driver_id' => $driverId]);
            }
        }

        $secondPostsAfter = DB::table('Ope_Postas')->where('tenant_id', $tenant->id)->whereIn('post_code', [11, 12])->orderBy('post_code')->get(['post_code', 'plate', 'driver_id']);
        $this->assertEquals($secondPostsBefore, $secondPostsAfter);

        $firstPost = DB::table('Ope_Postas')->where(['tenant_id' => $tenant->id, 'post_code' => 8])->firstOrFail();
        $this->put("/operaciones/transporte/posta/{$firstPost->id}", [
            'plate' => 'MNOP78', 'driver_rut' => '13012860-2', 'driver_name' => 'Claudio Andres Castro Valenzuela',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('Ope_Postas', ['id' => $firstPost->id, 'plate' => 'MNOP78']);

        $secondPost = DB::table('Ope_Postas')->where(['tenant_id' => $tenant->id, 'post_code' => 9])->firstOrFail();
        $this->put("/operaciones/transporte/posta/{$secondPost->id}", [
            'plate' => 'MNOP78', 'driver_rut' => '19049607-4', 'driver_name' => 'Cristian Marcelo Saldivia Guerrero',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('Ope_Postas', ['id' => $secondPost->id, 'plate' => 'MNOP78']);
        $southTrunk = DB::table('Ope_Troncales')->where(['tenant_id' => $tenant->id, 'trunk_code' => 4])->firstOrFail();
        $this->put("/operaciones/transporte/troncal/{$southTrunk->id}", [
            'plate' => 'QRST90', 'driver_rut' => '13012860-2', 'driver_name' => 'Claudio Andres Castro Valenzuela',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('Ope_Postas', ['id' => $firstPost->id, 'plate' => 'QRST90']);
        $this->assertDatabaseHas('Ope_Postas', ['id' => $secondPost->id, 'plate' => 'QRST90']);
        $this->get('/operaciones/transporte?type=posta&record='.$secondPost->id)->assertOk()->assertSee('Guardar transporte');
        $this->get('/operaciones/transporte?type=posta&record='.$firstPost->id)->assertOk()->assertSee('Editar troncal');
    }

    public function test_updating_an_air_trunk_requires_choosing_whether_to_extend_the_change(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => 'Administrador']);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $this->actingAs($user);
        $airRoutes = DB::table('Ope_Troncales')->where('tenant_id', $tenant->id)->whereIn('trunk_code', [1, 2, 3])->get()->keyBy('trunk_code');
        $north = $airRoutes->get(1);
        $pacific = $airRoutes->get(2);
        $south = $airRoutes->get(3);
        $originalNorthPost = DB::table('Ope_Postas')->where(['tenant_id' => $tenant->id, 'post_code' => 1])->firstOrFail();

        $this->get('/operaciones/transporte?type=troncal&record='.$north->id)
            ->assertOk()
            ->assertSee('name="air_route_scope"', false)
            ->assertSee('value="only"', false)
            ->assertSee('value="all"', false)
            ->assertSee('otros dos aéreos');

        $change = ['plate' => 'ABCD12', 'driver_rut' => '12345678-5', 'driver_name' => 'Chofer Aereo Nuevo'];
        $this->put("/operaciones/transporte/troncal/{$north->id}", $change)->assertSessionHasErrors('air_route_scope');
        $this->assertDatabaseHas('Ope_Troncales', ['id' => $north->id, 'plate' => 'SGSS86']);

        $this->put("/operaciones/transporte/troncal/{$north->id}", [...$change, 'air_route_scope' => 'only'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Transporte de Aereo Norte actualizado.');
        $this->assertDatabaseHas('Ope_Troncales', ['id' => $north->id, 'plate' => 'ABCD12']);
        $this->assertDatabaseHas('Ope_Troncales', ['id' => $pacific->id, 'plate' => 'SGSS86', 'driver_id' => $pacific->driver_id]);
        $this->assertDatabaseHas('Ope_Troncales', ['id' => $south->id, 'plate' => 'SGSS86', 'driver_id' => $south->driver_id]);

        $this->put("/operaciones/transporte/troncal/{$pacific->id}", [
            'plate' => 'EFGH34', 'driver_rut' => '13012860-2', 'driver_name' => 'Claudio Andres Castro Valenzuela', 'air_route_scope' => 'all',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Transporte de Aereo Pacifico actualizado y extendido a los otros aéreos.');
        $driverId = DB::table('Ope_Troncales')->where('id', $pacific->id)->value('driver_id');
        foreach ($airRoutes as $route) {
            $this->assertDatabaseHas('Ope_Troncales', ['id' => $route->id, 'plate' => 'EFGH34', 'driver_id' => $driverId]);
        }
        $this->assertDatabaseHas('Ope_Postas', ['id' => $originalNorthPost->id, 'plate' => $originalNorthPost->plate, 'driver_id' => $originalNorthPost->driver_id]);
        $this->assertSame(2, DB::table('Ope_Auditoria')->where(['tenant_id' => $tenant->id, 'action' => 'Extender transporte aéreo'])->count());
    }

    public function test_each_transport_row_opens_its_assigned_coverages_in_a_modal(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => 'Administrador']);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        Coverage::factory()->create(['tenant_id' => $tenant->id, 'ID_ComunaMatrizAgencia' => 15, 'commune_name' => 'Cobertura Prueba Concepcion']);
        $trunk = DB::table('Ope_Troncales')->where(['tenant_id' => $tenant->id, 'trunk_code' => 4])->firstOrFail();
        $firstPost = DB::table('Ope_Postas')->where(['tenant_id' => $tenant->id, 'post_code' => 8])->firstOrFail();
        $secondPost = DB::table('Ope_Postas')->where(['tenant_id' => $tenant->id, 'post_code' => 9])->firstOrFail();

        $this->actingAs($user)->get('/operaciones/transporte')
            ->assertOk()
            ->assertSee('id="ope-coverages-dialog"', false)
            ->assertSee(route('operations.transport.coverages', ['type' => 'troncal', 'record' => $trunk->id]))
            ->assertSee('aria-label="Ver 1 coberturas de Troncal Sur', false)
            ->assertDontSee('Cobertura Prueba Concepcion');

        $this->get(route('operations.transport.coverages', ['type' => 'troncal', 'record' => $trunk->id]))
            ->assertOk()->assertJsonCount(1, 'coverages')
            ->assertJsonPath('coverages.0.commune', 'Cobertura Prueba Concepcion')
            ->assertJsonPath('coverages.0.role', 'Troncal');
        $this->get(route('operations.transport.coverages', ['type' => 'posta', 'record' => $firstPost->id]))
            ->assertOk()->assertJsonCount(1, 'coverages')->assertJsonPath('coverages.0.role', 'Posta 1');
        $this->get(route('operations.transport.coverages', ['type' => 'posta', 'record' => $secondPost->id]))
            ->assertOk()->assertJsonCount(1, 'coverages')->assertJsonPath('coverages.0.role', 'Posta 2');
        $this->get('/operaciones/transporte/posta/999999/coberturas')->assertNotFound();
    }

    public function test_trunk_agency_count_opens_origin_matrix_communes_in_a_modal(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => 'Administrador']);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $northTrunk = DB::table('Ope_Troncales')->where(['tenant_id' => $tenant->id, 'trunk_code' => 6])->firstOrFail();
        $externalTrunk = DB::table('Ope_Troncales')->where(['tenant_id' => $tenant->id, 'trunk_code' => 7])->firstOrFail();

        $this->actingAs($user)->get('/operaciones/transporte')
            ->assertOk()
            ->assertSee('id="ope-agencies-dialog"', false)
            ->assertSee('Comuna matriz origen')
            ->assertSee('aria-label="Ver 7 agencias de Troncal Norte', false);

        $response = $this->get(route('operations.transport.agencies', ['type' => 'troncal', 'record' => $northTrunk->id]))
            ->assertOk()->assertJsonCount(7, 'agencies');
        $losAndes = collect($response->json('agencies'))->firstWhere('code', 27);
        $this->assertSame('Los Andes', $losAndes['name']);
        $this->assertSame('Llay Llay', $losAndes['matrix_origin_commune']);
        $this->assertSame('El Porvenir Najo Sitio E_1', $losAndes['address']);
        $this->get(route('operations.transport.agencies', ['type' => 'troncal', 'record' => $externalTrunk->id]))
            ->assertOk()->assertJsonCount(0, 'agencies');
        $this->get('/operaciones/transporte/troncal/999999/agencias')->assertNotFound();
    }

    public function test_post_agency_counts_open_only_the_selected_relay_with_its_origin_commune(): void
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => 'Administrador']);
        $user->tenants()->attach($tenant->id, ['is_active' => true, 'role_code' => 'operator']);
        $firstPost = DB::table('Ope_Postas')->where(['tenant_id' => $tenant->id, 'post_code' => 8])->firstOrFail();
        $secondPost = DB::table('Ope_Postas')->where(['tenant_id' => $tenant->id, 'post_code' => 9])->firstOrFail();

        $this->actingAs($user)->get('/operaciones/transporte')
            ->assertOk()
            ->assertSee('aria-label="Ver 14 agencias como Posta 1 de Troncal Sur', false)
            ->assertSee('aria-label="Ver 1 agencias como Posta 2 de Troncal Sur', false)
            ->assertSee(route('operations.transport.agencies', ['type' => 'posta', 'record' => $secondPost->id, 'role' => 2]));

        $firstResponse = $this->get(route('operations.transport.agencies', ['type' => 'posta', 'record' => $firstPost->id, 'role' => 1]))
            ->assertOk()->assertJsonCount(14, 'agencies');
        $buin = collect($firstResponse->json('agencies'))->firstWhere('code', 8);
        $this->assertSame('Buin', $buin['name']);
        $this->assertSame('Buin', $buin['matrix_origin_commune']);

        $this->get(route('operations.transport.agencies', ['type' => 'posta', 'record' => $secondPost->id, 'role' => 1]))
            ->assertOk()->assertJsonCount(0, 'agencies');
        $this->get(route('operations.transport.agencies', ['type' => 'posta', 'record' => $secondPost->id, 'role' => 2]))
            ->assertOk()->assertJsonCount(1, 'agencies')->assertJsonPath('agencies.0.name', 'Concepcion')
            ->assertJsonPath('agencies.0.matrix_origin_commune', 'Concepcion');
        $this->get('/operaciones/transporte/posta/'.$secondPost->id.'/agencias')->assertNotFound();
        $this->get('/operaciones/transporte/posta/'.$secondPost->id.'/agencias?role=3')->assertNotFound();
    }
}
