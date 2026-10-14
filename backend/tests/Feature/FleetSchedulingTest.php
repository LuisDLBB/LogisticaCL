<?php

namespace Tests\Feature;

use App\Fleet\CoordinationService;
use App\Fleet\SchedulingService;
use App\Models\Client;
use App\Models\ClientBranch;
use App\Models\FixedPickup;
use App\Models\ServiceRequest;
use App\Models\ServiceType;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FleetSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $supervisor;

    private User $driver;

    private Tenant $fourN;

    private Tenant $pmcb;

    private Client $client;

    private ClientBranch $point;

    private ServiceType $serviceType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fourN = Tenant::where('code', '4N')->firstOrFail();
        $this->pmcb = Tenant::where('code', 'PMCB')->firstOrFail();
        $this->operator = User::factory()->create();
        $this->supervisor = User::factory()->create();
        $this->membership($this->operator, $this->fourN, 'administrator');
        $this->membership($this->supervisor, $this->fourN, 'administrator');
        $this->driver = $this->driver('App Conductor');
        $this->actingAs($this->operator)->withSession(['fleet_tenant_id' => $this->fourN->id]);
        $this->client = Client::create([
            'tenant_id' => $this->fourN->id, 'tax_id' => '76543210-0',
            'tax_id_number' => '76543210', 'tax_id_check_digit' => '0',
            'commercial_name' => 'Cliente prueba', 'legal_name' => 'Cliente prueba SPA', 'is_active' => true,
        ]);
        $this->serviceType = ServiceType::create(['service_code' => 1, 'name' => 'Retiro', 'is_active' => true]);
        $this->point = ClientBranch::create([
            'client_id' => $this->client->id, 'code' => 'P1', 'name' => 'Punto prueba',
            'address' => 'Calle 123', 'commune_name' => 'Santiago',
            'address_status' => 'ready', 'operational_email_pending' => true, 'is_active' => true,
        ]);
    }

    public function test_fixed_pickup_proposes_five_but_saves_the_edited_snapshot_only(): void
    {
        $fixed = FixedPickup::create([
            'tenant_id' => $this->fourN->id, 'client_id' => $this->client->id,
            'client_branch_id' => $this->point->id, 'service_type_id' => $this->serviceType->id,
            'source_client_name' => 'Cliente prueba', 'source_point_name' => 'Punto prueba',
            'association_status' => 'linked', 'weekdays' => [1], 'shift' => 'AM',
            'window_start' => '09:00', 'window_end' => '10:00', 'material' => 'Cajas',
            'usual_packages' => null, 'is_active' => true,
        ]);
        $this->get(route('fleet.page.coordination-fixed-pickups', ['fecha' => '2026-09-28']))
            ->assertOk()->assertSee('value="5"', false);
        $this->post(route('fleet.fixed.convert'), [
            'service_date' => '2026-09-28', 'single_id' => $fixed->id,
            'packages' => [$fixed->id => 7],
        ])->assertRedirect();
        $this->assertSame(7, ServiceRequest::firstOrFail()->packages);
        $this->assertNull($fixed->fresh()->usual_packages);
    }

    public function test_existing_schedule_button_requires_assignment_and_prepares_only_valid_email(): void
    {
        $ret = $this->ret();
        $vehicle = $this->vehicle($this->fourN, 'AAAA11');
        $this->post(route('fleet.requests.schedule', $ret))->assertSessionHasErrors('ret');
        $this->assertSame('requested', $ret->fresh()->status);
        $this->post(route('fleet.scheduling.assign', $ret), ['driver_user_id' => $this->driver->id, 'vehicle_id' => $vehicle->id])->assertRedirect();
        $this->post(route('fleet.requests.schedule', $ret))->assertRedirect();
        $this->assertSame('scheduled', $ret->fresh()->status);
        $this->assertSame('not_prepared', $ret->fresh()->email_status);

        $this->point->update(['operational_emails' => 'operacion@example.test', 'operational_email_pending' => false]);
        $withEmail = $this->ret('11:00', '12:00');
        $this->post(route('fleet.scheduling.assign', $withEmail), ['driver_user_id' => $this->driver->id, 'vehicle_id' => $vehicle->id])->assertRedirect();
        $this->post(route('fleet.requests.schedule', $withEmail))->assertRedirect();
        $this->assertSame('prepared', $withEmail->fresh()->email_status);
        $this->assertSame('operacion@example.test', $withEmail->fresh()->email_to_snapshot);
        $this->assertSame('Hansdelabarra@4nlogistica.cl', $withEmail->fresh()->email_cc_snapshot);
        $this->assertNotNull($withEmail->fresh()->email_prepared_at);
        $this->assertStringContainsString($this->driver->name, $withEmail->fresh()->email_body);
    }

    public function test_ret_cannot_be_scheduled_with_only_a_driver_or_only_a_vehicle(): void
    {
        $vehicle = $this->vehicle($this->fourN, 'AAAA11');
        $driverOnly = $this->ret();
        $driverOnly->update(['driver_user_id' => $this->driver->id]);
        $this->post(route('fleet.requests.schedule', $driverOnly))->assertSessionHasErrors('ret');
        $this->assertSame('requested', $driverOnly->fresh()->status);
        $vehicleOnly = $this->ret('11:00', '12:00');
        $vehicleOnly->update(['vehicle_id' => $vehicle->id]);
        $this->post(route('fleet.requests.schedule', $vehicleOnly))->assertSessionHasErrors('ret');
        $this->assertSame('requested', $vehicleOnly->fresh()->status);
    }

    public function test_shared_pool_and_maintenance_rules(): void
    {
        $own = $this->vehicle($this->fourN, 'AAAA11');
        $pmcb = $this->vehicle($this->pmcb, 'BBBB22');
        $sold = $this->vehicle($this->fourN, 'CCCC33', 'Vendido');
        $maintenance = $this->vehicle($this->fourN, 'DDDD44');
        $planned = $this->vehicle($this->fourN, 'EEEE55');
        $this->maintenance($maintenance, 'in_progress');
        $this->maintenance($planned, 'scheduled');
        $ret = $this->ret();
        $this->get(route('fleet.page.operations-scheduling', ['fecha' => '2026-09-28']))
            ->assertOk()->assertSee('AAAA11')->assertSee('BBBB22')->assertSee('EEEE55')
            ->assertDontSee('CCCC33 · Furgón');
        $this->post(route('fleet.scheduling.assign', $ret), ['driver_user_id' => $this->driver->id, 'vehicle_id' => $sold->id])->assertSessionHasErrors('vehicle_id');
        $this->post(route('fleet.scheduling.assign', $ret), ['driver_user_id' => $this->driver->id, 'vehicle_id' => $maintenance->id])->assertSessionHasErrors('vehicle_id');
        $this->post(route('fleet.scheduling.assign', $ret), ['driver_user_id' => $this->driver->id, 'vehicle_id' => $pmcb->id])->assertRedirect();
        $this->assertSame($pmcb->id, $ret->fresh()->vehicle_id);
        $this->post(route('fleet.scheduling.assign', $ret), ['driver_user_id' => $this->driver->id, 'vehicle_id' => $planned->id])->assertRedirect();
        $this->get(route('fleet.page.operations-scheduling', ['fecha' => '2026-09-28']))->assertSee('Vehículo con mantención programada');
    }

    public function test_first_three_overlaps_are_allowed_fourth_needs_independent_approval(): void
    {
        $vehicle = $this->vehicle($this->fourN, 'AAAA11');
        for ($number = 1; $number <= 3; $number++) {
            $ret = $this->ret();
            $this->post(route('fleet.scheduling.assign', $ret), ['driver_user_id' => $this->driver->id, 'vehicle_id' => $vehicle->id])->assertRedirect();
            $this->assertSame($this->driver->id, $ret->fresh()->driver_user_id);
        }
        $fourth = $this->ret();
        $assignment = ['driver_user_id' => $this->driver->id, 'vehicle_id' => $vehicle->id];
        $this->post(route('fleet.scheduling.assign', $fourth), $assignment)->assertSessionHasErrors('overload');
        $this->post(route('fleet.scheduling.overload.request', $fourth), $assignment + ['reason' => 'Ruta compartida'])->assertRedirect();
        $approval = DB::table('service_request_overload_authorizations')->first();
        $this->get(route('fleet.page.operations-scheduling', ['fecha' => '2026-09-28']))
            ->assertOk()->assertSee($this->driver->name)->assertSee($vehicle->plate)
            ->assertSee('Tu solicitud requiere la decisión de otra persona');
        $this->assertSame(4, $approval->driver_concurrences);
        $this->assertSame(4, $approval->vehicle_concurrences);
        $this->post(route('fleet.scheduling.overload.decide', $approval->id), ['decision' => 'approved'])->assertSessionHasErrors('decision');
        $this->actingAs($this->supervisor)->withSession(['fleet_tenant_id' => $this->fourN->id]);
        $this->post(route('fleet.scheduling.overload.decide', $approval->id), ['decision' => 'approved'])->assertRedirect();
        $this->actingAs($this->operator)->withSession(['fleet_tenant_id' => $this->fourN->id]);
        $this->post(route('fleet.scheduling.assign', $fourth), $assignment)->assertRedirect();
        $this->assertSame($vehicle->id, $fourth->fresh()->vehicle_id);
    }

    public function test_rejected_overload_does_not_allow_assignment(): void
    {
        $vehicle = $this->vehicle($this->fourN, 'AAAA11');
        for ($number = 0; $number < 3; $number++) {
            $this->ret()->update(['driver_user_id' => $this->driver->id, 'vehicle_id' => $vehicle->id]);
        }
        $fourth = $this->ret();
        $payload = ['driver_user_id' => $this->driver->id, 'vehicle_id' => $vehicle->id];
        $this->post(route('fleet.scheduling.overload.request', $fourth), $payload + ['reason' => 'Prueba'])->assertRedirect();
        $approval = DB::table('service_request_overload_authorizations')->first();
        $this->actingAs($this->supervisor)->withSession(['fleet_tenant_id' => $this->fourN->id]);
        $this->post(route('fleet.scheduling.overload.decide', $approval->id), ['decision' => 'rejected'])->assertRedirect();
        $this->actingAs($this->operator)->withSession(['fleet_tenant_id' => $this->fourN->id]);
        $this->post(route('fleet.scheduling.assign', $fourth), $payload)->assertSessionHasErrors('overload');
    }

    public function test_touching_windows_do_not_overlap_but_real_overlap_does(): void
    {
        $vehicle = $this->vehicle($this->fourN, 'AAAA11');
        $this->ret('10:00', '11:00')->update(['driver_user_id' => $this->driver->id, 'vehicle_id' => $vehicle->id]);
        $adjacent = $this->ret('11:00', '12:00');
        $overlap = $this->ret('10:30', '11:30');
        $service = app(SchedulingService::class);
        $this->assertSame(1, $service->concurrences($adjacent, $this->driver->id, $vehicle->id, '2026-09-28', '11:00', '12:00')['driver']);
        $this->assertSame(2, $service->concurrences($overlap, $this->driver->id, $vehicle->id, '2026-09-28', '10:30', '11:30')['vehicle']);
    }

    public function test_driver_and_vehicle_limits_are_calculated_separately(): void
    {
        $vehicle = $this->vehicle($this->fourN, 'AAAA11');
        $otherVehicle = $this->vehicle($this->pmcb, 'BBBB22');
        $otherDriver = $this->driver('Conductor');
        for ($number = 0; $number < 3; $number++) {
            $this->ret()->update(['driver_user_id' => $this->driver->id, 'vehicle_id' => $vehicle->id]);
        }
        $vehicleOnly = $this->ret();
        $counts = app(SchedulingService::class)->concurrences($vehicleOnly, $otherDriver->id, $vehicle->id, '2026-09-28', '09:00', '10:00');
        $this->assertSame(['driver' => 1, 'vehicle' => 4], $counts);
        $this->post(route('fleet.scheduling.assign', $vehicleOnly), ['driver_user_id' => $otherDriver->id, 'vehicle_id' => $vehicle->id])->assertSessionHasErrors('overload');
        $driverOnly = $this->ret();
        $counts = app(SchedulingService::class)->concurrences($driverOnly, $this->driver->id, $otherVehicle->id, '2026-09-28', '09:00', '10:00');
        $this->assertSame(['driver' => 4, 'vehicle' => 1], $counts);
        $this->post(route('fleet.scheduling.assign', $driverOnly), ['driver_user_id' => $this->driver->id, 'vehicle_id' => $otherVehicle->id])->assertSessionHasErrors('overload');
    }

    public function test_historical_driver_profiles_do_not_grant_internal_access(): void
    {
        $driver = $this->driver('Conductor');
        $ambiguous = $this->driver('Operaciones');
        $ambiguous->tenants()->updateExistingPivot($this->fourN->id, ['role_code' => 'operations']);
        $this->assertTrue(app(SchedulingService::class)->isDriver($driver));
        $this->assertFalse(app(SchedulingService::class)->isDriver($ambiguous));
        $this->actingAs($driver)->withSession(['fleet_tenant_id' => $this->fourN->id]);
        $this->get(route('fleet.page.operations-scheduling'))->assertForbidden();
    }

    public function test_assignment_changes_are_audited_and_expire_old_authorization(): void
    {
        $vehicle = $this->vehicle($this->fourN, 'AAAA11');
        $otherVehicle = $this->vehicle($this->pmcb, 'BBBB22');
        $otherDriver = $this->driver('Conductor A5');
        $ret = $this->ret();
        $this->post(route('fleet.scheduling.assign', $ret), ['driver_user_id' => $this->driver->id, 'vehicle_id' => $vehicle->id])->assertRedirect();
        $this->post(route('fleet.requests.schedule', $ret))->assertRedirect();
        $this->post(route('fleet.scheduling.assign', $ret), ['driver_user_id' => $otherDriver->id, 'vehicle_id' => $vehicle->id])->assertSessionHasErrors('reason');
        DB::table('service_request_overload_authorizations')->insert([
            'service_request_id' => $ret->id, 'driver_user_id' => $this->driver->id, 'vehicle_id' => $vehicle->id,
            'service_date' => '2026-09-28', 'window_start' => '09:00:00', 'window_end' => '10:00:00',
            'driver_concurrences' => 4, 'vehicle_concurrences' => 4, 'limit_snapshot' => 3,
            'reason' => 'Prueba', 'status' => 'approved', 'requested_by_user_id' => $this->operator->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->post(route('fleet.scheduling.assign', $ret), ['driver_user_id' => $otherDriver->id, 'vehicle_id' => $vehicle->id, 'reason' => 'Cambio de ruta'])->assertRedirect();
        $this->assertSame('expired', DB::table('service_request_overload_authorizations')->value('status'));
        DB::table('service_request_overload_authorizations')->insert([
            'service_request_id' => $ret->id, 'driver_user_id' => $otherDriver->id, 'vehicle_id' => $vehicle->id,
            'service_date' => '2026-09-28', 'window_start' => '09:00:00', 'window_end' => '10:00:00',
            'driver_concurrences' => 4, 'vehicle_concurrences' => 4, 'limit_snapshot' => 3,
            'reason' => 'Prueba vehículo', 'status' => 'approved', 'requested_by_user_id' => $this->operator->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->post(route('fleet.scheduling.assign', $ret), ['driver_user_id' => $otherDriver->id, 'vehicle_id' => $otherVehicle->id, 'reason' => 'Cambio de móvil'])->assertRedirect();
        $this->assertSame(2, DB::table('service_request_overload_authorizations')->where('status', 'expired')->count());
        $events = DB::table('service_request_events')->where('service_request_id', $ret->id)->where('action', 'assignment_changed')->get();
        $this->assertCount(3, $events);
        $this->assertSame('Cambio de móvil', json_decode($events->last()->details, true, 512, JSON_THROW_ON_ERROR)['reason']);
    }

    public function test_reprogramming_rechecks_capacity_and_prepares_new_email(): void
    {
        $vehicle = $this->vehicle($this->fourN, 'AAAA11');
        $this->point->update(['operational_emails' => 'operacion@example.test']);
        for ($number = 0; $number < 3; $number++) {
            $this->ret('11:00', '12:00')->update(['driver_user_id' => $this->driver->id, 'vehicle_id' => $vehicle->id]);
        }
        $ret = $this->ret('09:00', '10:00');
        $this->post(route('fleet.scheduling.assign', $ret), ['driver_user_id' => $this->driver->id, 'vehicle_id' => $vehicle->id])->assertRedirect();
        $this->post(route('fleet.requests.schedule', $ret))->assertRedirect();
        $this->post(route('fleet.requests.reprogram', $ret), [
            'reason' => 'Cliente solicita', 'proposed_date' => '2026-09-28',
            'proposed_window_start' => '11:00', 'proposed_window_end' => '12:00',
        ])->assertRedirect();
        $decision = $ret->reprogrammings()->firstOrFail();
        $payload = ['decision' => 'approved', 'customer_response' => 'Cliente confirma'];
        $this->post(route('fleet.reprogramming.decide', $decision), $payload)->assertSessionHasErrors('overload');
        $this->post(route('fleet.scheduling.overload.request', $ret), [
            'driver_user_id' => $this->driver->id, 'vehicle_id' => $vehicle->id,
            'reprogramming_id' => $decision->id, 'reason' => 'Cambio solicitado',
        ])->assertRedirect();
        $approval = DB::table('service_request_overload_authorizations')->first();
        $this->actingAs($this->supervisor)->withSession(['fleet_tenant_id' => $this->fourN->id]);
        $this->post(route('fleet.scheduling.overload.decide', $approval->id), ['decision' => 'approved'])->assertRedirect();
        $this->actingAs($this->operator)->withSession(['fleet_tenant_id' => $this->fourN->id]);
        $this->post(route('fleet.reprogramming.decide', $decision), $payload)->assertRedirect();
        $this->assertSame('rescheduled', $ret->fresh()->status);
        $this->assertSame('prepared', $ret->fresh()->email_status);
        $this->assertStringContainsString('11:00', $ret->fresh()->email_body);
    }

    public function test_reprogramming_rechecks_current_maintenance(): void
    {
        $vehicle = $this->vehicle($this->fourN, 'AAAA11');
        $ret = $this->ret();
        $this->post(route('fleet.scheduling.assign', $ret), ['driver_user_id' => $this->driver->id, 'vehicle_id' => $vehicle->id])->assertRedirect();
        $this->post(route('fleet.requests.schedule', $ret))->assertRedirect();
        $this->post(route('fleet.requests.reprogram', $ret), [
            'reason' => 'Cambio', 'proposed_date' => '2026-09-29',
            'proposed_window_start' => '11:00', 'proposed_window_end' => '12:00',
        ])->assertRedirect();
        $this->maintenance($vehicle, 'in_progress');
        $this->post(route('fleet.reprogramming.decide', $ret->reprogrammings()->firstOrFail()), [
            'decision' => 'approved', 'customer_response' => 'Cliente confirma',
        ])->assertSessionHasErrors('vehicle_id');
        $this->assertSame('reprogramming_pending', $ret->fresh()->status);
    }

    public function test_scheduling_limit_is_configurable_in_administration(): void
    {
        $this->get(route('fleet.scheduling.settings'))->assertOk()->assertSee('value="3"', false);
        $this->put(route('fleet.scheduling.settings.update'), ['max_concurrent_pickups' => 2])->assertRedirect();
        $this->assertSame(2, app(SchedulingService::class)->limit($this->fourN->id));
    }

    private function membership(User $user, Tenant $tenant, string $role): void
    {
        DB::table('tenant_users')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'role_code' => $role, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function driver(string $profile): User
    {
        $driver = User::factory()->create();
        $driver->profile_name = $profile;
        $driver->license_type = 'B';
        $driver->save();
        $this->membership($driver, $this->fourN, 'app_conductor');

        return $driver;
    }

    private function vehicle(Tenant $owner, string $plate, string $status = 'Activa'): Vehicle
    {
        return Vehicle::create([
            'tenant_id' => $this->fourN->id, 'rut_empresa' => $owner->tax_id,
            'company_source' => $owner->code, 'internal_code' => $plate, 'plate' => $plate,
            'max_weight_kg' => 3500, 'vehicle_type' => 'Furgón', 'ownership_type' => 'Propio',
            'operational_status' => $status, 'is_active' => true,
        ]);
    }

    private function maintenance(Vehicle $vehicle, string $status): void
    {
        VehicleMaintenance::create([
            'vehicle_id' => $vehicle->id, 'tenant_id' => $this->fourN->id,
            'maintenance_type' => 'Preventiva', 'execution_type' => 'external',
            'status' => $status, 'scheduled_at' => '2026-10-01 09:00:00',
        ]);
    }

    private function ret(string $start = '09:00', string $end = '10:00'): ServiceRequest
    {
        return app(CoordinationService::class)->createRequest($this->operator, $this->client, $this->point, $this->serviceType->id, [
            'service_date' => '2026-09-28', 'shift' => 'AM',
            'window_start' => $start, 'window_end' => $end,
            'packages' => 5, 'material' => 'Cajas',
        ]);
    }
}
