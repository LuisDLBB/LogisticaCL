<?php

namespace Tests\Feature;

use App\Fleet\CoordinationService;
use App\Fleet\MicrosoftRetMail;
use App\Fleet\RetCode;
use App\Fleet\RetNotificationService;
use App\Models\Client;
use App\Models\ClientBranch;
use App\Models\ServiceRequest;
use App\Models\ServiceType;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RetRedesignTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $this->travelTo(Carbon::parse('2026-10-01 12:30:00', 'America/Santiago'));
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $actor = User::factory()->create();
        FleetTestMembership::insert(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'role_code' => 'administrator', 'is_active' => true]);
        $this->actingAs($actor)->withSession(['fleet_tenant_id' => $tenant->id]);
        $client = Client::create(['tenant_id' => $tenant->id, 'commercial_name' => 'Rendic Hermanos', 'legal_name' => 'Rendic SPA', 'tax_id' => '76543210-0', 'tax_id_number' => '76543210', 'tax_id_check_digit' => '0', 'is_active' => true]);
        $point = ClientBranch::create(['client_id' => $client->id, 'code' => 'R01', 'name' => 'Bañados', 'address' => 'Dorsal 3225', 'commune_name' => 'Renca', 'address_status' => 'ready', 'operational_emails' => 'contacto@example.test', 'is_active' => true]);
        $type = ServiceType::create(['service_code' => 990, 'name' => 'Retiro', 'is_active' => true]);
        $client->serviceTypes()->attach($type->id, ['is_active' => true]);
        $driver = User::factory()->create(['profile_name' => 'App Conductor', 'license_type' => 'B']);
        FleetTestMembership::insert(['tenant_id' => $tenant->id, 'user_id' => $driver->id, 'role_code' => 'app_conductor', 'is_active' => true]);
        $vehicle = Vehicle::create(['tenant_id' => $tenant->id, 'rut_empresa' => $tenant->tax_id, 'company_source' => '4N', 'internal_code' => 'RED001', 'plate' => 'RETT11', 'max_weight_kg' => 3500, 'vehicle_type' => 'Furgón', 'ownership_type' => 'Propio', 'operational_status' => 'Activa', 'is_active' => true]);

        return compact('tenant', 'actor', 'client', 'point', 'type', 'driver', 'vehicle');
    }

    private function payload(array $c): array
    {
        return ['client_id' => $c['client']->id, 'client_branch_id' => $c['point']->id, 'service_type_id' => $c['type']->id, 'service_date' => '2026-10-02', 'window_start' => '09:00', 'window_end' => '10:00', 'shift' => 'AM', 'packages' => 5, 'requesting_area' => 'Comercial', 'pickup_kind' => 'Spot', 'access_reference_snapshot' => 'Portón azul'];
    }

    private function createRet(array $c): ServiceRequest
    {
        $this->post(route('fleet.requests.store'), $this->payload($c))->assertSessionHasNoErrors();

        return ServiceRequest::latest('id')->firstOrFail();
    }

    public function test_name_and_original_snapshot_are_automatic_without_material_input(): void
    {
        $c = $this->context();
        $ret = $this->createRet($c);
        $this->assertSame(RetCode::format($ret->id), $ret->pickup_name);
        $this->assertSame(5, $ret->original_data['packages']);
        $this->assertSame('Portón azul', $ret->access_reference_snapshot);
        $this->get(route('fleet.requests.create'))->assertOk()->assertSee('Agendar retiro con operaciones')->assertDontSee('name="material"', false);
        $this->get(route('fleet.requests.show', $ret))->assertOk()->assertSee('Antecedentes originales protegidos')->assertSee('Guardar decisión');
        $this->get(route('fleet.page.coordination-requests'))->assertOk()->assertSee('Pendientes de programar')->assertSee('Mañana')->assertSee('data-ret-modal', false);
    }

    public function test_packages_use_point_history_excluding_tests_and_cancelled_requests(): void
    {
        $c = $this->context();
        $url = route('fleet.clients.points', $c['client']);
        $this->getJson($url)->assertJsonPath('0.suggested_packages', 5);
        $real = $this->createRet($c);
        $real->update(['packages' => 8, 'actual_packages' => 7, 'status' => 'retired']);
        $test = $this->createRet($c);
        $test->update(['is_test' => true, 'packages' => 99]);
        $this->getJson($url)->assertJsonPath('0.suggested_packages', 7);
        $real->update(['status' => 'cancelled']);
        $this->getJson($url)->assertJsonPath('0.suggested_packages', 5);
    }

    public function test_operations_assignment_is_atomic_and_preserves_original_date(): void
    {
        $c = $this->context();
        $ret = $this->createRet($c);
        $data = ['decision' => 'scheduled', 'weight_warning_acknowledged' => true, 'service_date' => '2026-10-03', 'shift' => 'AM/PM', 'window_start' => '11:00', 'window_end' => '12:00', 'driver_user_id' => $c['driver']->id, 'vehicle_id' => $c['vehicle']->id];
        $c['driver']->license_type = 'C';
        $c['driver']->save();
        $this->post(route('fleet.requests.decision', $ret), $data)->assertSessionHasErrors();
        $this->assertSame('2026-10-02', $ret->fresh()->service_date->toDateString());
        $this->assertDatabaseCount('ret_notifications', 0);
        $c['driver']->license_type = 'B';
        $c['driver']->save();
        $this->post(route('fleet.requests.decision', $ret), $data)->assertSessionHasNoErrors();
        $ret->refresh();
        $this->assertSame('scheduled', $ret->status);
        $this->assertSame('2026-10-03', $ret->service_date->toDateString());
        $this->assertSame('2026-10-02', $ret->requested_date_original->toDateString());
        $this->assertSame('2026-10-02', Carbon::parse($ret->original_data['service_date'])->toDateString());
        $this->assertDatabaseCount('ret_notifications', 1);
        $this->post(route('fleet.requests.decision', $ret), $data)->assertStatus(409);
        $this->assertDatabaseCount('ret_notifications', 1);
    }

    public function test_correction_requires_reason_and_requester_can_resubmit(): void
    {
        $c = $this->context();
        $ret = $this->createRet($c);
        $this->post(route('fleet.requests.decision', $ret), ['decision' => 'correction_requested'])->assertSessionHasErrors('operations_notes');
        $this->post(route('fleet.requests.decision', $ret), ['decision' => 'correction_requested', 'operations_notes' => 'Precisar bultos'])->assertSessionHasNoErrors();
        $this->assertSame('correction_requested', $ret->fresh()->status);
        $this->assertDatabaseCount('ret_notifications', 0);
        $this->put(route('fleet.requests.update', $ret), array_replace($this->payload($c), ['packages' => 10, 'pickup_name' => $ret->pickup_name, 'effective_address' => 'Dorsal 3225', 'reason' => 'Cliente confirma 10', 'material' => null]))->assertSessionHasNoErrors();
        $this->assertSame('requested', $ret->fresh()->status);
        $this->assertSame(5, $ret->fresh()->original_data['packages']);
        $this->assertSame(10, $ret->fresh()->packages);
    }

    public function test_merchant_profile_reuses_master_and_can_disable_all_services(): void
    {
        $c = $this->context();
        $total = Client::count();
        $this->get(route('fleet.page.admin-masters', ['search' => 'Rendic Hermanos']))->assertOk()->assertSee('Rendic Hermanos');
        $this->get(route('fleet.merchants.show', $c['client']))->assertOk()->assertSee('Bañados');
        $this->put(route('fleet.ret.clients.services', $c['client']), ['services' => []])->assertSessionHasNoErrors();
        $this->getJson(route('fleet.clients.services', $c['client']))->assertJsonCount(0, 'services');
        $this->post(route('fleet.requests.store'), $this->payload($c))->assertSessionHasNoErrors();
        $this->put(route('fleet.merchants.update', $c['client']), ['commercial_name' => 'Rendic Hermanos', 'legal_name' => 'Rendic SPA', 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($c['client']->fresh()->is_active);
        $this->assertDatabaseCount('MBA_clients', $total);
        $other = Tenant::where('code', 'PMCB')->firstOrFail();
        FleetTestMembership::insert(['tenant_id' => $other->id, 'user_id' => $c['actor']->id, 'role_code' => 'administrator', 'is_active' => true]);
        $this->withSession(['fleet_tenant_id' => $other->id])->get(route('fleet.merchants.show', $c['client']))->assertNotFound();
    }

    public function test_service_activation_returns_to_the_same_merchant_with_a_saved_signal(): void
    {
        $c = $this->context();
        $this->put(route('fleet.ret.clients.services', $c['client']), ['services' => [$c['type']->id], 'return_to_request' => 1])
            ->assertRedirect(route('fleet.merchants.show', ['client' => $c['client'], 'return_to_request' => 1]))
            ->assertSessionHas('ret_services_saved', true);
        $this->get(route('fleet.merchants.show', ['client' => $c['client'], 'return_to_request' => 1]))
            ->assertOk()->assertSee('ret-services-saved', false);
        $this->getJson(route('fleet.clients.services', $c['client']))->assertJsonPath('services.0.id', $c['type']->id);
    }

    public function test_variable_request_uses_actor_area_and_retirement_service_without_user_selection(): void
    {
        $c = $this->context();
        $c['actor']->forceFill(['area' => 'Comercial'])->save();
        DB::table('PPR_client_service_type')->where('client_id', $c['client']->id)->delete();
        $payload = $this->payload($c);
        unset($payload['service_type_id']);
        $payload['requesting_area'] = 'Operaciones';
        $payload['pickup_kind'] = 'Fijo';
        $payload['load_unit'] = 'pallets';
        $payload['pallet_type'] = 'european';
        $payload['pallet_height_cm'] = 150;
        $payload['contact_name_snapshot'] = 'Contacto editable';

        $this->post(route('fleet.requests.store'), $payload)->assertSessionHasNoErrors();

        $ret = ServiceRequest::latest('id')->firstOrFail();
        $this->assertSame('Comercial', $ret->requesting_area);
        $this->assertSame('Variable', $ret->pickup_kind);
        $this->assertSame($c['type']->id, $ret->service_type_id);
        $this->assertSame('pallets', $ret->original_data['load_unit']);
        $this->assertSame('Contacto editable', $ret->contact_name_snapshot);
        $this->get(route('fleet.requests.create'))->assertSee('Retiro variable')
            ->assertDontSee('name="requesting_area"', false)->assertDontSee('name="pickup_kind"', false)
            ->assertDontSee('Servicio habilitado')->assertDontSee('Contacto del punto');
    }

    public function test_window_reference_uses_last_scheduled_request_for_the_same_point(): void
    {
        $c = $this->context();
        $url = route('fleet.clients.points', $c['client']);
        $this->getJson($url)->assertJsonPath('0.suggested_window_start', '00:00')->assertJsonPath('0.suggested_window_end', '00:00');
        $ret = $this->createRet($c);
        $ret->update(['status' => 'scheduled', 'scheduled_at' => now(), 'window_start' => '14:00', 'window_end' => '15:30', 'shift' => 'PM']);
        $test = $this->createRet($c);
        $test->update(['status' => 'scheduled', 'scheduled_at' => now()->addMinute(), 'is_test' => true, 'window_start' => '16:00', 'window_end' => '17:00']);
        $this->createRet($c);

        $this->getJson($url)->assertJsonPath('0.suggested_window_start', '14:00')
            ->assertJsonPath('0.suggested_window_end', '15:30')->assertJsonPath('0.suggested_shift', 'PM');
        $ret->update(['status' => 'cancelled']);
        $this->getJson($url)->assertJsonPath('0.suggested_window_start', '00:00');
    }

    public function test_contact_history_is_specific_to_point_and_excludes_tests_and_cancelled_requests(): void
    {
        $c = $this->context();
        $ret = $this->createRet($c);
        $ret->update(['contact_name_snapshot' => 'Ana contacto', 'contact_phone_snapshot' => '+56912345678']);
        $test = $this->createRet($c);
        $test->update(['is_test' => true, 'contact_name_snapshot' => 'Prueba', 'contact_phone_snapshot' => '999']);
        $cancelled = $this->createRet($c);
        $cancelled->update(['status' => 'cancelled', 'contact_name_snapshot' => 'Anulado', 'contact_phone_snapshot' => '888']);

        $this->getJson(route('fleet.clients.points', $c['client']))
            ->assertJsonPath('0.suggested_contact_name', 'Ana contacto')->assertJsonPath('0.suggested_contact_phone', '+56912345678');
    }

    public function test_pallet_type_and_height_are_required_and_saved_in_original_snapshot(): void
    {
        $c = $this->context();
        $payload = $this->payload($c) + ['load_unit' => 'pallets'];

        $this->post(route('fleet.requests.store'), $payload)->assertSessionHasErrors(['pallet_type', 'pallet_height_cm']);
        $this->assertDatabaseCount('service_requests', 0);
        $this->post(route('fleet.requests.store'), $payload + ['pallet_type' => 'american', 'pallet_height_cm' => 145.5])->assertSessionHasNoErrors();

        $ret = ServiceRequest::latest('id')->firstOrFail();
        $this->assertSame('american', $ret->original_data['pallet_type']);
        $this->assertSame('145.50', $ret->original_data['pallet_height_cm']);
        $this->get(route('fleet.requests.show', $ret))->assertSee('Americano · 120 × 100')->assertSee('145.50 cm');
    }

    public function test_loose_packages_ignore_pallet_information(): void
    {
        $c = $this->context();

        $this->post(route('fleet.requests.store'), $this->payload($c) + ['load_unit' => 'loose', 'pallet_type' => 'invalid', 'pallet_height_cm' => -1])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('service_requests', ['load_unit' => 'loose', 'pallet_type' => null, 'pallet_height_cm' => null]);
    }

    private function lateApprovers(array $c): array
    {
        $ops = User::factory()->create();
        $manager = User::factory()->create(['email' => 'hansdelabarra@4nlogistica.cl']);
        foreach ([$ops, $manager] as $user) {
            FleetTestMembership::insert(['tenant_id' => $c['tenant']->id, 'user_id' => $user->id, 'role_code' => $user === $ops ? 'operations' : 'administrator', 'is_active' => true]);
        }

        foreach (['operations' => $ops, 'commercial' => $manager] as $area => $user) {
            DB::table('fleet_pickup_approvers')->insert(['tenant_id' => $c['tenant']->id, 'user_id' => $user->id, 'area' => $area, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$ops, $manager];
    }

    private function lateAgenda(array $c): array
    {
        return ['decision' => 'scheduled', 'weight_warning_acknowledged' => true, 'service_date' => '2026-10-02', 'shift' => 'PM', 'window_start' => '15:00', 'window_end' => '16:00', 'driver_user_id' => $c['driver']->id, 'vehicle_id' => $c['vehicle']->id];
    }

    public function test_late_request_requires_both_areas_before_programming(): void
    {
        $c = $this->context();
        [$ops, $manager] = $this->lateApprovers($c);
        $this->travelTo(Carbon::parse('2026-10-02 12:31:00', 'America/Santiago'));
        $ret = $this->createRet($c);
        $this->assertSame('pending', $ret->late_pickup_authorization['operations']['status']);
        $this->get(route('fleet.requests.show', $ret))->assertSee('las dos aprobaciones');
        $this->post(route('fleet.requests.decision', $ret), $this->lateAgenda($c))->assertSessionHasErrors('late_pickup');
        $this->assertNull($ret->fresh()->driver_user_id);
        $this->assertDatabaseCount('ret_notifications', 0);
        $this->actingAs($ops)->post(route('fleet.requests.late.decide', $ret), ['area' => 'operations', 'decision' => 'approved', 'notes' => 'Ruta permite el retiro'])->assertSessionHasNoErrors();
        $this->post(route('fleet.requests.decision', $ret), $this->lateAgenda($c))->assertSessionHasErrors('late_pickup');
        $this->actingAs($manager)->get(route('fleet.requests.late.index'))->assertOk()->assertSee($ret->ret_code);
        $this->get(route('fleet.requests.show', $ret))->assertOk()->assertSee('Aprobar Gerente comercial');
        $this->post(route('fleet.requests.late.decide', $ret), ['area' => 'commercial', 'decision' => 'approved', 'notes' => 'Cliente requiere hoy'])->assertSessionHasNoErrors();
        $this->actingAs($ops)->post(route('fleet.requests.decision', $ret), $this->lateAgenda($c))->assertSessionHasNoErrors();
        $this->assertSame('scheduled', $ret->fresh()->status);
        $this->assertDatabaseCount('ret_notifications', 1);
    }

    public function test_either_rejection_blocks_today_and_client_can_agree_to_next_day(): void
    {
        $c = $this->context();
        [$ops, $manager] = $this->lateApprovers($c);
        $this->travelTo(Carbon::parse('2026-10-02 13:00:00', 'America/Santiago'));
        foreach (['operations' => $ops, 'commercial' => $manager] as $area => $actor) {
            $this->actingAs($c['actor']);
            $ret = $this->createRet($c);
            $this->actingAs($actor)->post(route('fleet.requests.late.decide', $ret), ['area' => $area, 'decision' => 'rejected', 'notes' => 'No procede hoy'])->assertSessionHasNoErrors();
            $this->actingAs($ops)->post(route('fleet.requests.decision', $ret), $this->lateAgenda($c))->assertSessionHasErrors('late_pickup');
            $this->assertSame('requested', $ret->fresh()->status);
            $this->post(route('fleet.requests.late.request', $ret), ['date' => '2026-10-02', 'reason' => 'Intento nuevo'])->assertSessionHasErrors('late_pickup');
            $this->actingAs($manager)->post(route('fleet.requests.late.decide', $ret), ['area' => 'commercial', 'decision' => 'approved', 'notes' => 'Intento posterior'])->assertSessionHasErrors('late_pickup');
            $this->actingAs($ops)->post(route('fleet.requests.decision', $ret), array_replace($this->lateAgenda($c), ['service_date' => '2026-10-03']))->assertSessionHasErrors('customer_date_confirmed');
            $this->actingAs($ops)->post(route('fleet.requests.decision', $ret), array_replace($this->lateAgenda($c), ['service_date' => '2026-10-03', 'operations_notes' => 'Cliente acepta mañana', 'customer_date_confirmed' => true]))->assertSessionHasNoErrors();
            $this->assertSame('2026-10-03', $ret->fresh()->service_date->toDateString());
            $this->assertSame('2026-10-03', $ret->fresh()->late_pickup_authorization['customer_date_confirmation']['date']);
            $this->assertSame('2026-10-02', $ret->fresh()->requested_date_original->toDateString());
        }
    }

    public function test_cutoff_uses_santiago_and_blocks_existing_requests_after_1230(): void
    {
        $c = $this->context();
        $this->travelTo(Carbon::parse('2026-10-02 12:30:00', 'America/Santiago'));
        $ret = $this->createRet($c);
        $this->assertNull($ret->late_pickup_authorization);
        $this->travelTo(Carbon::parse('2026-10-02 15:30:01', 'UTC'));
        $this->post(route('fleet.requests.decision', $ret), $this->lateAgenda($c))->assertSessionHasErrors('late_pickup');
        $this->post(route('fleet.requests.late.request', $ret), ['date' => '2026-10-02', 'reason' => 'Cliente pide hoy'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-02', $ret->fresh()->late_pickup_authorization['date']);
    }

    public function test_admin_cannot_impersonate_either_approver_and_tenants_are_isolated(): void
    {
        $c = $this->context();
        [$ops, $manager] = $this->lateApprovers($c);
        $this->travelTo(Carbon::parse('2026-10-02 13:00:00', 'America/Santiago'));
        $ret = $this->createRet($c);
        foreach (['commercial', 'operations'] as $area) {
            $this->post(route('fleet.requests.late.decide', $ret), ['area' => $area, 'decision' => 'approved', 'notes' => 'No autorizado'])->assertForbidden();
        }
        $other = Tenant::where('code', 'PMCB')->firstOrFail();
        FleetTestMembership::insert(['tenant_id' => $other->id, 'user_id' => $ops->id, 'role_code' => 'operations', 'is_active' => true]);
        $this->actingAs($ops)->withSession(['fleet_tenant_id' => $other->id])->post(route('fleet.requests.late.decide', $ret), ['area' => 'operations', 'decision' => 'approved', 'notes' => 'Otra empresa'])->assertNotFound();
        $this->get(route('fleet.requests.late.index'))->assertOk()->assertDontSee($ret->ret_code);
    }

    public function test_approvals_expire_and_cannot_authorize_another_day(): void
    {
        $c = $this->context();
        [$ops, $manager] = $this->lateApprovers($c);
        $this->travelTo(Carbon::parse('2026-10-02 13:00:00', 'America/Santiago'));
        $ret = $this->createRet($c);
        foreach (['operations' => $ops, 'commercial' => $manager] as $area => $actor) {
            $this->actingAs($actor)->post(route('fleet.requests.late.decide', $ret), ['area' => $area, 'decision' => 'approved', 'notes' => 'Autorizado hoy'])->assertSessionHasNoErrors();
        }
        $this->travelTo(Carbon::parse('2026-10-03 13:00:00', 'America/Santiago'));
        $this->actingAs($ops)->post(route('fleet.requests.decision', $ret), array_replace($this->lateAgenda($c), ['service_date' => '2026-10-03']))->assertSessionHasErrors('late_pickup');
        $this->assertSame('requested', $ret->fresh()->status);
        $this->assertDatabaseCount('ret_notifications', 0);
    }

    public function test_legacy_schedule_route_enforces_cutoff_and_exact_1230_is_allowed(): void
    {
        $c = $this->context();
        $this->travelTo(Carbon::parse('2026-10-02 12:30:00', 'America/Santiago'));
        $ret = $this->createRet($c);
        $this->post(route('fleet.requests.decision', $ret), $this->lateAgenda($c))->assertSessionHasNoErrors();
        $this->assertSame('scheduled', $ret->fresh()->status);
        $pending = $this->createRet($c);
        $pending->update(['driver_user_id' => $c['driver']->id, 'vehicle_id' => $c['vehicle']->id]);
        $this->travelTo(Carbon::parse('2026-10-02 12:30:01', 'America/Santiago'));
        $this->post(route('fleet.requests.schedule', $pending))->assertSessionHasErrors('late_pickup');
        $this->assertSame('requested', $pending->fresh()->status);
    }

    public function test_legacy_route_cannot_skip_customer_confirmation_after_rejection(): void
    {
        $c = $this->context();
        [$ops, $manager] = $this->lateApprovers($c);
        $this->travelTo(Carbon::parse('2026-10-02 13:00:00', 'America/Santiago'));
        $ret = $this->createRet($c);
        $this->actingAs($manager)->post(route('fleet.requests.late.decide', $ret), ['area' => 'commercial', 'decision' => 'rejected', 'notes' => 'No procede hoy'])->assertSessionHasNoErrors();
        $ret->update(['service_date' => '2026-10-03', 'driver_user_id' => $c['driver']->id, 'vehicle_id' => $c['vehicle']->id]);
        $this->actingAs($ops)->post(route('fleet.requests.schedule', $ret))->assertSessionHasErrors('customer_date_confirmed');
        $this->assertSame('requested', $ret->fresh()->status);
        $this->assertDatabaseCount('ret_notifications', 0);
    }

    public function test_administrator_can_change_cutoff_and_runtime_and_screen_use_new_time(): void
    {
        $c = $this->context();
        $this->get(route('fleet.scheduling.settings'))->assertOk()->assertSee('Matriz de aprobadores');
        $this->put(route('fleet.scheduling.cutoff.update'), ['pickup_cutoff_time' => '13:00'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('fleet_scheduling_settings', ['tenant_id' => $c['tenant']->id, 'pickup_cutoff_time' => '13:00:00', 'pickup_authorization_revision' => 2]);
        $this->travelTo(Carbon::parse('2026-10-02 12:45:00', 'America/Santiago'));
        $ret = $this->createRet($c);
        $this->assertNull($ret->late_pickup_authorization);
        $this->get(route('fleet.requests.create'))->assertSee('después de las 13:00');
        $this->travelTo(Carbon::parse('2026-10-02 13:01:00', 'America/Santiago'));
        $this->post(route('fleet.requests.decision', $ret), $this->lateAgenda($c))->assertSessionHasErrors('late_pickup');
        $this->assertDatabaseHas('fleet_permission_audits', ['action' => 'pickup_cutoff_updated', 'actor_user_id' => $c['actor']->id]);
    }

    public function test_approvers_can_be_added_by_email_removed_and_reactivated_without_duplicates(): void
    {
        $c = $this->context();
        [$ops, $manager] = $this->lateApprovers($c);
        $extra = User::factory()->create(['email' => 'suplente@example.test']);
        FleetTestMembership::insert(['tenant_id' => $c['tenant']->id, 'user_id' => $extra->id, 'role_code' => 'management', 'is_active' => true]);
        $this->post(route('fleet.scheduling.approvers.add'), ['email' => 'SUPLENTE@example.test', 'area' => 'commercial'])->assertSessionHasNoErrors();
        $approver = DB::table('fleet_pickup_approvers')->where('user_id', $extra->id)->first();
        $this->assertSame('commercial', $approver->area);
        $this->post(route('fleet.scheduling.approvers.add'), ['email' => $extra->email, 'area' => 'commercial'])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('fleet_pickup_approvers')->where('user_id', $extra->id)->count());
        $this->travelTo(Carbon::parse('2026-10-02 13:00:00', 'America/Santiago'));
        $ret = $this->createRet($c);
        $this->actingAs($extra)->get(route('fleet.requests.late.index'))->assertOk()->assertSee('Decidir Gerencia comercial');
        $this->get(route('fleet.requests.show', $ret))->assertForbidden();
        $this->post(route('fleet.requests.late.decide', $ret), ['area' => 'commercial', 'decision' => 'approved', 'notes' => 'Suplencia autorizada'])->assertSessionHasNoErrors();
        $this->actingAs($c['actor'])->patch(route('fleet.scheduling.approvers.toggle', $approver->id), ['is_active' => false])->assertSessionHasNoErrors();
        $this->actingAs($extra)->post(route('fleet.requests.late.decide', $ret), ['area' => 'commercial', 'decision' => 'approved', 'notes' => 'Sin acceso'])->assertForbidden();
        $this->get(route('fleet.requests.late.index'))->assertForbidden();
        $this->assertDatabaseHas('fleet_pickup_approvers', ['id' => $approver->id, 'is_active' => false]);
        $this->assertSame('approved', $ret->fresh()->late_pickup_authorization['commercial']['status']);
        $this->actingAs($c['actor'])->patch(route('fleet.scheduling.approvers.toggle', $approver->id), ['is_active' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('fleet_pickup_approvers', ['id' => $approver->id, 'is_active' => true]);
    }

    public function test_configuration_requires_admin_and_valid_active_accounts_of_same_tenant(): void
    {
        $c = $this->context();
        [$ops, $manager] = $this->lateApprovers($c);
        $this->put(route('fleet.scheduling.cutoff.update'), ['pickup_cutoff_time' => '25:00'])->assertSessionHasErrors('pickup_cutoff_time');
        $this->post(route('fleet.scheduling.approvers.add'), ['email' => 'unknown@example.test', 'area' => 'commercial'])->assertSessionHasErrors('email');
        $other = Tenant::where('code', 'PMCB')->firstOrFail();
        $outsider = User::factory()->create();
        FleetTestMembership::insert(['tenant_id' => $other->id, 'user_id' => $outsider->id, 'role_code' => 'operations', 'is_active' => true]);
        $this->post(route('fleet.scheduling.approvers.add'), ['email' => $outsider->email, 'area' => 'operations'])->assertSessionHasErrors('email');
        $foreign = DB::table('fleet_pickup_approvers')->insertGetId(['tenant_id' => $other->id, 'user_id' => $outsider->id, 'area' => 'operations', 'is_active' => true]);
        $this->patch(route('fleet.scheduling.approvers.toggle', $foreign), ['is_active' => false])->assertNotFound();
        $this->actingAs($ops)->put(route('fleet.scheduling.cutoff.update'), ['pickup_cutoff_time' => '13:00'])->assertForbidden();
        $this->post(route('fleet.scheduling.approvers.add'), ['email' => $ops->email, 'area' => 'commercial'])->assertForbidden();
        $this->get(route('fleet.scheduling.settings'))->assertForbidden();
    }

    public function test_policy_change_requires_renewal_and_preserves_previous_decisions_in_audit(): void
    {
        $c = $this->context();
        [$ops, $manager] = $this->lateApprovers($c);
        $this->travelTo(Carbon::parse('2026-10-02 14:00:00', 'America/Santiago'));
        $ret = $this->createRet($c);
        foreach (['operations' => $ops, 'commercial' => $manager] as $area => $actor) {
            $this->actingAs($actor)->post(route('fleet.requests.late.decide', $ret), ['area' => $area, 'decision' => 'approved', 'notes' => 'Autorizado'])->assertSessionHasNoErrors();
        }
        $this->actingAs($c['actor'])->put(route('fleet.scheduling.cutoff.update'), ['pickup_cutoff_time' => '13:00'])->assertSessionHasNoErrors();
        $this->actingAs($ops)->post(route('fleet.requests.decision', $ret), $this->lateAgenda($c))->assertSessionHasErrors('late_pickup');
        $this->get(route('fleet.requests.show', $ret))->assertSee('Renueva esta excepción');
        $this->post(route('fleet.requests.late.request', $ret), ['date' => '2026-10-02', 'reason' => 'Renovar aprobaciones'])->assertSessionHasNoErrors();
        $this->assertSame('pending', $ret->fresh()->late_pickup_authorization['operations']['status']);
        $event = DB::table('service_request_events')->where('service_request_id', $ret->id)->where('action', 'late_pickup_requested')->latest('id')->first();
        $this->assertSame('approved', json_decode($event->details, true)['previous_authorization']['operations']['status']);
    }

    public function test_deactivated_account_cannot_leave_a_valid_approval(): void
    {
        $c = $this->context();
        [$ops, $manager] = $this->lateApprovers($c);
        $this->travelTo(Carbon::parse('2026-10-02 14:00:00', 'America/Santiago'));
        $ret = $this->createRet($c);
        foreach (['operations' => $ops, 'commercial' => $manager] as $area => $actor) {
            $this->actingAs($actor)->post(route('fleet.requests.late.decide', $ret), ['area' => $area, 'decision' => 'approved', 'notes' => 'Autorizado'])->assertSessionHasNoErrors();
        }
        DB::table('MBA_tenant_users')->where('user_id', $manager->id)->update(['is_active' => false]);
        $this->actingAs($ops)->post(route('fleet.requests.decision', $ret), $this->lateAgenda($c))->assertSessionHasErrors('late_pickup');
        $this->assertSame('requested', $ret->fresh()->status);
    }

    public function test_customer_code_uses_modulo_11_in_subject_and_body_and_preserves_legacy_storage(): void
    {
        $c = $this->context();
        $ret = $this->createRet($c);
        $this->assertSame('4N-RET-00017-5', RetCode::format(17));
        $this->assertSame('4N-RET-00006-K', RetCode::format(6));
        $this->assertSame('4N-RET-00014-0', RetCode::format(14));
        $this->assertSame('4N-RET-12345678-5', RetCode::format(12345678));
        $ret->update(['ret_code' => 'RET-'.str_pad((string) $ret->id, 5, '0', STR_PAD_LEFT)]);
        $this->assertStringStartsWith('RET-', $ret->fresh()->getRawOriginal('ret_code'));
        $this->assertSame(RetCode::format($ret->id), $ret->fresh()->ret_code);
        $this->post(route('fleet.requests.decision', $ret), $this->lateAgenda($c))->assertSessionHasNoErrors();
        $mail = DB::table('ret_notifications')->where('service_request_id', $ret->id)->first();
        $this->assertStringStartsWith($ret->ret_code.' | Retiro agendado', $mail->subject);
        $this->assertStringContainsString('**'.$ret->ret_code.'**', $mail->body);
        $this->get(route('fleet.requests.create'))->assertSee('Código del retiro')->assertDontSee('Iniciales del cliente');
    }

    public function test_review_shows_exception_only_for_today_after_configured_cutoff(): void
    {
        $c = $this->context();
        $ret = $this->createRet($c);
        $this->get(route('fleet.requests.show', $ret))->assertDontSee('Excepción para retiro del mismo día');
        $this->travelTo(Carbon::parse('2026-10-02 12:30:00', 'America/Santiago'));
        $this->get(route('fleet.requests.show', $ret))->assertDontSee('Excepción para retiro del mismo día');
        $this->travelTo(Carbon::parse('2026-10-02 12:31:00', 'America/Santiago'));
        $this->get(route('fleet.requests.show', $ret))->assertSee('Excepción para retiro del mismo día');
        $this->put(route('fleet.scheduling.cutoff.update'), ['pickup_cutoff_time' => '13:00'])->assertSessionHasNoErrors();
        $this->get(route('fleet.requests.show', $ret))->assertDontSee('Excepción para retiro del mismo día');
    }

    public function test_review_has_contact_above_agenda_spanish_events_and_change_dialog(): void
    {
        $c = $this->context();
        $ret = $this->createRet($c);
        foreach (['late_pickup_requested', 'late_pickup_approved', 'late_pickup_rejected'] as $action) {
            app(CoordinationService::class)->event($ret, $c['actor'], $action, ['area' => 'commercial', 'notes' => 'Motivo registrado']);
        }
        $this->get(route('fleet.requests.show', $ret))->assertOk()
            ->assertSeeInOrder(['Antecedentes originales protegidos', 'Contacto e instrucciones del retiro', 'Agenda de Operaciones'])
            ->assertSee('Solicitar reagendamiento')->assertSee('id="ret-change-dialog"', false)
            ->assertSee('Autorización fuera de horario solicitada')->assertSee('Autorización fuera de horario aprobada')->assertSee('Autorización fuera de horario rechazada')
            ->assertDontSee('late_pickup_requested')->assertDontSee('Corregir o editar solicitud y reenviar a Operaciones');
    }

    public function test_change_checklist_updates_only_selected_items_and_keeps_original_snapshot(): void
    {
        $c = $this->context();
        $ret = $this->createRet($c);
        $original = $ret->original_data;
        $this->put(route('fleet.requests.update', $ret), array_replace($this->payload($c), ['change_items' => ['packages'], 'pickup_name' => $ret->pickup_name, 'packages' => 9, 'service_date' => '2026-10-09', 'effective_address' => 'Dirección distinta', 'reason' => 'Solo cambia cantidad']))->assertSessionHasNoErrors();
        $this->assertSame(9, $ret->fresh()->packages);
        $this->assertSame('2026-10-02', $ret->fresh()->service_date->toDateString());
        $this->assertSame('Dorsal 3225', $ret->fresh()->effective_address);
        $this->assertSame($original, $ret->fresh()->original_data);
        $this->put(route('fleet.requests.update', $ret), array_replace($this->payload($c), ['change_items' => [], 'pickup_name' => $ret->pickup_name, 'effective_address' => 'Dorsal 3225', 'reason' => 'Sin ítems']))->assertSessionHasErrors('change_items');
    }

    public function test_weight_warning_requires_confirmation_and_records_snapshot_without_bypassing_other_checks(): void
    {
        $c = $this->context();
        $c['vehicle']->update(['payload_capacity_kg' => 200, 'capacity_m3' => 10]);
        foreach ([250, null] as $weight) {
            $ret = $this->createRet($c);
            $ret->update(['requested_weight_kg' => $weight]);
            $data = ['decision' => 'scheduled', 'service_date' => '2026-10-02', 'shift' => 'AM', 'window_start' => '09:00', 'window_end' => '10:00', 'driver_user_id' => $c['driver']->id, 'vehicle_id' => $c['vehicle']->id];
            $this->post(route('fleet.requests.decision', $ret), $data)->assertSessionHasErrors('weight_warning_acknowledged');
            $this->assertSame('requested', $ret->fresh()->status);
            $this->assertNull($ret->fresh()->vehicle_id);
            $this->post(route('fleet.requests.decision', $ret), $data + ['weight_warning_acknowledged' => true])->assertSessionHasNoErrors();
            $this->assertSame('scheduled', $ret->fresh()->status);
            $event = DB::table('service_request_events')->where('service_request_id', $ret->id)->where('action', 'weight_warning_confirmed')->firstOrFail();
            $this->assertSame($c['actor']->id, $event->actor_user_id);
            $this->get(route('fleet.requests.show', $ret))->assertOk()->assertSee('Advertencia de carga útil confirmada')->assertSee('RETT11');
        }
        $ret = $this->createRet($c);
        $ret->update(['requested_weight_kg' => 250, 'requested_volume_m3' => 12]);
        $this->post(route('fleet.requests.decision', $ret), $data + ['weight_warning_acknowledged' => true])->assertSessionHasErrors('vehicle_id');
        $this->assertSame('requested', $ret->fresh()->status);
        $this->assertFalse(DB::table('service_request_events')->where('service_request_id', $ret->id)->where('action', 'weight_warning_confirmed')->exists());
        $ret->update(['requested_volume_m3' => null]);
        $c['vehicle']->update(['payload_capacity_kg' => null]);
        $this->post(route('fleet.requests.decision', $ret), $data)->assertSessionHasErrors('weight_warning_acknowledged');
        $this->post(route('fleet.requests.decision', $ret), $data + ['weight_warning_acknowledged' => true])->assertSessionHasNoErrors();
    }

    public function test_creation_redirects_to_requester_confirmation_without_operations_agenda(): void
    {
        $c = $this->context();
        $response = $this->post(route('fleet.requests.store'), $this->payload($c));
        $ret = ServiceRequest::latest('id')->firstOrFail();
        $confirmation = route('fleet.requests.show', ['serviceRequest' => $ret, 'vista' => 'solicitante']);
        $response->assertRedirect($confirmation)->assertSessionHas('status', 'Tu solicitud fue creada con el código '.$ret->ret_code.' y está pendiente de programación por Operaciones.');
        $this->get($confirmation)->assertOk()->assertSee('Antecedentes originales protegidos')->assertSee('Editar solicitud')->assertDontSee('Agenda de Operaciones')->assertDontSee('Guardar decisión');
        $this->get(route('fleet.requests.show', $ret))->assertOk()->assertSee('Agenda de Operaciones')->assertSee('Guardar decisión');
    }

    public function test_requester_without_operations_permission_never_sees_operations_agenda(): void
    {
        $c = $this->context();
        $ret = $this->createRet($c);
        $membership = DB::table('MBA_tenant_users')->where('user_id', $c['actor']->id)->where('tenant_id', $c['tenant']->id)->value('id');
        DB::table('fleet_user_permission_overrides')->insert(['tenant_user_id' => $membership, 'permission_code' => 'operations.scheduling', 'access_level' => 0]);
        $this->get(route('fleet.requests.show', $ret))->assertOk()->assertSee('Editar solicitud')->assertDontSee('Agenda de Operaciones')->assertDontSee('Guardar decisión');
    }

    public function test_unavailable_plate_is_disabled_and_cannot_be_scheduled_even_with_weight_confirmation(): void
    {
        $c = $this->context();
        $ret = $this->createRet($c);
        $c['vehicle']->update(['operational_status' => 'En reparación']);
        $this->get(route('fleet.requests.show', $ret))->assertOk()->assertSee('No disponible: En reparación');
        $data = ['decision' => 'scheduled', 'weight_warning_acknowledged' => true, 'service_date' => '2026-10-02', 'shift' => 'AM', 'window_start' => '09:00', 'window_end' => '10:00', 'driver_user_id' => $c['driver']->id, 'vehicle_id' => $c['vehicle']->id];
        $this->post(route('fleet.requests.decision', $ret), $data)->assertSessionHasErrors(['vehicle_id' => 'RETT11 no se puede asignar: En reparación.']);
        $this->assertSame('requested', $ret->fresh()->status);
        $this->assertNull($ret->fresh()->vehicle_id);
        $c['vehicle']->update(['operational_status' => 'Activa']);
        $this->post(route('fleet.requests.decision', $ret), $data)->assertSessionHasNoErrors();
        $this->assertSame('scheduled', $ret->fresh()->status);
    }

    public function test_scheduled_test_confirmation_is_sent_to_hans_once(): void
    {
        $c = $this->context();
        Storage::fake('local');
        Http::preventStrayRequests();
        config()->set('fleet.auto_test_mode', true);
        config()->set('fleet.test_tools_enabled', true);
        $ret = $this->createRet($c);
        $ret->update(['status' => 'scheduled', 'driver_user_id' => $c['driver']->id, 'vehicle_id' => $c['vehicle']->id]);
        $service = app(RetNotificationService::class);
        $service->prepare($ret->fresh());
        $notice = DB::table('ret_notifications')->first();
        app()->detectEnvironment(fn () => 'local');
        $mail = $this->mock(MicrosoftRetMail::class);
        $mail->shouldReceive('settings')->andReturn(['automatic_receipts' => true]);
        $mail->shouldReceive('sendTest')->once()->with($ret->tenant_id, $notice->subject, \Mockery::on(fn ($html) => str_contains($html, $ret->ret_code)));
        $mail->shouldNotReceive('sendCustomer');
        $service->capture($notice->id);
        $service->capture($notice->id);
        $this->assertDatabaseHas('ret_notifications', ['id' => $notice->id, 'status' => 'test_accepted', 'attempts' => $notice->attempts + 1]);
        Http::assertNothingSent();
    }

    public function test_uncertain_confirmation_is_not_sent_twice(): void
    {
        $c = $this->context();
        Storage::fake('local');
        Http::preventStrayRequests();
        config()->set('fleet.auto_test_mode', true);
        config()->set('fleet.test_tools_enabled', true);
        $ret = $this->createRet($c);
        $ret->update(['status' => 'scheduled', 'driver_user_id' => $c['driver']->id, 'vehicle_id' => $c['vehicle']->id]);
        $service = app(RetNotificationService::class);
        $service->prepare($ret->fresh());
        $notice = DB::table('ret_notifications')->first();
        app()->detectEnvironment(fn () => 'local');
        $mail = $this->mock(MicrosoftRetMail::class);
        $mail->shouldReceive('settings')->andReturn(['automatic_receipts' => true]);
        $mail->shouldReceive('sendTest')->once()->andThrow(new ConnectionException('Connection lost'));
        $mail->shouldNotReceive('sendCustomer');
        $service->capture($notice->id);
        $service->capture($notice->id);
        $this->assertDatabaseHas('ret_notifications', ['id' => $notice->id, 'status' => 'uncertain', 'attempts' => $notice->attempts + 1]);
        Http::assertNothingSent();
    }
}
