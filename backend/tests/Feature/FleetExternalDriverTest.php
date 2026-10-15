<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FleetExternalDriverTest extends TestCase
{
    use RefreshDatabase;

    private function signInAdmin(): Tenant
    {
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $admin = User::factory()->create();
        $this->membership($admin, $tenant, 'administrator');
        $this->actingAs($admin)->withSession(['fleet_tenant_id' => $tenant->id]);

        return $tenant;
    }

    private function membership(User $user, Tenant $tenant, string $role = 'app_conductor'): int
    {
        return FleetTestMembership::insertGetId([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $role,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_external_driver_can_be_saved_without_vehicle_details(): void
    {
        $tenant = $this->signInAdmin();
        $driver = User::factory()->create();
        $id = $this->membership($driver, $tenant);

        $this->patch(route('fleet.users.update', $id), [
            'role_code' => 'app_conductor', 'is_active' => 1, 'driver_type' => 'external',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('MBA_tenant_users', [
            'id' => $id, 'driver_type' => 'external', 'external_vehicle_plate' => null,
            'external_vehicle_type' => null, 'external_payload_capacity_kg' => null, 'external_capacity_m3' => null,
        ]);
    }

    public function test_external_vehicle_details_are_saved_and_rendered(): void
    {
        $tenant = $this->signInAdmin();
        $driver = User::factory()->create();
        $id = $this->membership($driver, $tenant);

        $this->patch(route('fleet.users.update', $id), [
            'role_code' => 'app_conductor', 'is_active' => 1, 'driver_type' => 'external',
            'external_vehicle_plate' => 'abcd12', 'external_vehicle_type' => 'Furgón',
            'external_payload_capacity_kg' => 950.5, 'external_capacity_m3' => 8.25,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('MBA_tenant_users', [
            'id' => $id, 'driver_type' => 'external', 'external_vehicle_plate' => 'ABCD12',
            'external_vehicle_type' => 'Furgón', 'external_payload_capacity_kg' => 950.5, 'external_capacity_m3' => 8.25,
        ]);
        $this->get(route('fleet.users.index'))->assertSee('ABCD12')->assertSee('Furgón')->assertSee('Carga útil (kg)');
    }

    public function test_association_saves_optional_external_plate(): void
    {
        $tenant = $this->signInAdmin();
        $driver = User::factory()->create();

        $this->post(route('fleet.users.associate'), [
            'email' => $driver->email, 'role_code' => 'app_conductor',
            'driver_type' => 'external', 'external_vehicle_plate' => 'wxyz99',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('MBA_tenant_users', [
            'tenant_id' => $tenant->id, 'user_id' => $driver->id,
            'driver_type' => 'external', 'external_vehicle_plate' => 'WXYZ99',
        ]);
    }

    public function test_creation_accepts_external_driver_without_capacities(): void
    {
        $tenant = $this->signInAdmin();

        $this->post(route('fleet.users.create'), [
            'name' => 'Conductor externo prueba', 'email' => 'externo@example.test',
            'password' => 'ClavePruebaSegura123', 'password_confirmation' => 'ClavePruebaSegura123',
            'role_code' => 'app_conductor', 'driver_type' => 'external', 'external_vehicle_type' => 'Camioneta',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $driver = User::where('email', 'externo@example.test')->firstOrFail();
        $this->assertDatabaseHas('MBA_tenant_users', [
            'tenant_id' => $tenant->id, 'user_id' => $driver->id, 'driver_type' => 'external',
            'external_vehicle_type' => 'Camioneta', 'external_payload_capacity_kg' => null,
        ]);
    }

    public function test_invalid_capacities_do_not_change_membership(): void
    {
        $tenant = $this->signInAdmin();
        $id = $this->membership(User::factory()->create(), $tenant);

        $this->patch(route('fleet.users.update', $id), [
            'role_code' => 'app_conductor', 'is_active' => 1, 'driver_type' => 'external',
            'external_payload_capacity_kg' => -1, 'external_capacity_m3' => 'abc',
        ])->assertSessionHasErrors(['external_payload_capacity_kg', 'external_capacity_m3']);

        $this->assertDatabaseHas('MBA_tenant_users', ['id' => $id, 'driver_type' => null]);
    }

    public function test_foreign_company_external_vehicle_cannot_be_edited(): void
    {
        $this->signInAdmin();
        $other = Tenant::where('code', 'PMCB')->firstOrFail();
        $id = $this->membership(User::factory()->create(), $other);

        $this->patch(route('fleet.users.update', $id), [
            'role_code' => 'app_conductor', 'is_active' => 1, 'driver_type' => 'external',
            'external_vehicle_plate' => 'ABCD12',
        ])->assertNotFound();

        $this->assertDatabaseHas('MBA_tenant_users', ['id' => $id, 'external_vehicle_plate' => null]);
    }

    public function test_editing_imported_driver_preserves_current_profile(): void
    {
        $tenant = $this->signInAdmin();
        $id = $this->membership(User::factory()->create(), $tenant, 'app_conductor');

        $this->patch(route('fleet.users.update', $id), [
            'role_code' => 'app_conductor', 'is_active' => 1, 'driver_type' => 'external',
            'external_vehicle_plate' => 'ABCD12',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('MBA_tenant_users', [
            'id' => $id, 'role_code' => 'app_conductor', 'external_vehicle_plate' => 'ABCD12',
        ]);
    }

    public function test_administrative_profile_ignores_driver_fields(): void
    {
        $tenant = $this->signInAdmin();
        $id = $this->membership(User::factory()->create(), $tenant, 'operations');

        $this->patch(route('fleet.users.update', $id), [
            'role_code' => 'operations', 'is_active' => 1, 'driver_type' => 'external',
            'external_vehicle_plate' => 'ABCD12', 'external_capacity_m3' => -5,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('MBA_tenant_users', ['id' => $id, 'driver_type' => null, 'external_vehicle_plate' => null]);
    }

    public function test_driver_must_choose_internal_or_external(): void
    {
        $tenant = $this->signInAdmin();
        $id = $this->membership(User::factory()->create(), $tenant);

        $this->patch(route('fleet.users.update', $id), [
            'role_code' => 'app_conductor', 'is_active' => 1,
        ])->assertSessionHasErrors('driver_type');

        $this->assertDatabaseHas('MBA_tenant_users', ['id' => $id, 'driver_type' => null]);
    }

    public function test_internal_driver_needs_no_vehicle_details(): void
    {
        $tenant = $this->signInAdmin();
        $id = $this->membership(User::factory()->create(), $tenant);

        $this->patch(route('fleet.users.update', $id), [
            'role_code' => 'app_conductor', 'is_active' => 1, 'driver_type' => 'own',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('MBA_tenant_users', ['id' => $id, 'driver_type' => 'own', 'external_vehicle_plate' => null]);
    }

    public function test_all_users_share_the_profile_catalog_and_can_become_drivers(): void
    {
        $tenant = $this->signInAdmin();
        $id = $this->membership(User::factory()->create(), $tenant, 'postventa');
        $this->membership(User::factory()->create(), $tenant, 'app_conductor');

        $response = $this->get(route('fleet.users.index'));
        $response->assertSee('App conductor')->assertDontSee('perfil actual');
        preg_match_all('/<select name="role_code"[^>]*>(.*?)<\/select>/s', $response->getContent(), $selects);
        foreach ($selects[1] as $select) {
            preg_match_all('/<option value="([^"]+)"/', $select, $options);
            $this->assertSame(['administrator', 'app_conductor', 'commercial', 'finance', 'management', 'operations', 'after_sales', 'read_only'], $options[1]);
        }

        $this->patch(route('fleet.users.update', $id), [
            'role_code' => 'app_conductor', 'is_active' => 1, 'driver_type' => 'own',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('MBA_tenant_users', ['id' => $id, 'role_code' => 'postventa', 'driver_type' => 'own']);
        $this->assertSame('app_conductor', DB::table('fleet_membership_profiles as memberships')
            ->join('fleet_profiles as profiles', 'profiles.id', '=', 'memberships.fleet_profile_id')
            ->where('memberships.tenant_user_id', $id)->value('profiles.code'));
    }
}
