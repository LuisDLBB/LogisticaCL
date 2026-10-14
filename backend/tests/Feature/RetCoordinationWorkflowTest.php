<?php

namespace Tests\Feature;

use App\Fleet\CoordinationService;
use App\Fleet\RetNotificationService;
use App\Fleet\RetVehicleEligibility;
use App\Http\Controllers\Fleet\RetFeasibilityController;
use App\Models\Client;
use App\Models\ClientBranch;
use App\Models\RetFeasibility;
use App\Models\ServiceRequest;
use App\Models\ServiceType;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RetCoordinationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        config(['fleet.test_tools_enabled' => true]);
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $actor = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'role_code' => 'administrator', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($actor)->withSession(['fleet_tenant_id' => $tenant->id]);
        $client = Client::create(['tenant_id' => $tenant->id, 'tax_id' => '76543210-0', 'tax_id_number' => '76543210', 'tax_id_check_digit' => '0', 'commercial_name' => 'Cliente RET', 'legal_name' => 'Cliente RET SPA', 'is_active' => true]);
        $point = ClientBranch::create(['client_id' => $client->id, 'code' => 'RET1', 'name' => 'Punto central', 'address' => 'Calle 123', 'commune_name' => 'Santiago', 'address_status' => 'ready', 'operational_emails' => 'cliente@example.test', 'is_active' => true]);
        $type = ServiceType::create(['service_code' => 991, 'name' => 'Retiro', 'is_active' => true]);
        DB::table('client_service_type')->insert(['client_id' => $client->id, 'service_type_id' => $type->id, 'is_active' => true]);
        $driver = User::factory()->create();
        $driver->profile_name = 'App Conductor';
        $driver->license_type = 'B';
        $driver->save();
        DB::table('tenant_users')->insert(['tenant_id' => $tenant->id, 'user_id' => $driver->id, 'role_code' => 'app_conductor', 'is_active' => true]);
        $vehicle = Vehicle::create(['tenant_id' => $tenant->id, 'rut_empresa' => $tenant->tax_id, 'company_source' => $tenant->code, 'internal_code' => 'RET001', 'plate' => 'RETT01', 'max_weight_kg' => 3500, 'vehicle_type' => 'Furgón', 'ownership_type' => 'Propio', 'operational_status' => 'Activa', 'is_active' => true]);

        return compact('tenant', 'actor', 'client', 'point', 'type', 'driver', 'vehicle');
    }

    private function payload(array $c): array
    {
        return ['client_id' => $c['client']->id, 'client_branch_id' => $c['point']->id, 'service_type_id' => $c['type']->id, 'service_date' => '2026-10-05', 'shift' => 'AM', 'window_start' => '09:00', 'window_end' => '10:00', 'packages' => 5, 'material' => 'Cajas', 'pickup_name' => 'Retiro central', 'is_test' => 1, 'commune_snapshot' => 'Santiago', 'contact_name_snapshot' => 'Contacto', 'contact_phone_snapshot' => '123456', 'access_reference_snapshot' => 'Portón azul'];
    }

    private function ret(array $c, bool $test = true): ServiceRequest
    {
        return app(CoordinationService::class)->createRequest($c['actor'], $c['client'], $c['point'], $c['type']->id, array_replace($this->payload($c), ['is_test' => $test]));
    }

    private function schedule(array $c, ServiceRequest $ret): void
    {
        $this->post(route('fleet.scheduling.assign', $ret), ['driver_user_id' => $c['driver']->id, 'vehicle_id' => $c['vehicle']->id])->assertSessionHasNoErrors();
        $this->post(route('fleet.requests.schedule', $ret))->assertSessionHasNoErrors();
    }

    public function test_new_request_uses_client_services_and_keeps_snapshots_and_original_date(): void
    {
        $c = $this->context();
        $data = $this->payload($c);
        $this->post(route('fleet.requests.store'), $data)->assertSessionHasNoErrors();
        $ret = ServiceRequest::firstOrFail();
        $this->assertSame('Contacto', $ret->contact_name_snapshot);
        $this->assertTrue($ret->is_test);
        $c['point']->update(['address' => 'Otra calle']);
        $this->assertSame('Calle 123', $ret->fresh()->effective_address);
        $this->put(route('fleet.requests.update', $ret), array_replace($data, ['effective_address' => 'Calle 123', 'reason' => 'Ajuste', 'service_date' => '2026-10-06', 'requested_date_original' => '2000-01-01', 'ret_code' => 'RET-OTRO']))->assertSessionHasNoErrors();
        $this->assertSame('2026-10-05', $ret->fresh()->requested_date_original->toDateString());
        $this->assertSame('2026-10-06', $ret->fresh()->service_date->toDateString());
        $this->assertNotSame('RET-OTRO', $ret->fresh()->ret_code);
        $this->schedule($c, $ret);
        $this->put(route('fleet.requests.update', $ret), $data + ['effective_address' => 'Cambio', 'reason' => 'Sin permiso'])->assertStatus(409);
        $this->get(route('fleet.requests.show', $ret))->assertOk()->assertSee('Contacto');
    }

    public function test_services_catalog_is_filtered_and_new_request_ignores_submitted_service(): void
    {
        $c = $this->context();
        $other = ServiceType::create(['service_code' => 992, 'name' => 'No asignado', 'is_active' => true]);
        $this->getJson(route('fleet.clients.services', $c['client']->id))->assertJsonCount(1, 'services')->assertJsonPath('services.0.id', $c['type']->id);
        $this->post(route('fleet.requests.store'), array_replace($this->payload($c), ['service_type_id' => $other->id]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('service_requests', ['service_type_id' => $c['type']->id, 'pickup_kind' => 'Variable']);
    }

    public function test_feasibility_with_finance_requires_cost_validation_and_final_approval(): void
    {
        $this->workflow(true);
    }

    public function test_feasibility_without_finance_still_requires_commercial_approval(): void
    {
        $this->workflow(false);
    }

    private function workflow(bool $finance): void
    {
        $c = $this->context();
        $this->post(route('fleet.feasibility.store'), $this->payload($c) + ['requires_finance' => $finance ? 1 : 0, 'origin' => 'Calle 123', 'destination' => 'Bodega'])->assertSessionHasNoErrors();
        $f = RetFeasibility::firstOrFail();
        $this->post(route('fleet.feasibility.act', $f), ['action' => 'convert'])->assertStatus(409);
        $this->post(route('fleet.feasibility.act', $f), ['action' => 'operations', 'result' => 'Factible con condiciones', 'conditions' => 'Carga embalada', 'allowances' => 0, 'estimated_cost' => 10000, 'reason' => 'Ruta revisada'])->assertSessionHasNoErrors();
        if ($finance) {
            $this->assertSame('finance', $f->fresh()->status);
            $costs = array_fill_keys(array_keys(RetFeasibilityController::COSTS), 1000);
            $this->post(route('fleet.feasibility.act', $f), $costs + ['action' => 'finance', 'reason' => 'Estimación validada'])->assertSessionHasNoErrors();
            $this->assertSame('cost_validation', $f->fresh()->status);
            $this->post(route('fleet.feasibility.act', $f), ['action' => 'validate', 'reason' => 'Costos revisados'])->assertSessionHasNoErrors();
        }
        $this->assertSame('commercial', $f->fresh()->status);
        $this->post(route('fleet.feasibility.act', $f), ['action' => 'commercial', 'proposed_price' => 15000, 'reason' => 'Oferta'])->assertSessionHasNoErrors();
        $this->assertSame('approval', $f->fresh()->status);
        $this->assertDatabaseCount('service_requests', 0);
        $this->post(route('fleet.feasibility.act', $f), ['action' => 'approve', 'proposed_price' => 16000, 'reason' => 'Ajuste aprobado'])->assertSessionHasNoErrors();
        $this->post(route('fleet.feasibility.act', $f), $this->payload($c) + ['action' => 'convert', 'reason' => 'Cliente confirma'])->assertSessionHasNoErrors();
        $this->assertSame('converted', $f->fresh()->status);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertTrue(ServiceRequest::firstOrFail()->is_test);
        $this->post(route('fleet.feasibility.act', $f), $this->payload($c) + ['action' => 'convert', 'reason' => 'Otra vez'])->assertForbidden();
        $this->assertDatabaseCount('service_requests', 1);
        $this->get(route('fleet.feasibility.show', $f))->assertOk()->assertSee('Carga embalada');
    }

    public function test_correction_preserves_original_estimate_and_requires_a_new_review(): void
    {
        $c = $this->context();
        $this->post(route('fleet.feasibility.store'), $this->payload($c) + ['requires_finance' => 0])->assertSessionHasNoErrors();
        $f = RetFeasibility::firstOrFail();
        $this->post(route('fleet.feasibility.act', $f), ['action' => 'correction', 'reason' => 'Precisar carga'])->assertSessionHasNoErrors();
        $this->post(route('fleet.feasibility.act', $f), array_replace($this->payload($c), ['action' => 'resubmit', 'requires_finance' => 1, 'material' => 'Pallets', 'reason' => 'Cliente aclaró']))->assertSessionHasNoErrors();
        $this->assertSame('Cajas', $f->fresh()->original_request_data['material']);
        $this->assertSame('Pallets', $f->fresh()->request_data['material']);
        $this->assertSame('operations', $f->fresh()->status);
    }

    public function test_recognition_does_not_guess_ambiguous_dates_or_units(): void
    {
        $this->context();
        $this->postJson(route('fleet.feasibility.recognize'), ['text' => "Origen: Calle 123\nBultos: 8\nPeso: 25 kg\nFecha: mañana\nDestino: A\nDestino: B"])->assertOk()->assertJsonPath('values.packages', '8')->assertJsonPath('values.weight_kg', '25')->assertJsonMissingPath('values.service_date')->assertJsonMissingPath('values.destination');
    }

    public function test_mail_has_postventa_cc_is_idempotent_and_driver_token_is_single_use(): void
    {
        $c = $this->context();
        $this->put(route('fleet.ret.settings.save'), ['sender' => 'retiros@example.test', 'postventa_cc' => 'postventa@example.test'])->assertSessionHasNoErrors();
        $ret = $this->ret($c);
        $messages = [];
        Event::listen(MessageSending::class, function (MessageSending $event) use (&$messages): void {
            $messages[] = $event->message;
        });
        $this->schedule($c, $ret);
        app(RetNotificationService::class)->prepare($ret->fresh());
        $this->assertDatabaseCount('ret_notifications', 1);
        $mail = DB::table('ret_notifications')->first();
        $this->assertSame('postventa@example.test', $mail->cc);
        $this->assertStringContainsString('10 minutos', $mail->body);
        $this->assertStringContainsString('Documentación y trazabilidad', $mail->body);
        $this->assertStringContainsString('**Hola, Cliente RET 👋**', $mail->body);
        $this->assertStringContainsString('**Fecha:** lunes, 05 de octubre de 2026', $mail->body);
        $this->assertStringContainsString('**Dirección:** Punto central, Calle 123, Santiago', $mail->body);
        $this->assertStringContainsString('**Jornada/horario:** 09:00 a 10:00', $mail->body);
        $this->assertStringContainsString('**5 bultos de Cajas**', $mail->body);
        app(RetNotificationService::class)->capture($mail->id);
        app(RetNotificationService::class)->capture($mail->id);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('<strong>Hola, Cliente RET 👋</strong>', $messages[0]->getHtmlBody());
        $this->assertStringNotContainsString('Registro operativo interno', $messages[0]->getHtmlBody());
        $this->assertStringNotContainsString('/retiro-conductor/', $messages[0]->getHtmlBody());
        $this->assertSame('captured', DB::table('ret_notifications')->value('status'));
        $this->assertSame(1, DB::table('ret_notifications')->value('attempts'));
        preg_match('~/retiro-conductor/([a-f0-9]{64})~', $mail->body, $matches);
        $token = $matches[1];
        Auth::logout();
        $this->get(route('fleet.driver.show', $token))->assertOk();
        $this->post(route('fleet.driver.store', $token), ['guide_number' => 'GUIA-1', 'actual_packages' => 7])->assertRedirect(route('fleet.driver.done'));
        $this->assertSame('retired', $ret->fresh()->status);
        $this->assertSame(7, $ret->fresh()->actual_packages);
        $this->get(route('fleet.driver.show', $token))->assertNotFound();
    }

    public function test_cancellation_keeps_history_and_revokes_driver_link(): void
    {
        $c = $this->context();
        $ret = $this->ret($c);
        $this->schedule($c, $ret);
        $this->post(route('fleet.requests.cancel', $ret), [])->assertSessionHasErrors('reason');
        $this->post(route('fleet.requests.cancel', $ret), ['reason' => 'Cliente cancela'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $ret->fresh()->status);
        $this->assertNull($ret->fresh()->driver_token_hash);
        $this->assertDatabaseHas('service_request_events', ['service_request_id' => $ret->id, 'action' => 'cancelled']);
        $this->get(route('fleet.page.coordination-requests', ['estado' => 'cancelled']))->assertOk()->assertSee('Anulado');
    }

    public function test_cleanup_only_deletes_marked_records_and_preserves_masters_and_real_requests(): void
    {
        $c = $this->context();
        $real = $this->ret($c, false);
        $test = $this->ret($c, true);
        $this->get(route('fleet.ret.cleanup'))->assertOk()->assertSee('LIMPIAR DATOS DE PRUEBA');
        $this->post(route('fleet.ret.cleanup.destroy'), ['confirmation' => 'limpiar'])->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('service_requests', 2);
        $this->post(route('fleet.ret.cleanup.destroy'), ['confirmation' => 'LIMPIAR DATOS DE PRUEBA'])->assertSessionHasNoErrors();
        $this->assertModelExists($real);
        $this->assertModelMissing($test);
        $this->assertModelExists($c['client']);
        $this->assertModelExists($c['point']);
        $this->assertModelExists($c['driver']);
        $this->assertModelExists($c['vehicle']);
        $this->assertDatabaseCount('ret_cleanup_audits', 1);
        config(['fleet.test_tools_enabled' => false]);
        $this->get(route('fleet.ret.cleanup'))->assertNotFound();
    }

    public function test_permission_and_tenant_boundaries_protect_shared_feasibility_and_cleanup(): void
    {
        $c = $this->context();
        $this->post(route('fleet.feasibility.store'), $this->payload($c) + ['requires_finance' => 0])->assertSessionHasNoErrors();
        $f = RetFeasibility::firstOrFail();
        $other = Tenant::where('code', 'PMCB')->firstOrFail();
        DB::table('tenant_users')->insert(['tenant_id' => $other->id, 'user_id' => $c['actor']->id, 'role_code' => 'administrator', 'is_active' => true]);
        $this->withSession(['fleet_tenant_id' => $other->id])->get(route('fleet.feasibility.show', $f))->assertNotFound();
        $this->withSession(['fleet_tenant_id' => $c['tenant']->id]);
        DB::table('tenant_users')->where('user_id', $c['actor']->id)->where('tenant_id', $c['tenant']->id)->update(['role_code' => 'read_only']);
        $this->get(route('fleet.ret.cleanup'))->assertForbidden();
        $this->post(route('fleet.feasibility.act', $f), ['action' => 'operations', 'result' => 'Factible', 'allowances' => 0])->assertForbidden();
        $this->post(route('fleet.feasibility.store'), $this->payload($c) + ['requires_finance' => 0])->assertForbidden();
    }

    public function test_all_new_screens_render_with_existing_identity(): void
    {
        $c = $this->context();
        foreach (['fleet.page.coordination-feasibility', 'fleet.page.commercial-feasibility', 'fleet.page.finance-feasibility', 'fleet.page.operations-feasibility', 'fleet.ret.settings', 'fleet.requests.create', 'fleet.page.coordination-fixed-pickups'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_licence_and_payload_are_validated_independently_of_gross_weight(): void
    {
        $c = $this->context();
        $ret = $this->ret($c);
        $c['vehicle']->update(['max_weight_kg' => 6000, 'capacity_m3' => 10, 'payload_capacity_kg' => 2000]);
        $url = route('fleet.scheduling.assign', $ret);
        $assignment = ['driver_user_id' => $c['driver']->id, 'vehicle_id' => $c['vehicle']->id];
        $this->post($url, $assignment)->assertSessionHasErrors('vehicle_id');
        $c['driver']->license_type = 'A4';
        $c['driver']->save();
        $this->post($url, $assignment)->assertSessionHasNoErrors();
        $ret->update(['requested_weight_kg' => 2500]);
        $this->post(route('fleet.requests.schedule', $ret))->assertSessionHasErrors('vehicle_id');
        $ret->update(['requested_weight_kg' => 1500, 'requested_volume_m3' => 12]);
        $this->post(route('fleet.requests.schedule', $ret))->assertSessionHasErrors('vehicle_id');
        $ret->update(['requested_volume_m3' => 8]);
        $this->post(route('fleet.requests.schedule', $ret))->assertSessionHasNoErrors();
        $c['vehicle']->update(['vehicle_type' => 'Motocicleta']);
        $this->assertFalse(app(RetVehicleEligibility::class)->compatible($c['driver'], $c['vehicle']));
        $c['vehicle']->update(['vehicle_type' => 'Camioneta', 'max_weight_kg' => 850000]);
        $this->assertNull(app(RetVehicleEligibility::class)->required($c['vehicle']));
    }

    public function test_point_cc_overrides_default_and_returning_to_assignment_issues_a_fresh_link(): void
    {
        $c = $this->context();
        $this->assertSame('Hansdelabarra@4nlogistica.cl', DB::table('ret_settings')->where('tenant_id', $c['tenant']->id)->value('postventa_cc'));
        $c['point']->update(['postventa_cc' => 'equipo@example.test']);
        $ret = $this->ret($c);
        $this->schedule($c, $ret);
        $originalHash = $ret->fresh()->driver_token_hash;
        $this->assertSame('equipo@example.test', DB::table('ret_notifications')->value('cc'));
        $ret->refresh()->update(['packages' => 8]);
        app(RetNotificationService::class)->prepare($ret->fresh());
        $ret->update(['packages' => 5]);
        app(RetNotificationService::class)->prepare($ret->fresh());
        app(RetNotificationService::class)->prepare($ret->fresh());
        $this->assertDatabaseCount('ret_notifications', 3);
        $this->assertNotSame($originalHash, $ret->fresh()->driver_token_hash);
    }

    public function test_customer_email_html_preserves_details_escapes_user_data_and_excludes_internal_link(): void
    {
        $c = $this->context();
        $ret = $this->ret($c);
        $ret->update(['effective_address' => '<script>alert(1)</script>', 'status' => 'scheduled']);
        $body = app(CoordinationService::class)->confirmationPreview($ret, 'Daniel Echague Pardo', 'PLRL48')['body'];
        $html = app(RetNotificationService::class)->emailHtml($body."\n\nRegistro operativo interno:\nRegistro del conductor: http://localhost/private-token");
        $this->assertStringContainsString($ret->ret_code, $html);
        $this->assertStringContainsString('Daniel Echague Pardo', $html);
        $this->assertStringContainsString('PLRL48', $html);
        $this->assertStringContainsString('dentro de los próximos 20 minutos', $html);
        $this->assertStringContainsString('máximo de 10 minutos', $html);
        $this->assertStringContainsString('embalaje insuficiente o inadecuado', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('private-token', $html);
        $this->assertStringNotContainsString('Registro operativo interno', $html);
    }
}
