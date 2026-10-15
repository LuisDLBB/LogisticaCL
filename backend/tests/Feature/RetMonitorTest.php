<?php

namespace Tests\Feature;

use App\Fleet\CoordinationService;
use App\Models\Client;
use App\Models\ClientBranch;
use App\Models\RetFeasibility;
use App\Models\ServiceRequest;
use App\Models\ServiceType;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RetMonitorTest extends TestCase
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

    private function ret(array $c, string $status = 'requested'): ServiceRequest
    {
        $ret = app(CoordinationService::class)->createRequest($c['actor'], $c['client'], $c['point'], $c['type']->id, ['service_date' => '2026-10-07', 'window_start' => '09:00', 'window_end' => '10:00', 'shift' => 'AM', 'packages' => 5, 'load_unit' => 'loose', 'is_test' => true]);
        $ret->update(['status' => $status]);

        return $ret;
    }

    public function test_guest_and_unassociated_users_cannot_read_monitor(): void
    {
        $this->get('/control-flota/coordinacion/monitor')->assertRedirect('/');
        $this->actingAs(User::factory()->create())->get('/control-flota/coordinacion/monitor')->assertForbidden();
    }

    public function test_colors_zero_cancelled_quantity_and_tenant_isolation(): void
    {
        $c = $this->context();
        foreach (['cancelled', 'correction_requested', 'requested', 'scheduled', 'retired'] as $status) {
            $this->ret($c, $status);
        }$other = $this->ret($c);
        $other->update(['tenant_id' => Tenant::where('code', 'PMCB')->value('id'), 'client_name_snapshot' => 'Empresa ajena']);
        $response = $this->get('/control-flota/coordinacion/monitor');
        $response->assertOk()->assertDontSee('Empresa ajena')->assertViewHas('total', 5)->assertViewHas('rows', function ($rows) {
            $colors = $rows->getCollection()->pluck('color')->all();

            return $colors === ['red', 'yellow', 'white', 'blue', 'green'] && $rows->getCollection()->first()['packages'] === 0;
        });
        Http::assertNothingSent();
    }

    public function test_pending_task_overrides_scheduled_and_fragment_is_partial(): void
    {
        $c = $this->context();
        $ret = $this->ret($c, 'scheduled');
        DB::table('ret_coordination_tasks')->insert(['service_request_id' => $ret->id, 'assigned_to_user_id' => $c['actor']->id, 'requested_by_user_id' => $c['actor']->id, 'kind' => 'correction', 'previous_status' => 'scheduled', 'status' => 'pending', 'reason' => 'Ahora son diez bultos', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/control-flota/coordinacion/monitor?fragment=1')->assertOk()->assertSee('Ahora son diez bultos')->assertDontSee('<!doctype html>', false)->assertViewHas('rows', fn ($rows) => $rows->getCollection()->first()['color'] === 'yellow');
    }

    public function test_approved_feasibility_is_white_until_linked_request_scheduled(): void
    {
        $c = $this->context();
        $case = RetFeasibility::create(['tenant_id' => $c['tenant']->id, 'client_id' => $c['client']->id, 'service_type_id' => $c['type']->id, 'created_by_user_id' => $c['actor']->id, 'status' => 'approved', 'requires_finance' => false, 'is_test' => true, 'request_data' => ['packages' => 8], 'original_request_data' => []]);
        $this->get('/control-flota/coordinacion/monitor?tipo=feasibility')->assertOk()->assertSee('Aprobado')->assertViewHas('rows', fn ($rows) => $rows->getCollection()->first()['color'] === 'white');
        $ret = $this->ret($c, 'scheduled');
        $case->update(['service_request_id' => $ret->id]);
        $this->get('/control-flota/coordinacion/monitor?tipo=feasibility')->assertOk()->assertViewHas('rows', fn ($rows) => $rows->getCollection()->first()['color'] === 'blue');
    }

    public function test_daily_monitor_ignores_old_filters_and_rotates_only_todays_requests(): void
    {
        $c = $this->context();
        for ($i = 0; $i < 13; $i++) {
            $this->ret($c);
        }$past = $this->ret($c);
        $past->update(['service_date' => '2026-10-06']);
        $this->get('/control-flota/coordinacion/monitor')->assertOk()->assertViewHas('total', 13)->assertViewHas('rows', fn ($rows) => $rows->count() === 12 && $rows->lastPage() === 2);
        $this->get('/control-flota/coordinacion/monitor?fecha=&page=2')->assertOk()->assertViewHas('total', 13)->assertViewHas('rows', fn ($rows) => $rows->count() === 1);
        $this->get('/control-flota/coordinacion/monitor?estado=red')->assertOk()->assertViewHas('total', 13)->assertDontSee('id="filters"', false)->assertDontSee('Fecha / horario');
    }

    public function test_fleet_summary_excludes_sold_stolen_and_counts_workshop_warning_and_bookings(): void
    {
        $c = $this->context();
        $ids = [];
        foreach (['Activa', 'Activa', 'Activa', 'En mantención', 'Fuera de servicio', 'Vendido', 'Robado'] as $index => $state) {
            $ids[] = DB::table('MBA_vehicles')->insertGetId(['tenant_id' => $c['tenant']->id, 'rut_empresa' => $c['tenant']->tax_id, 'company_source' => $c['tenant']->code, 'plate' => 'TV'.$index, 'internal_code' => 'TV'.$index, 'vehicle_type' => 'Camioneta', 'ownership_type' => 'Propio', 'operational_status' => $state, 'is_active' => true, 'next_maintenance_at' => $index === 2 ? '2026-10-10' : null, 'created_at' => now(), 'updated_at' => now()]);
        }
        $ret = $this->ret($c, 'scheduled');
        $ret->update(['vehicle_id' => $ids[1]]);
        $response = $this->get('/control-flota/coordinacion/monitor');
        $response->assertOk()->assertViewHas('fleetSummary', ['available' => 1, 'booked' => 1, 'warning' => 1, 'workshop' => 1, 'unavailable' => 1, 'papers' => 0]);
        $response->assertDontSee('id="filters"', false)->assertSee('Desde / hasta')->assertSee('Programar mantención');
    }

    public function test_monitor_respects_section_permissions(): void
    {
        $c = $this->context();
        $this->ret($c);
        RetFeasibility::create(['tenant_id' => $c['tenant']->id, 'client_id' => $c['client']->id, 'service_type_id' => $c['type']->id, 'created_by_user_id' => $c['actor']->id, 'status' => 'operations', 'requires_finance' => false, 'request_data' => [], 'original_request_data' => []]);
        $profile = DB::table('fleet_profiles')->where('tenant_id', $c['tenant']->id)->where('code', 'administrator')->value('id');
        DB::table('fleet_profile_permissions')->where('fleet_profile_id', $profile)->update(['access_level' => 0]);
        DB::table('fleet_profile_permissions')->where('fleet_profile_id', $profile)->where('permission_code', 'operations.fleet')->update(['access_level' => 1]);
        $this->get('/control-flota/coordinacion/monitor')->assertForbidden();
        DB::table('fleet_profile_permissions')->where('fleet_profile_id', $profile)->where('permission_code', 'commercial.feasibility')->update(['access_level' => 1]);
        $this->get('/control-flota/coordinacion/monitor')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertViewHas('total', 1)->assertViewHas('fleetSummary', null)->assertViewHas('rows', fn ($rows) => $rows->getCollection()->first()['type'] === 'Factibilidad');
        DB::table('fleet_profile_permissions')->where('fleet_profile_id', $profile)->where('permission_code', 'commercial.feasibility')->update(['access_level' => 0]);
        DB::table('fleet_profile_permissions')->where('fleet_profile_id', $profile)->where('permission_code', 'coordination.requests')->update(['access_level' => 1]);
        $this->get('/control-flota/coordinacion/monitor')->assertOk()->assertViewHas('total', 1)->assertViewHas('rows', fn ($rows) => $rows->getCollection()->first()['type'] === 'Retiro');
    }
}
