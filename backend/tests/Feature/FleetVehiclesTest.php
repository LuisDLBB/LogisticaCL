<?php

namespace Tests\Feature;

use App\Fleet\VehicleCatalog;
use App\Models\Client;
use App\Models\ClientBranch;
use App\Models\ServiceRequest;
use App\Models\ServiceType;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Models\VehicleMaintenanceImport;
use Carbon\Carbon;
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
            ->assertOk()->assertSee('AAAA11')->assertSee('BBBB22')->assertDontSee('Volver a Control de Flota')->assertSee('No disponibles');
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

    public function test_board_shows_today_services_and_direct_workshop_closing(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 10:00:00', 'America/Santiago'));
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $admin = User::factory()->create();
        $this->associate($admin, $tenant, 'administrator');
        $booked = $this->createVehicle($tenant, $tenant, 'AGEN11', 'Furgón');
        $workshop = $this->createVehicle($tenant, $tenant, 'TALL22', 'Camión', 'En mantención');
        $client = Client::where('tenant_id', $tenant->id)->firstOrFail();
        $point = ClientBranch::create(['client_id' => $client->id, 'code' => 'P1', 'name' => 'Bodega', 'address' => 'Calle 123', 'commune_name' => 'Renca', 'is_active' => true]);
        $type = ServiceType::create(['service_code' => 991, 'name' => 'Retiro', 'is_active' => true]);
        $service = ServiceRequest::create([
            'tenant_id' => $tenant->id, 'client_id' => $client->id,
            'client_branch_id' => $point->id, 'service_type_id' => $type->id, 'requested_by_user_id' => $admin->id,
            'requested_date_original' => '2026-10-08', 'service_date' => '2026-10-08',
            'shift' => 'AM', 'window_start' => '09:00', 'window_end' => '12:00',
            'packages' => 5, 'material' => 'Bultos', 'client_name_snapshot' => 'Agenda cliente',
            'legal_name_snapshot' => 'Cliente SPA', 'address_snapshot' => 'Calle 123', 'effective_address' => 'Calle 123', 'point_name_snapshot' => 'Bodega cliente', 'status' => 'scheduled', 'vehicle_id' => $booked->id,
        ]);
        $historicalPending = VehicleMaintenance::create(['vehicle_id' => $booked->id, 'tenant_id' => $tenant->id, 'maintenance_type' => 'Reparación antigua', 'execution_type' => 'external', 'status' => 'pending']);
        $job = VehicleMaintenance::create(['vehicle_id' => $booked->id, 'tenant_id' => $tenant->id, 'maintenance_type' => 'Preventiva', 'execution_type' => 'internal', 'status' => 'scheduled', 'scheduled_at' => '2026-10-16 13:00:00', 'responsible_user_id' => $admin->id]);
        $response = $this->actingAs($admin)->withSession(['fleet_tenant_id' => $tenant->id])
            ->get(route('fleet.page.operations-fleet'));
        $response->assertOk()->assertSee('Agendado hoy · ver servicios')
            ->assertSee('Agenda cliente')->assertSee('09:00 – 12:00')
            ->assertSee('data-complete-vehicle="'.$workshop->id.'"', false);
        $rows = $response->viewData('rows')->keyBy('vehicle.id');
        $this->assertSame([$service->id], $rows[$booked->id]['today_services']->pluck('id')->all());
        $this->assertSame('booked', $rows[$booked->id]['display_state']);
        $this->assertTrue($rows[$booked->id]['maintenance_reminder']);
        $this->assertTrue($rows[$booked->id]['maintenance_scheduled']);
        $response->assertSee('maintenance-reminder maintenance-scheduled', false)->assertSee('Ojo: mantención agendada')->assertSee('16/10/2026');
        $job->update(['status' => 'pending']);
        $pending = $this->get(route('fleet.page.operations-fleet'));
        $pending->assertViewHas('rows', fn ($rows) => ! $rows->firstWhere('vehicle.id', $booked->id)['maintenance_scheduled'])->assertOk()->assertSee('Ojo: mantención por programar')->assertViewHas('rows', fn ($rows) => $rows->firstWhere('vehicle.id', $booked->id)['display_state'] === 'booked');
        $service->update(['status' => 'cancelled']);
        $this->get(route('fleet.page.operations-fleet'))->assertOk()->assertDontSee('Agenda cliente')->assertViewHas('rows', fn ($rows) => $rows->firstWhere('vehicle.id', $booked->id)['display_state'] === 'warning');
        $this->assertSame('pending', $historicalPending->fresh()->status);
        $historicalPending->update(['status' => 'cancelled']);
        $job->update(['status' => 'cancelled']);
        $this->get(route('fleet.page.operations-fleet'))->assertViewHas('rows', fn ($rows) => $rows->firstWhere('vehicle.id', $booked->id)['display_state'] === 'available');
    }

    public function test_sheet_counts_only_closed_repairs_in_their_recorded_months(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'America/Santiago'));
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $admin = User::factory()->create();
        $this->associate($admin, $tenant, 'administrator');
        $vehicle = $this->createVehicle($tenant, $tenant, 'REPA11', 'Camión');
        foreach ([['Reparación', 'closed', '2026-10-01 15:00:00'], ['REPARACION', 'closed', '2026-09-15 15:00:00'], ['Reparación', 'closed', '2026-08-15 15:00:00'], ['Mantención periódica', 'closed', '2026-10-05 15:00:00'], ['Reparación', 'scheduled', null], ['Reparación', 'closed', '2026-10-20 15:00:00'], ['Reparación', 'closed', '2025-12-01 15:00:00']] as [$type, $status, $closedAt]) {
            VehicleMaintenance::create(['vehicle_id' => $vehicle->id, 'tenant_id' => $tenant->id, 'maintenance_type' => $type, 'execution_type' => 'internal', 'status' => $status, 'closed_at' => $closedAt]);
        }
        $historical = VehicleMaintenance::create(['vehicle_id' => $vehicle->id, 'tenant_id' => $tenant->id, 'maintenance_type' => 'REPARACION', 'execution_type' => 'external', 'status' => 'closed']);
        VehicleMaintenanceImport::create(['vehicle_maintenance_id' => $historical->id, 'source_key' => 'sheet-summary-month-boundary', 'source_line' => 1, 'requested_at' => '2026-10-01', 'source_data' => []]);

        $response = $this->actingAs($admin)->withSession(['fleet_tenant_id' => $tenant->id])->get(route('fleet.vehicles.show', $vehicle));

        $response->assertOk()->assertSeeInOrder(['Identificación y capacidad', 'Kilometraje y próxima mantención', 'Documentos y vencimientos', 'Conductores y servicios', 'Historial de disponibilidad', 'Historial de mantenciones y reparaciones'])->assertDontSee('Notas del vehículo');
        $summary = $response->viewData('repairSummary');
        $this->assertSame([1, 4], $summary['years']->pluck('count')->all());
        $this->assertSame([2, 1, 1], $summary['months']->pluck('count')->all());
        $this->assertSame(['octubre', 'septiembre', 'agosto'], $summary['months']->pluck('label')->all());
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

    public function test_vehicle_cards_show_availability_and_open_the_sheet_in_a_dialog(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 10:00:00', 'America/Santiago'));
        $admin = User::factory()->create();
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $this->associate($admin, $tenant, 'administrator');
        $available = $this->createVehicle($tenant, $tenant, 'TEST11', 'Camioneta');
        $warning = $this->createVehicle($tenant, $tenant, 'TEST22', 'Camión');
        $warning->update(['next_maintenance_at' => '2026-10-11']);
        $unavailable = $this->createVehicle($tenant, $tenant, 'TEST33', 'Rampla', 'Robado');
        $booked = $this->createVehicle($tenant, $tenant, 'TEST44', 'Furgón');
        $client = Client::create(['tenant_id' => $tenant->id, 'commercial_name' => 'Cliente', 'legal_name' => 'Cliente SPA', 'tax_id' => '76543210-0', 'tax_id_number' => '76543210', 'tax_id_check_digit' => '0', 'is_active' => true]);
        $point = ClientBranch::create(['client_id' => $client->id, 'code' => 'P1', 'name' => 'Bodega', 'address' => 'Calle 123', 'commune_name' => 'Renca', 'is_active' => true]);
        $type = ServiceType::create(['service_code' => 991, 'name' => 'Retiro', 'is_active' => true]);
        $ret = ServiceRequest::create(['tenant_id' => $tenant->id, 'client_id' => $client->id, 'client_branch_id' => $point->id, 'service_type_id' => $type->id, 'requested_by_user_id' => $admin->id, 'requested_date_original' => '2026-10-08', 'service_date' => '2026-10-08', 'shift' => 'AM', 'window_start' => '09:00', 'window_end' => '12:00', 'packages' => 5, 'material' => 'Bultos', 'client_name_snapshot' => 'Cliente', 'legal_name_snapshot' => 'Cliente SPA', 'point_name_snapshot' => 'Bodega', 'address_snapshot' => 'Calle 123', 'effective_address' => 'Calle 123', 'status' => 'scheduled', 'vehicle_id' => $booked->id]);

        $response = $this->actingAs($admin)->withSession(['fleet_tenant_id' => $tenant->id])->get(route('fleet.page.operations-fleet'));

        $response->assertOk()->assertSee('fleet-header-filters', false)->assertSee('data-ret-modal', false)->assertSee('Robado');
        $states = $response->viewData('rows')->keyBy('vehicle.id');
        $this->assertSame('available', $states[$available->id]['display_state']);
        $this->assertSame('warning', $states[$warning->id]['display_state']);
        $this->assertSame('unavailable', $states[$unavailable->id]['display_state']);
        $this->assertSame('booked', $states[$booked->id]['display_state']);
        $this->assertSame(2, $response->viewData('unavailableRows')->count());
        $response->assertSee('data-count-state="available">1</b>', false)->assertSee('data-count-state="booked">1</b>', false)->assertSee('data-count-state="warning">1</b>', false)->assertSee('data-count-state="maintenance">0</b>', false)->assertSee('data-count-state="unavailable">1</b>', false);
        $move = ['target_state' => 'unavailable', 'reason_code' => 'repair', 'reason' => 'Debe ingresar a taller', 'expected_status' => 'Activa', 'expected_revision' => $booked->updated_at->toISOString()];
        $this->patch(route('fleet.vehicles.status', $booked), $move)->assertSessionHasErrors('vehicle_state');
        $this->assertSame('Activa', $booked->fresh()->operational_status);
        $this->patch(route('fleet.vehicles.status', $booked), $move + ['assigned_acknowledged' => 1])->assertSessionHasNoErrors();
        $this->assertSame('scheduled', $ret->fresh()->status);
        $this->assertSame($booked->id, $ret->fresh()->vehicle_id);
        $this->assertSame('['.$ret->id.']', DB::table('VEH_vehicle_status_events')->where('vehicle_id', $booked->id)->value('affected_request_ids'));
        $this->patch(route('fleet.vehicles.status', $booked), ['target_state' => 'available', 'reason' => 'Reparación completada', 'close_maintenance' => true, 'closing' => ['execution_type' => 'internal', 'actual_cost' => 0, 'closed_odometer_km' => 0], 'expected_status' => 'En reparación', 'expected_revision' => $booked->fresh()->updated_at->toISOString()])->assertSessionHasNoErrors();
        $ret->update(['status' => 'cancelled']);
        $response = $this->get(route('fleet.page.operations-fleet'));
        $this->assertSame('available', $response->viewData('rows')->keyBy('vehicle.id')[$booked->id]['display_state']);
    }

    public function test_confirmed_state_changes_block_assignment_and_record_actor_and_reason(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 10:00:00', 'America/Santiago'));
        $admin = User::factory()->create();
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $this->associate($admin, $tenant, 'administrator');
        $vehicle = $this->createVehicle($tenant, $tenant, 'MOVE11', 'Camioneta');
        $vehicle->update(['payload_capacity_kg' => 1500, 'max_pallets' => 4, 'capacity_m3' => 12]);
        $this->actingAs($admin)->withSession(['fleet_tenant_id' => $tenant->id]);
        $url = route('fleet.vehicles.status', $vehicle);
        $initial = ['target_state' => 'unavailable', 'reason_code' => 'repair', 'reason' => 'Reparación de frenos', 'expected_status' => 'Activa', 'expected_revision' => $vehicle->fresh()->updated_at->toISOString()];

        $this->patch($url, $initial)->assertSessionHasNoErrors();

        $this->assertSame('En reparación', $vehicle->fresh()->operational_status);
        $this->assertFalse(app(VehicleCatalog::class)->isOperational($vehicle->fresh(), $tenant));
        $this->assertDatabaseHas('VEH_vehicle_status_events', ['vehicle_id' => $vehicle->id, 'actor_user_id' => $admin->id, 'reason' => 'Reparación de frenos']);
        $this->get(route('fleet.vehicles.show', $vehicle))->assertSee('Historial de disponibilidad')->assertSee('Reparación de frenos');
        $this->get(route('fleet.page.operations-fleet'))->assertSee('fleet-reason-tooltip', false)->assertSee('1.500 kg')->assertSee('12,0 m³');
        $this->patch($url, $initial)->assertSessionHasErrors('vehicle_state');
        $this->assertDatabaseCount('VEH_vehicle_status_events', 1);
        $this->patch($url, ['target_state' => 'available', 'reason' => 'Taller confirmó la reparación', 'close_maintenance' => true, 'closing' => ['execution_type' => 'internal', 'actual_cost' => 0, 'closed_odometer_km' => 0], 'expected_status' => 'En reparación', 'expected_revision' => $vehicle->fresh()->updated_at->toISOString()])->assertSessionHasNoErrors();
        $this->assertTrue(app(VehicleCatalog::class)->isOperational($vehicle->fresh(), $tenant));
        $this->assertDatabaseCount('VEH_vehicle_status_events', 2);
    }

    public function test_state_change_requires_edit_permission_for_the_owner_and_a_reason(): void
    {
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $reader = User::factory()->create();
        $this->associate($reader, $tenant, 'read_only');
        $vehicle = $this->createVehicle($tenant, $tenant, 'MOVE22', 'Camioneta');
        $url = route('fleet.vehicles.status', $vehicle);
        $this->actingAs($reader)->withSession(['fleet_tenant_id' => $tenant->id])->patch($url, [])->assertForbidden();
        $admin = User::factory()->create();
        $this->associate($admin, $tenant, 'administrator');
        $this->actingAs($admin)->patch($url, ['target_state' => 'unavailable'])->assertSessionHasErrors(['reason', 'reason_code', 'expected_status', 'expected_revision']);
        $foreign = $this->createVehicle($tenant, Tenant::where('code', 'PMCB')->firstOrFail(), 'MOVE33', 'Camión');
        $this->patch(route('fleet.vehicles.status', $foreign), [])->assertForbidden();
        $this->assertDatabaseCount('VEH_vehicle_status_events', 0);
        $this->assertSame('Activa', $vehicle->fresh()->operational_status);
    }

    public function test_in_progress_maintenance_prevents_reactivation_and_manual_warning_is_persisted(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 10:00:00', 'America/Santiago'));
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $admin = User::factory()->create();
        $this->associate($admin, $tenant, 'administrator');
        $vehicle = $this->createVehicle($tenant, $tenant, 'MOVE44', 'Camioneta', 'En mantención');
        $maintenance = VehicleMaintenance::create(['vehicle_id' => $vehicle->id, 'tenant_id' => $tenant->id, 'maintenance_type' => 'Frenos', 'execution_type' => 'external', 'status' => 'in_progress']);
        $this->actingAs($admin)->withSession(['fleet_tenant_id' => $tenant->id]);
        $url = route('fleet.vehicles.status', $vehicle);
        $data = ['target_state' => 'available', 'reason' => 'Verificación', 'expected_status' => 'En mantención', 'expected_revision' => $vehicle->updated_at->toISOString()];
        $this->patch($url, $data)->assertSessionHasErrors('vehicle_state');
        $this->assertDatabaseCount('VEH_vehicle_status_events', 0);
        $this->assertSame('En mantención', $vehicle->fresh()->operational_status);
        $maintenance->update(['status' => 'closed']);
        $this->patch($url, array_replace($data, ['target_state' => 'warning', 'next_maintenance_at' => '2026-10-11']))->assertSessionHasNoErrors();
        $this->assertSame('2026-10-11', $vehicle->fresh()->next_maintenance_at->toDateString());
        $response = $this->get(route('fleet.page.operations-fleet'));
        $this->assertSame('warning', $response->viewData('rows')->keyBy('vehicle.id')[$vehicle->id]['display_state']);
    }

    public function test_compact_sheet_totals_only_recorded_closed_costs_and_marks_expiration_dates(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 10:00:00', 'America/Santiago'));
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $admin = User::factory()->create();
        $this->associate($admin, $tenant, 'administrator');
        $vehicle = $this->createVehicle($tenant, $tenant, 'SHEET1', 'Camioneta');
        $vehicle->update(['technical_inspection_expires_at' => '2026-10-07', 'insurance_expires_at' => '2026-10-20', 'circulation_permit_expires_at' => '2027-03-31']);
        $certificate = DB::table('VEH_documents')->insertGetId(['tenant_id' => $tenant->id, 'sha256' => str_repeat('d', 64), 'kind' => 'technical_inspection', 'title' => 'Revisión técnica', 'filename' => 'revision.pdf', 'storage_path' => 'fleet/revision.pdf', 'details' => '{}']);
        DB::table('VEH_document_vehicles')->insert(['document_id' => $certificate, 'vehicle_id' => $vehicle->id, 'details' => json_encode(['verified_expirations' => ['technical_inspection_expires_at' => ['date' => '2026-10-07', 'display' => '07/10/2026']]])]);
        foreach ([['Frenos', 'closed', 50000, null], ['Aceite', 'closed', 20000, null], ['Motor', 'pending', null, 1000000], ['Cancelada', 'cancelled', 999999, null]] as [$type, $status, $cost, $estimate]) {
            VehicleMaintenance::create(['vehicle_id' => $vehicle->id, 'tenant_id' => $tenant->id, 'maintenance_type' => $type, 'execution_type' => 'external', 'status' => $status, 'actual_cost' => $cost, 'estimated_cost' => $estimate, 'notes' => 'Resumen del trabajo '.$type]);
        }

        $response = $this->actingAs($admin)->withSession(['fleet_tenant_id' => $tenant->id])->get(route('fleet.vehicles.show', $vehicle));

        $response->assertOk()->assertSee('vehicle-columns', false)->assertSee('Total gastado registrado')->assertSee('$70.000')->assertSee('Resumen del trabajo Frenos')->assertSee('07/10/2026')->assertSee('20/10/2026')->assertSee('Vencido')->assertSee('Por vencer')->assertSee('Vigente')->assertSee('Documentos y vencimientos');
    }

    public function test_personal_priority_persists_without_changing_vehicle_state_or_other_users_order(): void
    {
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $actor = User::factory()->create();
        $other = User::factory()->create();
        $this->associate($actor, $tenant, 'administrator');
        $this->associate($other, $tenant, 'administrator');
        $first = $this->createVehicle($tenant, $tenant, 'PRIOA1', 'Camioneta');
        $second = $this->createVehicle($tenant, $tenant, 'PRIOB2', 'Camioneta');
        $revision = $second->updated_at->toISOString();
        $this->actingAs($actor)->withSession(['fleet_tenant_id' => $tenant->id]);

        $this->patchJson(route('fleet.vehicles.priorities'), ['state' => 'available', 'vehicle_ids' => [$second->id, $first->id]])->assertOk();

        $response = $this->get(route('fleet.page.operations-fleet'));
        $this->assertSame([$second->id, $first->id], $response->viewData('rows')->pluck('vehicle.id')->all());
        $this->assertSame('Activa', $second->fresh()->operational_status);
        $this->assertSame($revision, $second->fresh()->updated_at->toISOString());
        $this->assertDatabaseCount('VEH_vehicle_status_events', 0);
        $this->assertDatabaseHas('VEH_board_priorities', ['user_id' => $actor->id, 'vehicle_id' => $second->id, 'state' => 'available', 'position' => 0]);
        $otherResponse = $this->actingAs($other)->get(route('fleet.page.operations-fleet'));
        $this->assertSame([$first->id, $second->id], $otherResponse->viewData('rows')->pluck('vehicle.id')->all());
    }

    public function test_priority_rejects_wrong_state_duplicate_ids_and_inaccessible_vehicles_without_saving(): void
    {
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $foreign = Tenant::where('code', 'PMCB')->firstOrFail();
        $actor = User::factory()->create();
        $this->associate($actor, $tenant, 'read_only');
        $own = $this->createVehicle($tenant, $tenant, 'ORDER1', 'Camioneta');
        $hidden = $this->createVehicle($foreign, $foreign, 'ORDER2', 'Camioneta');
        $this->actingAs($actor)->withSession(['fleet_tenant_id' => $tenant->id]);

        $this->patchJson(route('fleet.vehicles.priorities'), ['state' => 'maintenance', 'vehicle_ids' => [$own->id]])->assertUnprocessable()->assertJsonValidationErrors('state');
        $this->patchJson(route('fleet.vehicles.priorities'), ['state' => 'available', 'vehicle_ids' => [$own->id, $own->id]])->assertUnprocessable()->assertJsonValidationErrors('vehicle_ids.0');
        $this->patchJson(route('fleet.vehicles.priorities'), ['state' => 'available', 'vehicle_ids' => [$own->id, $hidden->id]])->assertNotFound();

        $this->assertDatabaseCount('VEH_board_priorities', 0);
    }

    public function test_vehicle_sheet_driver_assignments_are_scoped_to_service_company(): void
    {
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $foreign = Tenant::where('code', 'PMCB')->firstOrFail();
        $admin = User::factory()->create();
        $driver = User::factory()->create(['name' => 'Conductor visible']);
        $hiddenDriver = User::factory()->create(['name' => 'Conductor de otra empresa']);
        $this->associate($admin, $tenant, 'administrator');
        $vehicle = $this->createVehicle($tenant, $tenant, 'DRVSHEET', 'Camioneta');
        $client = Client::create(['tenant_id' => $tenant->id, 'commercial_name' => 'Cliente', 'legal_name' => 'Cliente SPA', 'tax_id' => '76543210-0', 'tax_id_number' => '76543210', 'tax_id_check_digit' => '0', 'is_active' => true]);
        $point = ClientBranch::create(['client_id' => $client->id, 'code' => 'P1', 'name' => 'Bodega', 'address' => 'Calle 123', 'commune_name' => 'Renca', 'is_active' => true]);
        $type = ServiceType::create(['service_code' => 991, 'name' => 'Retiro', 'is_active' => true]);
        $ret = ServiceRequest::create(['tenant_id' => $tenant->id, 'client_id' => $client->id, 'client_branch_id' => $point->id, 'service_type_id' => $type->id, 'requested_by_user_id' => $admin->id, 'requested_date_original' => '2026-10-08', 'service_date' => '2026-10-08', 'shift' => 'AM', 'window_start' => '09:00', 'window_end' => '12:00', 'packages' => 5, 'material' => 'Bultos', 'client_name_snapshot' => 'Cliente', 'legal_name_snapshot' => 'Cliente SPA', 'point_name_snapshot' => 'Bodega', 'address_snapshot' => 'Calle 123', 'effective_address' => 'Calle 123', 'status' => 'scheduled', 'vehicle_id' => $vehicle->id, 'driver_user_id' => $driver->id]);
        $hidden = $ret->replicate();
        $hidden->tenant_id = $foreign->id;
        $hidden->driver_user_id = $hiddenDriver->id;
        $hidden->save();

        $response = $this->actingAs($admin)->withSession(['fleet_tenant_id' => $tenant->id])->get(route('fleet.vehicles.show', $vehicle));
        $response->assertOk()->assertSee('Conductor visible')->assertDontSee('Conductor de otra empresa');
        $this->assertSame([$ret->id], $response->viewData('driverAssignments')->modelKeys());
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
