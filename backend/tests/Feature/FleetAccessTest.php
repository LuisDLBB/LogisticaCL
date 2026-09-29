<?php

namespace Tests\Feature;

use App\Fleet\FleetAccess;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FleetAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_sent_to_fleet_login_and_provider_payments_still_loads(): void
    {
        $this->get('/control-flota')->assertRedirect('/control-flota/ingresar');
        $this->get('/control-flota/ingresar')->assertOk()->assertSee('Control de Flota 4N');
        $this->get('/pago-proveedores')->assertOk();
    }

    public function test_localhost_automatically_uses_the_configured_administrator_for_both_tenants(): void
    {
        $admin = User::factory()->create(['email' => 'hansdelabarra@4nlogistica.cl']);
        $fourN = Tenant::where('code', '4N')->firstOrFail();
        $pmcb = Tenant::where('code', 'PMCB')->firstOrFail();
        $this->associate($admin, $fourN, 'administrator');
        $this->associate($admin, $pmcb, 'administrator');
        config()->set('app.env', 'local');
        config()->set('fleet.local_admin_email', $admin->email);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('http://127.0.0.1:8000/control-flota/administrador/usuarios')
            ->assertOk();
        $this->assertAuthenticatedAs($admin);
        $this->post('/control-flota/empresa', ['tenant_id' => $pmcb->id])->assertRedirect();
        $this->get('http://127.0.0.1:8000/control-flota/administrador/permisos')->assertOk();
    }

    public function test_local_admin_login_is_disabled_outside_local_environment_or_loopback(): void
    {
        $admin = User::factory()->create(['email' => 'hansdelabarra@4nlogistica.cl']);
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $this->associate($admin, $tenant, 'administrator');
        config()->set('fleet.local_admin_email', $admin->email);

        config()->set('app.env', 'production');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('http://127.0.0.1:8000/control-flota')->assertRedirect('/control-flota/ingresar');
        $this->assertGuest();

        config()->set('app.env', 'local');
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->get('http://127.0.0.1:8000/control-flota')->assertRedirect('/control-flota/ingresar');
        $this->assertGuest();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('http://logisticacl.test/control-flota')->assertRedirect('/control-flota/ingresar');
        $this->assertGuest();
    }

    public function test_unassociated_user_cannot_enter_or_select_a_tenant(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::where('code', '4N')->firstOrFail();

        $this->actingAs($user)->get('/control-flota')->assertForbidden();
        $this->post('/control-flota/empresa', ['tenant_id' => $tenant->id])->assertForbidden();
    }

    public function test_unassociated_user_cannot_remain_logged_in_through_fleet_login(): void
    {
        $user = User::factory()->create(['password' => 'ClaveLocalSegura123']);

        $this->post('/control-flota/ingresar', [
            'email' => $user->email, 'password' => 'ClaveLocalSegura123',
        ])->assertRedirect()->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_and_logout_use_the_existing_user_identity(): void
    {
        $user = User::factory()->create(['password' => 'ClaveLocalSegura123']);
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $this->associate($user, $tenant, 'operations');

        $this->post('/control-flota/ingresar', [
            'email' => $user->email, 'password' => 'ClaveLocalSegura123',
        ])->assertRedirect('/control-flota');
        $this->assertAuthenticatedAs($user);
        $this->post('/control-flota/salir')->assertRedirect('/control-flota/ingresar');
        $this->assertGuest();
    }

    public function test_all_zero_permissions_or_inactive_membership_block_the_module(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $membership = $this->associate($user, $tenant, 'operations');
        DB::table('fleet_profile_permissions')->whereIn('fleet_profile_id', DB::table('fleet_profiles')
            ->where('tenant_id', $tenant->id)->where('code', 'operations')->select('id'))->update(['access_level' => 0]);

        $this->actingAs($user)->get('/control-flota')->assertForbidden();

        DB::table('fleet_profile_permissions')->whereIn('fleet_profile_id', DB::table('fleet_profiles')
            ->where('tenant_id', $tenant->id)->where('code', 'operations')->select('id'))->update(['access_level' => 1]);
        DB::table('tenant_users')->where('id', $membership)->update(['is_active' => false]);
        $this->get('/control-flota')->assertForbidden();
    }

    public function test_operations_profile_is_shared_and_restricted_to_its_tenant_and_area(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $fourN = Tenant::where('code', '4N')->firstOrFail();
        $pmcb = Tenant::where('code', 'PMCB')->firstOrFail();
        $this->associate($first, $fourN, 'operations');
        $this->associate($second, $fourN, 'operations');
        $access = app(FleetAccess::class);

        $this->assertSame($access->level($first, $fourN, 'operations.fleet'), $access->level($second, $fourN, 'operations.fleet'));
        $this->actingAs($first)->get('/control-flota/operaciones/flota')->assertOk()->assertSee('No hay vehículos para estos filtros.');
        $this->get('/control-flota/administrador/usuarios')->assertForbidden();
        $this->post('/control-flota/empresa', ['tenant_id' => $pmcb->id])->assertForbidden();
        $this->assertSame(0, $access->level($first, $pmcb, 'operations.fleet'));
    }

    public function test_profile_changes_and_user_override_are_audited_and_isolated_by_tenant(): void
    {
        $admin = User::factory()->create();
        $operator = User::factory()->create();
        $fourN = Tenant::where('code', '4N')->firstOrFail();
        $pmcb = Tenant::where('code', 'PMCB')->firstOrFail();
        $this->associate($admin, $fourN, 'administrator');
        $this->associate($admin, $pmcb, 'administrator');
        $fourNMembership = $this->associate($operator, $fourN, 'operations');
        $this->associate($operator, $pmcb, 'operations');

        $levels = array_fill_keys(array_keys(FleetAccess::SCOPES), '0');
        $levels['operations.area'] = '1';
        $levels['operations.fleet'] = '1';
        $profile = DB::table('fleet_profiles')->where('tenant_id', $fourN->id)->where('code', 'operations')->first();
        $this->actingAs($admin)->withSession(['fleet_tenant_id' => $fourN->id])
            ->put('/control-flota/administrador/permisos/perfiles/'.$profile->id, ['levels' => $levels])
            ->assertRedirect();

        $access = app(FleetAccess::class);
        $this->assertSame(1, $access->level($operator, $fourN, 'operations.fleet'));
        $this->assertSame(2, $access->level($operator, $pmcb, 'operations.fleet'));

        $overrides = array_fill_keys(array_keys(FleetAccess::SCOPES), '-1');
        $overrides['operations.fleet'] = '0';
        $this->put('/control-flota/administrador/permisos/usuarios/'.$fourNMembership, ['levels' => $overrides])->assertRedirect();
        $this->assertSame(0, $access->level($operator, $fourN, 'operations.fleet'));
        $this->assertSame(2, $access->level($operator, $pmcb, 'operations.fleet'));
        $this->assertDatabaseCount('fleet_permission_audits', 2);
    }

    public function test_administrator_can_create_and_associate_accounts_without_duplicate_users(): void
    {
        $admin = User::factory()->create();
        $fourN = Tenant::where('code', '4N')->firstOrFail();
        $pmcb = Tenant::where('code', 'PMCB')->firstOrFail();
        $this->associate($admin, $fourN, 'administrator');
        $this->associate($admin, $pmcb, 'administrator');

        $this->actingAs($admin)->withSession(['fleet_tenant_id' => $fourN->id])
            ->post('/control-flota/administrador/usuarios', [
                'name' => 'Usuaria de prueba', 'email' => 'persona@example.com',
                'password' => 'ClaveDePruebaSegura123', 'password_confirmation' => 'ClaveDePruebaSegura123',
                'role_code' => 'commercial',
            ])->assertRedirect();
        $user = User::where('email', 'persona@example.com')->firstOrFail();
        $this->assertDatabaseHas('tenant_users', ['tenant_id' => $fourN->id, 'user_id' => $user->id, 'role_code' => 'commercial']);

        $this->post('/control-flota/empresa', ['tenant_id' => $pmcb->id])->assertRedirect();
        $this->post('/control-flota/administrador/usuarios/asociar', [
            'email' => 'persona@example.com', 'role_code' => 'read_only',
        ])->assertRedirect();
        $this->assertSame(1, User::where('email', 'persona@example.com')->count());
        $this->assertDatabaseHas('tenant_users', ['tenant_id' => $pmcb->id, 'user_id' => $user->id, 'role_code' => 'read_only']);
    }

    public function test_administrator_cannot_change_a_membership_from_another_tenant(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();
        $fourN = Tenant::where('code', '4N')->firstOrFail();
        $pmcb = Tenant::where('code', 'PMCB')->firstOrFail();
        $this->associate($admin, $fourN, 'administrator');
        $foreignMembership = $this->associate($other, $pmcb, 'operations');

        $this->actingAs($admin)->withSession(['fleet_tenant_id' => $fourN->id])
            ->patch('/control-flota/administrador/usuarios/'.$foreignMembership, [
                'role_code' => 'administrator', 'is_active' => '1',
            ])->assertNotFound();
        $this->assertDatabaseHas('tenant_users', ['id' => $foreignMembership, 'role_code' => 'operations']);
    }

    public function test_administration_pages_render_and_provider_payments_links_to_fleet_only_for_authorized_users(): void
    {
        $admin = User::factory()->create();
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $this->associate($admin, $tenant, 'administrator');

        $this->actingAs($admin)->get('/control-flota/administrador/usuarios')->assertOk()->assertSee('Usuarios y acceso');
        $this->get('/control-flota/administrador/permisos')->assertOk()->assertSee('Perfiles y permisos');
        $this->get('/pago-proveedores')->assertOk()->assertSee('Control de Flota');
    }

    private function associate(User $user, Tenant $tenant, string $roleCode): int
    {
        return DB::table('tenant_users')->insertGetId([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $roleCode,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
