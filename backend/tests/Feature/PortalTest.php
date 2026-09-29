<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    private function member(bool $active = true): User
    {
        $user = User::factory()->create(['username' => 'portal.luis', 'name' => 'Luis Prueba']);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $user->tenants()->attach($tenant->id, ['is_active' => $active, 'role_code' => 'operator']);

        return $user;
    }

    public function test_root_shows_login_and_protected_pages_require_authentication(): void
    {
        $this->get('/')->assertSee('Iniciar sesión')->assertSee('Usuario');
        $this->get('/inicio')->assertRedirect(route('login'));
        $this->get('/pago-proveedores')->assertRedirect(route('login'));
        $this->get('/mi-cuenta')->assertRedirect(route('login'));
        $this->put('/mi-cuenta', ['name' => 'No permitido'])->assertRedirect(route('login'));
    }

    public function test_existing_username_signs_in_and_records_activity(): void
    {
        $user = $this->member();
        $this->post('/login', ['username' => 'PORTAL.LUIS', 'password' => 'password'])->assertRedirect(route('portal.home'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('user_activities', ['user_id' => $user->id, 'action' => 'Inicio de sesión']);
    }

    public function test_existing_email_also_signs_in(): void
    {
        $user = $this->member();
        $this->post('/login', ['username' => $user->email, 'password' => 'password'])->assertRedirect(route('portal.home'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_incorrect_password_is_rejected_without_activity(): void
    {
        $this->member();
        $this->post('/login', ['username' => 'portal.luis', 'password' => 'wrong'])->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->assertDatabaseCount('user_activities', 0);
    }

    public function test_inactive_membership_cannot_sign_in(): void
    {
        $this->member(false);
        $this->post('/login', ['username' => 'portal.luis', 'password' => 'password'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_other_tenant_and_revoked_membership_cannot_enter_payments(): void
    {
        $user = $this->member(false);
        $other = Tenant::factory()->create();
        $user->tenants()->attach($other->id, ['is_active' => true]);
        $this->actingAs($user)->get('/pago-proveedores')->assertForbidden();
        $this->get('/inicio')->assertForbidden();
    }

    public function test_duplicate_username_is_rejected(): void
    {
        $this->member();
        User::factory()->create(['username' => 'portal.luis']);
        $this->post('/login', ['username' => 'portal.luis', 'password' => 'password'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $key = 'portal-login:'.hash('sha256', 'portal.luis|127.0.0.1');
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($key, 60);
        }
        $this->member();
        $this->post('/login', ['username' => 'portal.luis', 'password' => 'password'])->assertSessionHasErrors(['username' => 'Demasiados intentos. Intenta nuevamente en un minuto.']);
        $this->assertGuest();
    }

    public function test_home_only_displays_own_activity_and_escapes_user_name(): void
    {
        $user = $this->member();
        $user->update(['name' => '<script>alert(1)</script>']);
        $other = User::factory()->create();
        $tenantId = Tenant::query()->where('code', '4N')->value('id');
        DB::table('user_activities')->insert([
            ['user_id' => $user->id, 'tenant_id' => $tenantId, 'action' => 'Actividad propia', 'module' => 'Acceso', 'created_at' => now()],
            ['user_id' => $other->id, 'tenant_id' => $tenantId, 'action' => 'Actividad ajena', 'module' => 'Acceso', 'created_at' => now()],
        ]);
        $this->actingAs($user)->get('/inicio')->assertOk()->assertSee('Actividad propia')->assertDontSee('Actividad ajena')->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_profile_updates_own_data_without_changing_role(): void
    {
        $user = $this->member();
        $this->actingAs($user)->put('/mi-cuenta', ['name' => 'Nombre actualizado', 'email' => 'nuevo@example.com', 'phone' => '+56912345678', 'current_password' => 'password', 'profile_name' => 'admin', 'id' => 999])->assertSessionHas('status');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Nombre actualizado', 'email' => 'nuevo@example.com', 'phone' => '+56912345678', 'profile_name' => null]);
        $this->assertDatabaseHas('user_activities', ['user_id' => $user->id, 'action' => 'Datos personales actualizados']);
    }

    public function test_profile_requires_current_password_and_unique_email(): void
    {
        $user = $this->member();
        $other = User::factory()->create();
        $this->actingAs($user)->put('/mi-cuenta', ['name' => 'Cambio', 'email' => $other->email, 'current_password' => 'wrong'])->assertSessionHasErrors(['email', 'current_password']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Luis Prueba']);
    }

    public function test_password_change_hashes_password_and_removes_other_sessions(): void
    {
        $user = $this->member();
        DB::table('sessions')->insert(['id' => 'another-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($user)->put('/mi-cuenta/clave', ['current_password' => 'password', 'password' => 'new-strong-password', 'password_confirmation' => 'new-strong-password'])->assertSessionHas('status');
        $this->assertTrue(Hash::check('new-strong-password', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'another-session']);
        $this->assertDatabaseHas('user_activities', ['action' => 'Contraseña actualizada']);
        $this->get('/inicio')->assertOk();
    }

    public function test_password_change_rejects_wrong_current_password(): void
    {
        $user = $this->member();
        $this->actingAs($user)->put('/mi-cuenta/clave', ['current_password' => 'wrong', 'password' => 'new-strong-password', 'password_confirmation' => 'new-strong-password'])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_change_rejects_short_or_unconfirmed_password(): void
    {
        $user = $this->member();
        $this->actingAs($user)->put('/mi-cuenta/clave', ['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_module_navigation_records_activity_and_invalid_module_is_not_found(): void
    {
        $this->actingAs($this->member())->get('/modulos/flota')->assertOk()->assertSee('Flota');
        $this->assertDatabaseHas('user_activities', ['module' => 'Flota', 'action' => 'Consulta de Flota']);
        $this->get('/modulos/desconocido')->assertNotFound();
        $this->assertDatabaseCount('user_activities', 1);
    }

    public function test_payments_remains_accessible_to_member_and_records_visit(): void
    {
        $this->actingAs($this->member())->get('/pago-proveedores')->assertOk()->assertSee('Pago Proveedores')->assertSee('Luis Prueba');
        $this->assertDatabaseHas('user_activities', ['module' => 'Pago Proveedores']);
    }

    public function test_logout_ends_session_and_records_activity(): void
    {
        $user = $this->member();
        $this->actingAs($user)->post('/salir')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseHas('user_activities', ['user_id' => $user->id, 'action' => 'Cierre de sesión']);
    }
}
