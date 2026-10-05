<?php

namespace Tests\Feature;

use App\Models\Coverage;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\OperationsTransportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationsTransportCatalogTest extends TestCase
{
    use RefreshDatabase;

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
}
