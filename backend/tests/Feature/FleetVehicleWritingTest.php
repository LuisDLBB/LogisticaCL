<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FleetVehicleWritingTest extends TestCase
{
    use RefreshDatabase;

    public function test_proposal_is_returned_without_changing_vehicle_or_history(): void
    {
        $vehicle = $this->workspace();
        config(['services.openai.key' => 'test-key', 'services.openai.model' => 'test-model']);
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'En taller por falla de embrague. Fecha de entrega pendiente.']]]]])]);
        $this->postJson(route('fleet.vehicles.writing', $vehicle), $this->payload())->assertOk()->assertJsonPath('proposal', 'En taller por falla de embrague. Fecha de entrega pendiente.');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/responses' && $request['store'] === false && json_decode($request['input'], true) === $this->payload() && ! str_contains($request['input'], $vehicle->plate));
        $this->assertSame('Activa', $vehicle->fresh()->operational_status);
        $this->assertNull($vehicle->fresh()->availability_reason);
        $this->assertDatabaseCount('VEH_vehicle_status_events', 0);
    }

    public function test_unconfigured_assistant_does_not_call_external_service(): void
    {
        $vehicle = $this->workspace();
        config(['services.openai.key' => null, 'services.openai.model' => null]);
        Http::preventStrayRequests();
        Http::fake();
        $this->postJson(route('fleet.vehicles.writing', $vehicle), $this->payload())->assertStatus(503)->assertJsonPath('message', 'El asistente está pendiente de conexión. Puedes continuar con tu texto original.');
        Http::assertNothingSent();
        $this->get(route('fleet.page.operations-fleet'))->assertOk()->assertDontSee('Mejorar redacción')->assertDontSee('Asistente pendiente de conexión');
    }

    public function test_invalid_input_does_not_call_service(): void
    {
        $vehicle = $this->workspace();
        Http::preventStrayRequests();
        Http::fake();
        $this->postJson(route('fleet.vehicles.writing', $vehicle), ['reason' => '', 'target_state' => 'inventado', 'reason_code' => 'inventado'])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'target_state', 'reason_code']);
        Http::assertNothingSent();
    }

    public function test_foreign_vehicle_and_read_only_user_are_denied(): void
    {
        $vehicle = $this->workspace();
        Http::preventStrayRequests();
        Http::fake();
        $foreign = Tenant::where('code', 'PMCB')->firstOrFail();
        $vehicle->update(['rut_empresa' => $foreign->tax_id, 'company_source' => $foreign->code]);
        $this->postJson(route('fleet.vehicles.writing', $vehicle), $this->payload())->assertForbidden();
        $vehicle = $this->workspace('commercial');
        $this->postJson(route('fleet.vehicles.writing', $vehicle), $this->payload())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_provider_error_or_empty_response_is_reported_without_saving(): void
    {
        $vehicle = $this->workspace();
        config(['services.openai.key' => 'test-key', 'services.openai.model' => 'test-model']);
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()->push(['error' => 'private upstream details'], 500)->push(['status' => 'completed', 'output' => []])->push(['status' => 'incomplete', 'output' => []])]);
        foreach (range(1, 3) as $attempt) {
            $this->postJson(route('fleet.vehicles.writing', $vehicle), $this->payload())->assertStatus(502)->assertDontSee('private upstream details');
        }
        Http::assertSentCount(3);
        $this->assertSame('Activa', $vehicle->fresh()->operational_status);
        $this->assertDatabaseCount('VEH_vehicle_status_events', 0);
    }

    public function test_guest_cannot_request_proposal(): void
    {
        $vehicle = $this->workspace();
        auth()->logout();
        Http::preventStrayRequests();
        Http::fake();
        $this->postJson(route('fleet.vehicles.writing', $vehicle), $this->payload())->assertUnauthorized();
        Http::assertNothingSent();
    }

    private function payload(): array
    {
        return ['reason' => 'La camioneta tiene problemas de embrague. Está en taller y no sabemos cuándo sale.', 'target_state' => 'unavailable', 'reason_code' => 'repair'];
    }

    private function workspace(string $role = 'administrator'): Vehicle
    {
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $user = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $role, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->withSession(['fleet_tenant_id' => $tenant->id]);

        return Vehicle::create(['tenant_id' => $tenant->id, 'rut_empresa' => $tenant->tax_id, 'company_source' => $tenant->code, 'internal_code' => 'WRITE'.$user->id, 'plate' => 'WR'.$user->id, 'vehicle_type' => 'Camioneta', 'ownership_type' => 'Propio', 'operational_status' => 'Activa', 'is_active' => true]);
    }
}
