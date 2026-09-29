<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FleetVehiclesTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_sees_both_owners_and_filters_without_changing_service_tenant(): void
    {
        $admin = User::factory()->create();
        $fourN = Tenant::where('code', '4N')->firstOrFail();
        $pmcb = Tenant::where('code', 'PMCB')->firstOrFail();
        $this->associate($admin, $fourN, 'administrator');
        $this->associate($admin, $pmcb, 'administrator');
        $this->createVehicle($fourN, $fourN, 'AAAA11', 'Camioneta');
        $pmcbVehicle = $this->createVehicle($fourN, $pmcb, 'BBBB22', 'Furgón');

        $this->actingAs($admin)->withSession(['fleet_tenant_id' => $fourN->id])
            ->get('/control-flota/operaciones/flota')
            ->assertOk()->assertSee('AAAA11')->assertSee('BBBB22')->assertSee('Pool operacional compartido');
        $this->get('/control-flota/operaciones/flota?empresa=PMCB')
            ->assertOk()->assertDontSee('AAAA11')->assertSee('BBBB22');
        $this->get('/control-flota/operaciones/flota?tipo=Furg%C3%B3n&patente=bbb')
            ->assertOk()->assertDontSee('AAAA11')->assertSee('BBBB22');
        $this->get('/control-flota/operaciones/flota/'.$pmcbVehicle->id)
            ->assertOk()->assertSee('BBBB22')->assertSee('PMCB')->assertSee('Empresa del servicio seleccionada');
        $this->assertSame($fourN->id, session('fleet_tenant_id'));
    }

    public function test_operations_profile_can_use_shared_vehicle_pool_without_general_pmcb_access(): void
    {
        $operator = User::factory()->create();
        $fourN = Tenant::where('code', '4N')->firstOrFail();
        $pmcb = Tenant::where('code', 'PMCB')->firstOrFail();
        $this->associate($operator, $fourN, 'operations');
        $this->createVehicle($fourN, $pmcb, 'CCCC33', 'Camión');

        $this->actingAs($operator)->get('/control-flota/operaciones/flota')->assertOk()->assertSee('CCCC33');
        $this->post('/control-flota/empresa', ['tenant_id' => $pmcb->id])->assertForbidden();
        $this->get('/control-flota/administrador/usuarios')->assertForbidden();
    }

    public function test_read_only_user_cannot_see_other_owner_or_enable_shared_filter(): void
    {
        $reader = User::factory()->create();
        $fourN = Tenant::where('code', '4N')->firstOrFail();
        $pmcb = Tenant::where('code', 'PMCB')->firstOrFail();
        $this->associate($reader, $fourN, 'read_only');
        $this->createVehicle($fourN, $fourN, 'DDDD44', 'Camioneta');
        $foreign = $this->createVehicle($fourN, $pmcb, 'EEEE55', 'Furgón');

        $this->actingAs($reader)->get('/control-flota/operaciones/flota')
            ->assertOk()->assertSee('DDDD44')->assertDontSee('EEEE55');
        $this->get('/control-flota/operaciones/flota?empresa=PMCB')->assertForbidden();
        $this->get('/control-flota/operaciones/flota/'.$foreign->id)->assertNotFound();
    }

    public function test_operational_pool_excludes_sold_stolen_inactive_and_unknown_ownership(): void
    {
        $admin = User::factory()->create();
        $fourN = Tenant::where('code', '4N')->firstOrFail();
        $pmcb = Tenant::where('code', 'PMCB')->firstOrFail();
        $this->associate($admin, $fourN, 'administrator');
        $this->createVehicle($fourN, $fourN, 'FFFF66', 'Furgón');
        $this->createVehicle($fourN, $pmcb, 'GGGG77', 'Furgón', 'Vendido');
        $this->createVehicle($fourN, $fourN, 'HHHH88', 'Furgón', 'Robado');
        $this->createVehicle($fourN, $fourN, 'IIII99', 'Furgón', 'Activa', false);
        $unknown = $this->createVehicle($fourN, $pmcb, 'JJJJ00', 'Furgón');
        $unknown->company_source = '4N';
        $unknown->save();

        $this->actingAs($admin)->get('/control-flota/operaciones/flota')
            ->assertOk()->assertSee('FFFF66')->assertSee('GGGG77')->assertSee('HHHH88')->assertSee('IIII99')->assertSee('JJJJ00');
        $this->get('/control-flota/operaciones/flota?pool=1')
            ->assertOk()->assertSee('FFFF66')->assertDontSee('GGGG77')->assertDontSee('HHHH88')
            ->assertDontSee('IIII99')->assertDontSee('JJJJ00');
        $this->get('/control-flota/operaciones/flota/'.$unknown->id)->assertOk()->assertSee('Por verificar');
    }

    public function test_legacy_imported_role_and_guest_gain_no_fleet_access(): void
    {
        $fourN = Tenant::where('code', '4N')->firstOrFail();
        $legacy = User::factory()->create();
        $this->associate($legacy, $fourN, 'operaciones');
        $this->createVehicle($fourN, $fourN, 'KKKK11', 'Camioneta');

        $this->get('/control-flota/operaciones/flota')->assertRedirect('/control-flota/ingresar');
        $this->actingAs($legacy)->get('/control-flota/operaciones/flota')->assertForbidden();
        $this->get('/pago-proveedores')->assertOk();
    }

    private function associate(User $user, Tenant $tenant, string $roleCode): void
    {
        DB::table('tenant_users')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role_code' => $roleCode,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createVehicle(Tenant $recordTenant, Tenant $owner, string $plate, string $type, string $status = 'Activa', bool $active = true): Vehicle
    {
        $id = DB::table('vehicles')->insertGetId([
            'tenant_id' => $recordTenant->id,
            'rut_empresa' => $owner->tax_id,
            'company_source' => $owner->code,
            'internal_code' => $plate,
            'plate' => $plate,
            'vehicle_type' => $type,
            'ownership_type' => 'Propio',
            'operational_status' => $status,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Vehicle::findOrFail($id);
    }
}
