<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientBranch;
use App\Models\ClientBranchContact;
use App\Models\FixedPickup;
use App\Models\ServiceRequest;
use App\Models\ServiceType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FleetCoordinationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Tenant $fourN;

    private Tenant $pmcb;

    private Client $client;

    private ClientBranch $point;

    private FixedPickup $fixed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fourN = Tenant::where('code', '4N')->firstOrFail();
        $this->pmcb = Tenant::where('code', 'PMCB')->firstOrFail();
        $this->admin = User::factory()->create();
        foreach ([$this->fourN, $this->pmcb] as $tenant) {
            DB::table('tenant_users')->insert([
                'tenant_id' => $tenant->id, 'user_id' => $this->admin->id,
                'role_code' => 'administrator', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->actingAs($this->admin)->withSession(['fleet_tenant_id' => $this->fourN->id]);
        $this->client = Client::create([
            'tenant_id' => $this->fourN->id, 'tax_id' => '76543210-0',
            'tax_id_number' => '76543210', 'tax_id_check_digit' => '0',
            'commercial_name' => 'Cliente prueba', 'legal_name' => 'Cliente prueba SPA', 'is_active' => true,
        ]);
        $retireType = ServiceType::create(['service_code' => 1, 'name' => 'Retiro', 'is_active' => true]);
        $this->point = ClientBranch::create([
            'client_id' => $this->client->id, 'code' => 'P1', 'name' => 'Punto prueba',
            'address' => 'Calle 123', 'commune_name' => 'Santiago',
            'address_status' => 'ready', 'operational_email_pending' => true, 'is_active' => true,
        ]);
        $this->fixed = FixedPickup::create([
            'tenant_id' => $this->fourN->id, 'client_id' => $this->client->id,
            'client_branch_id' => $this->point->id,
            'service_type_id' => $retireType->id,
            'source_client_name' => 'Cliente prueba', 'source_point_name' => 'Punto prueba',
            'association_status' => 'linked', 'weekdays' => [1], 'shift' => 'AM',
            'window_start' => '09:00', 'window_end' => '10:00',
            'material' => 'Cajas', 'usual_packages' => null,
            'operational_email_pending' => true, 'is_active' => true,
        ]);
    }

    public function test_fixed_pickup_requires_packages_and_original_occurrence_cannot_be_imported_twice(): void
    {
        $payload = ['service_date' => '2026-09-28', 'single_id' => $this->fixed->id];
        $this->post(route('fleet.fixed.convert'), $payload)->assertSessionHasErrors('packages');
        $this->assertDatabaseCount('service_requests', 0);

        $this->post(route('fleet.fixed.convert'), $payload + ['packages' => [$this->fixed->id => 3]])
            ->assertRedirect(route('fleet.page.coordination-fixed-pickups', ['fecha' => '2026-09-28']));
        $ret = ServiceRequest::firstOrFail();
        $this->assertSame('RET-'.str_pad((string) $ret->id, 5, '0', STR_PAD_LEFT), $ret->ret_code);
        $this->assertSame(3, $ret->packages);
        $this->assertSame('Cajas', $ret->material);
        $this->assertNull($ret->operational_emails_snapshot);
        $this->post(route('fleet.fixed.convert'), $payload + ['packages' => [$this->fixed->id => 3]])
            ->assertSessionHasErrors('fixed_pickup_id');
        $this->assertDatabaseCount('service_requests', 1);
    }

    public function test_pending_address_blocks_ret_but_missing_email_does_not(): void
    {
        $this->point->update(['address_status' => 'pending']);
        $form = [
            'client_id' => $this->client->id, 'client_branch_id' => $this->point->id,
            'service_type_id' => $this->fixed->service_type_id,
            'service_date' => '2026-09-28', 'shift' => 'AM',
            'window_start' => '09:00', 'window_end' => '10:00',
            'packages' => 2, 'material' => 'Cajas',
        ];
        $this->post(route('fleet.requests.store'), $form)->assertSessionHasErrors('client_branch_id');
        $this->point->update(['address_status' => 'ready']);
        $this->post(route('fleet.requests.store'), $form)->assertRedirect();
        $ret = ServiceRequest::firstOrFail();
        $this->get(route('fleet.requests.show', $ret))->assertOk()->assertSee('Correo operacional pendiente');
    }

    public function test_reprogramming_keeps_original_date_and_requires_postventa_decision(): void
    {
        $this->post(route('fleet.fixed.convert'), [
            'service_date' => '2026-09-28', 'single_id' => $this->fixed->id,
            'packages' => [$this->fixed->id => 2],
        ]);
        $ret = ServiceRequest::firstOrFail();
        $this->post(route('fleet.requests.schedule', $ret))->assertRedirect();
        $this->post(route('fleet.requests.reprogram', $ret), [
            'reason' => 'Cliente solicita cambio', 'proposed_date' => '2026-09-29',
            'proposed_window_start' => '11:00', 'proposed_window_end' => '12:00',
        ])->assertRedirect();
        $ret->refresh();
        $this->assertSame('reprogramming_pending', $ret->status);
        $this->assertSame('2026-09-28', $ret->service_date->toDateString());
        $decision = $ret->reprogrammings()->firstOrFail();
        $this->post(route('fleet.reprogramming.decide', $decision), [
            'decision' => 'approved', 'customer_response' => 'Cliente confirma la nueva fecha',
        ])->assertRedirect();
        $ret->refresh();
        $this->assertSame('rescheduled', $ret->status);
        $this->assertSame('2026-09-28', $ret->requested_date_original->toDateString());
        $this->assertSame('2026-09-29', $ret->service_date->toDateString());
        $this->post(route('fleet.fixed.convert'), [
            'service_date' => '2026-09-28', 'single_id' => $this->fixed->id,
            'packages' => [$this->fixed->id => 2],
        ])->assertSessionHasErrors('fixed_pickup_id');
    }

    public function test_client_points_and_requests_are_isolated_by_tenant(): void
    {
        $otherPoint = $this->point->replicate();
        $otherPoint->code = 'P2';
        $otherPoint->name = 'Segundo punto';
        $otherPoint->save();
        ClientBranchContact::create(['client_branch_id' => $this->point->id, 'name' => 'Contacto A', 'email' => 'a@example.test']);
        ClientBranchContact::create(['client_branch_id' => $otherPoint->id, 'name' => 'Contacto B', 'email' => 'b@example.test']);
        $this->get(route('fleet.clients.points', $this->client))->assertOk()->assertJsonCount(2)
            ->assertJsonFragment(['name' => 'Contacto A'])->assertJsonFragment(['name' => 'Contacto B']);
        $differentClient = Client::create([
            'tenant_id' => $this->fourN->id, 'tax_id' => '11111111-1',
            'tax_id_number' => '11111111', 'tax_id_check_digit' => '1',
            'commercial_name' => 'Otro cliente', 'legal_name' => 'Otro cliente SPA', 'is_active' => true,
        ]);
        $foreignPoint = $this->point->replicate();
        $foreignPoint->client_id = $differentClient->id;
        $foreignPoint->save();
        ClientBranchContact::create(['client_branch_id' => $foreignPoint->id, 'name' => 'Contacto ajeno']);
        $this->get(route('fleet.clients.points', $this->client))->assertOk()->assertJsonMissing(['name' => 'Contacto ajeno']);
        $this->withSession(['fleet_tenant_id' => $this->pmcb->id]);
        $this->get(route('fleet.clients.points', $this->client))->assertNotFound();
        $this->get(route('fleet.page.coordination-fixed-pickups'))->assertOk()->assertDontSee('Cliente prueba');
    }

    public function test_same_point_can_have_two_windows_and_matrix_marks_original_occurrences(): void
    {
        $second = $this->fixed->replicate();
        $second->window_start = '15:00';
        $second->window_end = '16:00';
        $second->save();
        $this->post(route('fleet.fixed.convert'), [
            'service_date' => '2026-09-28', 'action' => 'all',
            'packages' => [$this->fixed->id => 2, $second->id => 4],
        ])->assertRedirect();
        $this->assertDatabaseCount('service_requests', 2);
        $this->assertSame(2, ServiceRequest::distinct()->count('ret_code'));
        $this->get(route('fleet.page.coordination-fixed-pickups', ['fecha' => '2026-09-28']))
            ->assertOk()->assertSee('En solicitudes');
        $this->get(route('fleet.page.coordination-fixed-pickups', ['fecha' => '2026-09-29']))
            ->assertOk()->assertSee('Otro día');
        $this->fixed->update(['is_active' => false]);
        $this->get(route('fleet.page.coordination-fixed-pickups', ['fecha' => '2026-10-05']))
            ->assertOk()->assertSee('Inactivo');
        $this->post(route('fleet.fixed.convert'), [
            'service_date' => '2026-10-05', 'single_id' => $this->fixed->id,
            'packages' => [$this->fixed->id => 2],
        ])->assertSessionHasErrors('ids');
    }

    public function test_rejected_reprogramming_keeps_current_agenda_and_direct_url_requires_permission(): void
    {
        $this->post(route('fleet.fixed.convert'), [
            'service_date' => '2026-09-28', 'single_id' => $this->fixed->id,
            'packages' => [$this->fixed->id => 2],
        ]);
        $ret = ServiceRequest::firstOrFail();
        $this->post(route('fleet.requests.schedule', $ret));
        $this->post(route('fleet.requests.reprogram', $ret), [
            'reason' => 'Cambio solicitado', 'proposed_date' => '2026-09-29',
            'proposed_window_start' => '11:00', 'proposed_window_end' => '12:00',
        ]);
        $decision = $ret->reprogrammings()->firstOrFail();
        $this->post(route('fleet.reprogramming.decide', $decision), [
            'decision' => 'rejected', 'customer_response' => 'Cliente mantiene fecha original',
        ])->assertRedirect();
        $ret->refresh();
        $this->assertSame('scheduled', $ret->status);
        $this->assertSame('2026-09-28', $ret->service_date->toDateString());

        $operator = User::factory()->create();
        DB::table('tenant_users')->insert([
            'tenant_id' => $this->fourN->id, 'user_id' => $operator->id,
            'role_code' => 'operations', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($operator)->withSession(['fleet_tenant_id' => $this->fourN->id]);
        $this->get(route('fleet.reprogramming.index'))->assertForbidden();
        $this->post(route('fleet.reprogramming.decide', $decision), [
            'decision' => 'approved', 'customer_response' => 'Intento no autorizado',
        ])->assertForbidden();
    }
}
