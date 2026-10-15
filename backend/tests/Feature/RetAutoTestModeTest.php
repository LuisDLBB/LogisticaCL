<?php

namespace Tests\Feature;

use App\Fleet\CoordinationService;
use App\Models\Client;
use App\Models\ClientBranch;
use App\Models\FixedPickup;
use App\Models\RetFeasibility;
use App\Models\ServiceType;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RetAutoTestModeTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $this->travelTo(Carbon::parse('2026-10-07 10:00:00', 'America/Santiago'));
        Storage::fake('local');
        Http::preventStrayRequests();
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $actor = User::factory()->create();
        FleetTestMembership::insert(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'role_code' => 'administrator', 'is_active' => true]);
        $this->actingAs($actor)->withSession(['fleet_tenant_id' => $tenant->id]);
        $client = Client::create(['tenant_id' => $tenant->id, 'commercial_name' => 'Cliente monitor', 'legal_name' => 'Cliente SPA', 'tax_id' => '76543210-0', 'tax_id_number' => '76543210', 'tax_id_check_digit' => '0', 'is_active' => true]);
        $point = ClientBranch::create(['client_id' => $client->id, 'code' => 'M01', 'name' => 'Bodega', 'address' => 'Dorsal 3225', 'commune_name' => 'Renca', 'address_status' => 'ready', 'operational_emails' => 'cliente@example.test', 'is_active' => true]);
        $type = ServiceType::create(['service_code' => 990, 'name' => 'Retiro', 'is_active' => true]);

        return compact('tenant', 'actor', 'client', 'point', 'type');
    }

    public function test_new_requests_cannot_disable_automatic_test_flag_and_existing_fixed_plan_is_preserved(): void
    {
        $c = $this->context();
        config()->set('fleet.test_tools_enabled', true);
        config()->set('fleet.auto_test_mode', true);
        $data = ['service_date' => '2026-10-07', 'window_start' => '09:00', 'window_end' => '10:00', 'shift' => 'AM', 'packages' => 5, 'is_test' => false];
        $ret = app(CoordinationService::class)->createRequest($c['actor'], $c['client'], $c['point'], $c['type']->id, $data);
        $this->assertTrue($ret->is_test);
        $plan = FixedPickup::create(['tenant_id' => $c['tenant']->id, 'client_id' => $c['client']->id, 'client_branch_id' => $c['point']->id, 'service_type_id' => $c['type']->id, 'source_client_name' => 'Cliente', 'source_point_name' => 'Bodega', 'association_status' => 'linked', 'is_active' => true, 'is_test' => false, 'material' => 'Bultos', 'usual_packages' => 5, 'shift' => 'AM', 'window_start' => '09:00', 'window_end' => '10:00', 'weekdays' => [3]]);
        $ret = app(CoordinationService::class)->createRequest($c['actor'], $c['client'], $c['point'], $c['type']->id, $data, $plan);
        $this->assertTrue($ret->is_test);
        $this->assertFalse($plan->fresh()->is_test);
        Http::assertNothingSent();
    }

    public function test_new_fixed_plans_and_feasibilities_are_automatic_tests_without_checkbox(): void
    {
        $c = $this->context();
        config()->set('fleet.test_tools_enabled', true);
        config()->set('fleet.auto_test_mode', true);
        $c['client']->serviceTypes()->attach($c['type']->id, ['is_active' => true]);
        $data = ['client_id' => $c['client']->id, 'client_branch_id' => $c['point']->id, 'service_type_id' => $c['type']->id, 'usual_packages' => 5, 'material' => 'Bultos', 'shift' => 'AM', 'window_start' => '09:00', 'window_end' => '10:00', 'weekdays' => [3], 'is_test' => false];
        $this->post(route('fleet.fixed.store'), $data)->assertSessionHasNoErrors();
        $this->assertTrue(FixedPickup::firstOrFail()->is_test);
        $this->post(route('fleet.feasibility.store'), ['client_id' => $c['client']->id, 'service_type_id' => $c['type']->id, 'material' => 'Cajas', 'requires_finance' => false, 'is_test' => false])->assertSessionHasNoErrors();
        $this->assertTrue(RetFeasibility::firstOrFail()->is_test);
        $this->get(route('fleet.requests.create'))->assertOk()->assertSee('Dato de prueba automático')->assertDontSee('Identificar este registro como dato de prueba');
        Http::assertNothingSent();
    }

    public function test_automatic_mode_can_be_disabled_and_never_marks_production_records(): void
    {
        $c = $this->context();
        config()->set('fleet.test_tools_enabled', true);
        config()->set('fleet.auto_test_mode', false);
        $data = ['service_date' => '2026-10-07', 'window_start' => '09:00', 'window_end' => '10:00', 'shift' => 'AM', 'packages' => 5];
        $this->assertFalse(app(CoordinationService::class)->createRequest($c['actor'], $c['client'], $c['point'], $c['type']->id, $data)->is_test);
        config()->set('fleet.auto_test_mode', true);
        app()->detectEnvironment(fn () => 'production');
        $this->assertFalse(app(CoordinationService::class)->createRequest($c['actor'], $c['client'], $c['point'], $c['type']->id, $data + ['is_test' => true])->is_test);
        Http::assertNothingSent();
    }
}
