<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FleetHansIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_fleet_pages_open_with_shared_user_and_tenant_tables(): void
    {
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $user = User::factory()->create(['profile_name' => 'Administrador']);
        $membershipId = DB::table('MBA_tenant_users')->insertGetId([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => 'fleet',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $profileId = DB::table('fleet_profiles')->where('tenant_id', $tenant->id)->where('code', 'administrator')->value('id');
        DB::table('fleet_membership_profiles')->insert([
            'tenant_user_id' => $membershipId, 'fleet_profile_id' => $profileId,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user);
        foreach ([
            '/control-flota',
            '/control-flota/administrador/usuarios',
            '/control-flota/operaciones/flota',
            '/control-flota/operaciones/mantenciones',
            '/control-flota/operaciones/combustible',
            '/control-flota/operaciones/programacion',
            '/control-flota/coordinacion/retiros-fijos',
            '/control-flota/coordinacion/monitor',
            '/control-flota/coordinacion/solicitudes',
            '/control-flota/coordinacion/solicitudes/nueva',
            '/control-flota/administrador/parametros-programacion',
            '/control-flota/administrador/ret-configuracion',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
