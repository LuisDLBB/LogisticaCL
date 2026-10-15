<?php

namespace Tests\Feature;

use App\Fleet\CoordinationService;
use App\Fleet\MicrosoftRetMail;
use App\Fleet\PickupCustomerService;
use App\Models\Client;
use App\Models\ClientBranch;
use App\Models\FixedPickup;
use App\Models\ServiceRequest;
use App\Models\ServiceType;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PickupCustomerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $this->travelTo(Carbon::parse('2026-10-01 10:00:00', 'America/Santiago'));
        Storage::fake('local');
        Http::preventStrayRequests();
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $actor = User::factory()->create(['email' => 'ejecutivo@example.test']);
        FleetTestMembership::insert(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'role_code' => 'administrator', 'is_active' => true]);
        $this->actingAs($actor)->withSession(['fleet_tenant_id' => $tenant->id]);
        $client = Client::create(['tenant_id' => $tenant->id, 'commercial_name' => 'Cliente prueba', 'legal_name' => 'Cliente SPA', 'tax_id' => '76543210-0', 'tax_id_number' => '76543210', 'tax_id_check_digit' => '0', 'is_active' => true]);
        $point = ClientBranch::create(['client_id' => $client->id, 'code' => 'CP01', 'name' => 'Bodega', 'address' => 'Dorsal 3225', 'commune_name' => 'Renca', 'address_status' => 'ready', 'operational_emails' => 'cliente@example.test', 'is_active' => true]);
        $type = ServiceType::create(['service_code' => 990, 'name' => 'Retiro', 'is_active' => true]);

        return compact('tenant', 'actor', 'client', 'point', 'type');
    }

    private function ret(array $c, bool $fixed = false): ServiceRequest
    {
        $plan = $fixed ? FixedPickup::create(['tenant_id' => $c['tenant']->id, 'client_id' => $c['client']->id, 'client_branch_id' => $c['point']->id, 'service_type_id' => $c['type']->id, 'source_client_name' => $c['client']->commercial_name, 'source_point_name' => $c['point']->name, 'association_status' => 'linked', 'is_active' => true, 'material' => 'Bultos', 'usual_packages' => 5, 'shift' => 'AM', 'window_start' => '09:00', 'window_end' => '10:00', 'weekdays' => [5]]) : null;

        return app(CoordinationService::class)->createRequest($c['actor'], $c['client'], $c['point'], $c['type']->id, ['service_date' => '2026-10-02', 'window_start' => '09:00', 'window_end' => '10:00', 'shift' => 'AM', 'packages' => 5, 'load_unit' => 'loose'], $plan);
    }

    private function notification(ServiceRequest $ret): object
    {
        return DB::table('ret_customer_notifications')->where('service_request_id', $ret->id)->where('kind', 'receipt')->first();
    }

    private function customerUrl(ServiceRequest $ret, string $action = 'correction'): string
    {
        preg_match('~href="([^"]*/retiro-cliente/[^"?]+/'.$action.')"~', $this->notification($ret)->html, $matches);

        return html_entity_decode($matches[1]);
    }

    public function test_pending_address_can_be_saved_and_new_point_is_reusable(): void
    {
        $c = $this->context();
        $c['point']->update(['address' => '', 'address_status' => 'pending']);
        $url = route('fleet.requests.point.save', $c['client']);
        $this->postJson($url, ['point_id' => $c['point']->id, 'name' => 'Bodega', 'address' => 'Nueva 123', 'commune' => 'Santiago'])->assertOk()->assertJsonPath('address', 'Nueva 123');
        $this->assertSame('ready', $c['point']->fresh()->address_status);
        $this->postJson($url, ['point_id' => $c['point']->id, 'name' => 'Bodega', 'address' => 'Otra 100', 'commune' => 'Renca'])->assertStatus(409);
        $response = $this->postJson($url, ['name' => 'Segundo punto', 'address' => 'Otra 100', 'commune' => 'Renca'])->assertOk();
        $this->assertDatabaseHas('PPR_client_branches', ['id' => $response->json('id'), 'client_id' => $c['client']->id, 'address_status' => 'ready']);
        $this->getJson(route('fleet.clients.points', $c['client']))->assertJsonCount(2);
    }

    public function test_points_are_scoped_to_the_selected_company_and_client(): void
    {
        $c = $this->context();
        $other = Client::create(['tenant_id' => Tenant::where('code', 'PMCB')->value('id'), 'commercial_name' => 'Otro', 'legal_name' => 'Otro', 'tax_id' => '1-9', 'tax_id_number' => '1', 'tax_id_check_digit' => '9', 'is_active' => true]);
        $this->postJson(route('fleet.requests.point.save', $other), ['name' => 'Nuevo', 'address' => '123', 'commune' => 'Renca'])->assertNotFound();
        $point = ClientBranch::create(['client_id' => $other->id, 'code' => 'OTHER', 'name' => 'Otro', 'address' => '', 'commune_name' => '', 'address_status' => 'pending', 'is_active' => true]);
        $this->postJson(route('fleet.requests.point.save', $c['client']), ['point_id' => $point->id, 'name' => 'Otro', 'address' => '123', 'commune' => 'Renca'])->assertNotFound();
    }

    public function test_variable_receipt_has_merchant_quantity_and_no_cancel_button_and_is_unique(): void
    {
        $c = $this->context();
        $ret = $this->ret($c);
        app(PickupCustomerService::class)->receipt($ret);
        $this->assertDatabaseCount('ret_customer_notifications', 1);
        $row = $this->notification($ret);
        $this->assertStringContainsString('Hola, Cliente prueba', $row->html);
        $this->assertStringContainsString('5 bultos sueltos', $row->html);
        $this->assertStringContainsString('Operaciones está revisándola', $row->html);
        $this->assertStringNotContainsString('No tengo carga', $row->html);
        $this->get(route('fleet.requests.show', $ret))->assertOk()->assertSee('Aviso al cliente')->assertSee('Enviar prueba a Hans');
        app(PickupCustomerService::class)->deliver($row->id);
        $this->assertDatabaseHas('ret_customer_notifications', ['id' => $row->id, 'status' => 'captured']);
        Http::assertNothingSent();
    }

    public function test_variable_cannot_cancel_using_a_modified_link(): void
    {
        $c = $this->context();
        $ret = $this->ret($c);
        $url = str_replace('/correction', '/cancel', $this->customerUrl($ret));
        $this->get($url)->assertNotFound();
        $this->post($url, ['confirmed' => 1, 'reason' => 'Sin carga'])->assertNotFound();
        $this->assertSame('requested', $ret->fresh()->status);
    }

    public function test_fixed_cancel_requires_post_confirmation_and_preserves_the_plan(): void
    {
        $c = $this->context();
        $ret = $this->ret($c, true);
        $url = $this->customerUrl($ret, 'cancel');
        Auth::logout();
        $this->get($url)->assertOk()->assertSee('Confirmar cancelación');
        $this->assertSame('requested', $ret->fresh()->status);
        $this->post($url, ['reason' => 'Sin pedidos'])->assertSessionHasErrors('confirmed');
        $this->post($url, ['confirmed' => 1, 'reason' => 'Sin pedidos'])->assertRedirect(route('fleet.customer.done'));
        $this->assertSame('cancelled', $ret->fresh()->status);
        $this->actingAs($c['actor'])->withSession(['fleet_tenant_id' => $c['tenant']->id]);
        $this->get(route('fleet.page.coordination-requests'))->assertOk()->assertSee('ret-customer-cancelled', false)->assertSee('Cancelado por el cliente')->assertSee('Sin pedidos');
        $this->get(route('fleet.requests.show', $ret))->assertOk()->assertSee('Este retiro no debe realizarse.')->assertSee('Sin pedidos');
        $this->assertTrue($ret->fixedPickup->fresh()->is_active);
        $notice = DB::table('ret_customer_notifications')->where('service_request_id', $ret->id)->where('kind', 'cancellation')->first();
        $this->assertSame('cliente@example.test', $notice->recipients);
        $this->assertStringContainsString('ejecutivo@example.test', $notice->cc);
        $this->assertDatabaseHas('service_request_events', ['service_request_id' => $ret->id, 'action' => 'customer_cancel']);
        $this->post($url, ['confirmed' => 1, 'reason' => 'Repetir'])->assertNotFound();
    }

    public function test_customer_correction_creates_a_task_for_the_original_requester(): void
    {
        $c = $this->context();
        $ret = $this->ret($c);
        $url = $this->customerUrl($ret);
        Auth::logout();
        $this->post($url, ['confirmed' => 1, 'reason' => 'Ahora son siete bultos', 'packages' => 7, 'package_weights' => [1, 2, 3, 4, 5, 6, 7]])->assertRedirect(route('fleet.customer.done'));
        $this->assertDatabaseHas('ret_coordination_tasks', ['service_request_id' => $ret->id, 'assigned_to_user_id' => $c['actor']->id, 'kind' => 'correction', 'status' => 'pending']);
        $this->assertSame(7, $ret->fresh()->packages);
        $this->assertSame(5, $ret->fresh()->original_data['packages']);
        $this->assertEquals(28, $ret->fresh()->requested_weight_kg);
        $this->assertStringContainsString('Peso total: 28 kg', DB::table('ret_coordination_tasks')->where('service_request_id', $ret->id)->value('reason'));
        $this->actingAs($c['actor'])->withSession(['fleet_tenant_id' => $c['tenant']->id]);
        $this->get(route('fleet.page.coordination-requests'))->assertOk()->assertSee('ret-customer-correction', false)->assertSee('Cliente solicita corrección')->assertSee('Ahora son siete bultos')->assertSee('7 bultos');
        $this->actingAs($c['actor'])->withSession(['fleet_tenant_id' => $c['tenant']->id]);
        $this->get(route('fleet.requests.show', ['serviceRequest' => $ret, 'vista' => 'solicitante']))->assertOk()->assertSee('Ahora son siete bultos');
        $task = DB::table('ret_coordination_tasks')->first();
        $this->post(route('fleet.requests.task.resolve', [$ret, $task->id]), ['decision' => 'confirmed', 'customer_response' => 'Coordinado con el cliente'])->assertSessionHasNoErrors();
        $this->get(route('fleet.page.coordination-requests'))->assertOk()->assertDontSee('Cliente solicita corrección')->assertDontSee('ret-customer-correction', false);
    }

    public function test_expired_customer_links_do_not_allow_actions(): void
    {
        $c = $this->context();
        $ret = $this->ret($c, true);
        $url = $this->customerUrl($ret, 'cancel');
        DB::table('ret_customer_notifications')->where('id', $this->notification($ret)->id)->update(['expires_at' => now()->subMinute()]);
        $this->get($url)->assertNotFound();
        $this->post($url, ['confirmed' => 1, 'reason' => 'Sin carga'])->assertNotFound();
        $this->assertSame('requested', $ret->fresh()->status);
    }

    private function reschedule(ServiceRequest $ret): object
    {
        $this->post(route('fleet.requests.requester-reschedule', $ret), ['reason_code' => 'vehicle', 'reason_detail' => 'Mantención', 'proposed_date' => '2026-10-03', 'proposed_start' => '10:15', 'proposed_end' => '11:15'])->assertSessionHasNoErrors();

        return DB::table('ret_coordination_tasks')->where('service_request_id', $ret->id)->first();
    }

    public function test_operations_proposes_without_changing_the_agenda_then_requester_confirms(): void
    {
        $c = $this->context();
        $ret = $this->ret($c);
        $ret->update(['status' => 'scheduled', 'driver_user_id' => $c['actor']->id, 'scheduled_at' => now()]);
        $original = $ret->original_data;
        $task = $this->reschedule($ret);
        $this->assertSame($c['actor']->id, $task->assigned_to_user_id);
        $this->assertSame('2026-10-02', $ret->fresh()->service_date->toDateString());
        $this->assertSame('reprogramming_pending', $ret->fresh()->status);
        $this->get(route('fleet.requests.show', $ret))->assertOk()->assertSee('Coordinación con el cliente');
        $this->post(route('fleet.requests.task.resolve', [$ret, $task->id]), ['decision' => 'confirmed', 'customer_response' => 'Cliente confirma', 'service_date' => '2026-10-03', 'window_start' => '10:15', 'window_end' => '11:15'])->assertSessionHasNoErrors();
        $this->assertSame('requested', $ret->fresh()->status);
        $this->assertSame('2026-10-03', $ret->fresh()->service_date->toDateString());
        $this->assertNull($ret->fresh()->driver_user_id);
        $this->assertNull($ret->fresh()->scheduled_at);
        $this->assertSame($original, $ret->fresh()->original_data);
        $this->assertSame('2026-10-02', $ret->fresh()->requested_date_original->toDateString());
    }

    public function test_declined_proposal_restores_previous_schedule_and_no_duplicate_pending_task(): void
    {
        $c = $this->context();
        $ret = $this->ret($c);
        $ret->update(['status' => 'scheduled']);
        $task = $this->reschedule($ret);
        $this->post(route('fleet.requests.requester-reschedule', $ret), ['reason_code' => 'customer', 'proposed_date' => '2026-10-03', 'proposed_start' => '10:00', 'proposed_end' => '11:00'])->assertStatus(409);
        $this->post(route('fleet.requests.task.resolve', [$ret, $task->id]), ['decision' => 'rejected', 'customer_response' => 'Mantener fecha'])->assertSessionHasNoErrors();
        $this->assertSame('scheduled', $ret->fresh()->status);
        $this->assertSame('2026-10-02', $ret->fresh()->service_date->toDateString());
    }

    public function test_unassigned_user_cannot_resolve_and_other_reason_needs_detail(): void
    {
        $c = $this->context();
        $ret = $this->ret($c);
        $this->post(route('fleet.requests.requester-reschedule', $ret), ['reason_code' => 'other', 'proposed_date' => '2026-10-03', 'proposed_start' => '10:00', 'proposed_end' => '11:00'])->assertSessionHasErrors('reason_detail');
        $task = $this->reschedule($ret);
        $user = User::factory()->create();
        FleetTestMembership::insert(['tenant_id' => $c['tenant']->id, 'user_id' => $user->id, 'role_code' => 'commercial', 'is_active' => true]);
        $this->actingAs($user)->get(route('fleet.requests.show', $ret))->assertOk();
        $this->post(route('fleet.requests.task.resolve', [$ret, $task->id]), ['decision' => 'rejected', 'customer_response' => 'Sin autorización'])->assertForbidden();
        $this->assertDatabaseHas('ret_coordination_tasks', ['id' => $task->id, 'status' => 'pending']);
    }

    public function test_real_send_is_deduplicated_and_test_send_has_no_client_copy(): void
    {
        $c = $this->context();
        $ret = $this->ret($c);
        $row = $this->notification($ret);
        $mail = app(MicrosoftRetMail::class);
        $mail->save($c['tenant']->id, ['account' => MicrosoftRetMail::SENDER, 'access_token' => 'fake-test-token', 'expires_at' => now()->timestamp + 3600]);
        Http::fake(['https://graph.microsoft.com/v1.0/me/sendMail' => Http::response('', 202)]);
        $customer = app(PickupCustomerService::class);
        $customer->deliver($row->id, false, true);
        $customer->deliver($row->id, false, true);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('ret_customer_notifications', ['id' => $row->id, 'status' => 'accepted']);
        Http::assertSent(fn ($r) => $r['message']['toRecipients'][0]['emailAddress']['address'] === 'cliente@example.test' && $r['message']['replyTo'][0]['emailAddress']['address'] === 'ejecutivo@example.test');
        $customer->deliver($row->id, true, true);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r['message']['toRecipients'][0]['emailAddress']['address'] === MicrosoftRetMail::TEST_RECIPIENT && ! isset($r['message']['ccRecipients']));
    }

    public function test_operations_dialog_only_proposes_date_time_and_reason(): void
    {
        $c = $this->context();
        $ret = $this->ret($c);
        $this->get(route('fleet.requests.show', $ret))->assertOk()->assertSee('Enviar a quien solicitó el retiro')->assertSee('Contingencia mecánica')->assertDontSee('Cantidad de bultos</label>', false);
        $this->get(route('fleet.requests.show', ['serviceRequest' => $ret, 'vista' => 'solicitante']))->assertOk()->assertSee('Editar solicitud')->assertDontSee('Agenda de Operaciones');
    }

    public function test_failed_test_copy_preserves_an_already_accepted_customer_email(): void
    {
        $c = $this->context();
        $ret = $this->ret($c);
        $row = $this->notification($ret);
        DB::table('ret_customer_notifications')->where('id', $row->id)->update(['status' => 'accepted']);
        app(MicrosoftRetMail::class)->save($c['tenant']->id, ['account' => MicrosoftRetMail::SENDER, 'access_token' => 'fake-token', 'expires_at' => now()->timestamp + 3600]);
        Http::fake(['https://graph.microsoft.com/v1.0/me/sendMail' => Http::response('', 400)]);
        try {
            app(PickupCustomerService::class)->deliver($row->id, true, true);
            $this->fail('Microsoft rechazó la copia de prueba.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('microsoft', $exception->errors());
        }
        $this->assertDatabaseHas('ret_customer_notifications', ['id' => $row->id, 'status' => 'accepted']);
        app(PickupCustomerService::class)->deliver($row->id, false, true);
        Http::assertSentCount(1);
    }

    public function test_internal_cancellation_is_not_labelled_as_a_customer_response(): void
    {
        $c = $this->context();
        $ret = $this->ret($c);
        $this->post(route('fleet.requests.cancel', $ret), ['reason' => 'Cancelación administrativa'])->assertSessionHasNoErrors();
        $this->get(route('fleet.page.coordination-requests'))->assertOk()->assertSee('Anulado')->assertDontSee('ret-customer-cancelled', false);
        $this->get(route('fleet.requests.show', $ret))->assertOk()->assertSee('Cancelación administrativa')->assertDontSee('Este retiro no debe realizarse.');
    }

    public function test_correction_validates_weights_only_when_provided(): void
    {
        $c = $this->context();
        $ret = $this->ret($c);
        $url = $this->customerUrl($ret);
        Auth::logout();
        $this->get($url)->assertOk()->assertSee('Nueva cantidad de bultos')->assertSee('Peso de cada bulto')->assertSee('20 minutos');
        $this->post($url, ['confirmed' => 1])->assertSessionHasErrors('packages');
        $this->post($url, ['confirmed' => 1, 'packages' => 2, 'package_weights' => [5]])->assertSessionHasErrors('package_weights');
        $this->post($url, ['confirmed' => 1, 'packages' => 2, 'package_weights' => [5, 0]])->assertSessionHasErrors('package_weights.1');
        $this->assertDatabaseCount('ret_coordination_tasks', 0);
        $this->assertNull($this->notification($ret)->responded_at);
        $this->post($url, ['confirmed' => 1, 'packages' => 2, 'package_weights' => [5.5, 10]])->assertRedirect(route('fleet.customer.done'));
        $this->assertStringContainsString('Peso total: 15.5 kg', DB::table('ret_coordination_tasks')->value('reason'));
        $this->assertSame(2, $ret->fresh()->packages);
    }

    public function test_correction_deadline_keeps_original_quantity_and_cancel_remains_available(): void
    {
        $c = $this->context();
        $ret = $this->ret($c, true);
        $url = $this->customerUrl($ret);
        $cancel = $this->customerUrl($ret, 'cancel');
        Auth::logout();
        $this->travel(20)->minutes();
        $this->get($url)->assertOk()->assertSee('El plazo de 20 minutos terminó.')->assertDontSee('Enviar carga e indicaciones');
        $this->post($url, ['confirmed' => 1, 'packages' => 1, 'package_weights' => [5]])->assertSessionHasErrors('correction');
        $this->assertSame(5, $ret->fresh()->packages);
        $this->assertDatabaseCount('ret_coordination_tasks', 0);
        $this->post($cancel, ['confirmed' => 1, 'reason' => 'Sin carga'])->assertRedirect(route('fleet.customer.done'));
        $this->assertSame('cancelled', $ret->fresh()->status);
    }

    public function test_customer_can_report_total_weight_without_individual_weights(): void
    {
        $c = $this->context();
        $ret = $this->ret($c);
        $url = $this->customerUrl($ret);
        Auth::logout();

        $this->post($url, ['confirmed' => 1, 'packages' => 4, 'weight_mode' => 'total', 'total_weight_kg' => 0])->assertSessionHasErrors('total_weight_kg');
        $this->post($url, ['confirmed' => 1, 'packages' => 4, 'weight_mode' => 'unknown', 'package_weights' => [1, 2, 3, 4]])->assertSessionHasErrors('weight_mode');
        $this->post($url, ['confirmed' => 1, 'packages' => 4, 'weight_mode' => 'total', 'total_weight_kg' => 35.5, 'package_weights' => [999]])->assertRedirect(route('fleet.customer.done'));
        $reason = DB::table('ret_coordination_tasks')->value('reason');
        $this->assertStringContainsString('Nueva cantidad: 4 bultos', $reason);
        $this->assertStringContainsString('Peso total: 35.5 kg', $reason);
        $this->assertStringNotContainsString('999', $reason);
        $this->assertSame(4, $ret->fresh()->packages);
        $this->assertEquals(35.5, $ret->fresh()->requested_weight_kg);
    }

    public function test_customer_can_correct_quantity_without_weight_in_either_mode(): void
    {
        $c = $this->context();
        foreach (['total', 'individual'] as $mode) {
            $ret = $this->ret($c);
            $url = $this->customerUrl($ret);
            Auth::logout();
            $this->post($url, ['confirmed' => 1, 'packages' => 3, 'weight_mode' => $mode, 'package_weights' => [null, null, null]])->assertRedirect(route('fleet.customer.done'));
            $reason = DB::table('ret_coordination_tasks')->where('service_request_id', $ret->id)->value('reason');
            $this->assertStringContainsString('Nueva cantidad: 3 bultos', $reason);
            $this->assertStringContainsString('Peso total: Sin informar', $reason);
            $this->assertStringNotContainsString('Peso total: 0', $reason);
            $this->assertSame(3, $ret->fresh()->packages);
            $this->assertNull($ret->fresh()->requested_weight_kg);
            $this->assertSame(5, $ret->fresh()->original_data['packages']);
        }
    }

    public function test_automatic_test_receipt_goes_to_hans_once_and_never_to_customer(): void
    {
        $c = $this->context();
        config()->set('fleet.test_tools_enabled', true);
        config()->set('fleet.auto_test_mode', true);
        $ret = $this->ret($c);
        $row = $this->notification($ret);
        $this->assertTrue($ret->is_test);
        app()->detectEnvironment(fn () => 'local');
        $mail = $this->mock(MicrosoftRetMail::class);
        $mail->shouldReceive('settings')->with($ret->tenant_id)->andReturn(['automatic_receipts' => true]);
        $mail->shouldReceive('sendTest')->once()->with($ret->tenant_id, $row->subject, $row->html);
        $mail->shouldNotReceive('sendCustomer');
        app(PickupCustomerService::class)->deliver($row->id);
        app(PickupCustomerService::class)->deliver($row->id);
        $this->assertDatabaseHas('ret_customer_notifications', ['id' => $row->id, 'status' => 'test_accepted', 'last_error' => null]);
        Http::assertNothingSent();
    }

    public function test_ambiguous_automatic_test_failure_is_not_retried_automatically(): void
    {
        $c = $this->context();
        config()->set('fleet.test_tools_enabled', true);
        config()->set('fleet.auto_test_mode', true);
        $ret = $this->ret($c);
        $row = $this->notification($ret);
        app()->detectEnvironment(fn () => 'local');
        $mail = $this->mock(MicrosoftRetMail::class);
        $mail->shouldReceive('settings')->andReturn(['automatic_receipts' => true]);
        $mail->shouldReceive('sendTest')->once()->andThrow(new ConnectionException('Connection lost'));
        $mail->shouldNotReceive('sendCustomer');
        app(PickupCustomerService::class)->deliver($row->id);
        app(PickupCustomerService::class)->deliver($row->id);
        $this->assertDatabaseHas('ret_customer_notifications', ['id' => $row->id, 'status' => 'uncertain']);
        Http::assertNothingSent();
    }
}
