<?php

namespace Tests\Feature;

use App\Fleet\VehicleDocumentImporter;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class FleetVehicleDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_import_is_idempotent_preserves_pdf_and_specific_deductibles_and_separates_soap_from_vehicle_insurance(): void
    {
        [$tenant, $user] = $this->workspace();
        $first = $this->vehicle($tenant, 'DOCONE');
        $second = $this->vehicle($tenant, 'DOCTWO');
        $records = [
            $this->document('damage', 'vehicle_policy', ['DOCONE' => ['Deducible según ítem' => 'UF 5', 'page' => 2], 'DOCTWO' => ['Deducible según ítem' => 'UF 50']], '2027-08-29'),
            $this->document('soap', 'soap', ['DOCONE' => []], '2027-09-30'),
            $this->document('permit', 'circulation_permit', ['DOCONE' => []], '2027-03-31'),
            $this->document('payment', 'payment_plan', ['DOCONE' => []], '2027-08-29'),
            $this->document('company', 'company_policy', [], '2027-09-06'),
        ];
        app(VehicleDocumentImporter::class)->import($records, $user);
        app(VehicleDocumentImporter::class)->import($records, $user);
        $this->assertDatabaseCount('VEH_documents', 5);
        $this->assertDatabaseCount('VEH_document_vehicles', 5);
        $this->assertSame('2027-08-29', $first->fresh()->insurance_expires_at->toDateString());
        $this->assertSame('2027-03-31', $first->fresh()->circulation_permit_expires_at->toDateString());
        $response = $this->get(route('fleet.vehicles.show', $first));
        $response->assertOk()->assertSee('UF 5')->assertDontSee('UF 50')->assertSee('Abrir PDF')->assertSee('SOAP')->assertSee('Empresa, sin patente individual');
        $this->get(route('fleet.vehicles.show', $second))->assertOk()->assertSee('UF 50')->assertDontSee('UF 5<', false);
        $record = DB::table('VEH_documents')->where('sha256', $records[0]['sha256'])->first();
        $pdf = $this->get(route('fleet.vehicles.documents.show', ['vehicle' => $first, 'document' => $record->id]));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($records[0]['sha256'], hash_file('sha256', $pdf->baseResponse->getFile()->getPathname()));
        $this->assertSame('Activa', $first->fresh()->operational_status);
    }

    public function test_unassociated_and_foreign_documents_and_invalid_paths_are_not_accessible(): void
    {
        [$tenant, $user] = $this->workspace();
        $first = $this->vehicle($tenant, 'DOCONE');
        $second = $this->vehicle($tenant, 'DOCTWO');
        $record = $this->document('one', 'soap', ['DOCONE' => []], '2027-09-30');
        app(VehicleDocumentImporter::class)->import([$record], $user);
        $id = DB::table('VEH_documents')->value('id');
        $this->get(route('fleet.vehicles.documents.show', ['vehicle' => $second, 'document' => $id]))->assertNotFound();
        $foreign = $this->vehicle(Tenant::where('code', 'PMCB')->firstOrFail(), 'FOREIGNDOC');
        $foreignUser = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $tenant->id, 'user_id' => $foreignUser->id, 'role_code' => 'read_only', 'is_active' => true]);
        $this->actingAs($foreignUser);
        $this->get(route('fleet.vehicles.documents.show', ['vehicle' => $foreign, 'document' => $id]))->assertNotFound();
        $this->actingAs($user);
        DB::table('VEH_documents')->where('id', $id)->update(['storage_path' => '../../secret.pdf']);
        $this->get(route('fleet.vehicles.documents.show', ['vehicle' => $first, 'document' => $id]))->assertNotFound();
    }

    public function test_missing_pdf_or_unknown_plate_rolls_back_the_import(): void
    {
        [$tenant, $user] = $this->workspace();
        $this->vehicle($tenant, 'DOCONE');
        $good = $this->document('good', 'soap', ['DOCONE' => []], '2027-09-30');
        $bad = $this->document('bad', 'soap', ['MISSING' => []], '2027-09-30');
        try {
            app(VehicleDocumentImporter::class)->import([$good, $bad], $user);
            $this->fail('Unknown plate must prevent import.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Patente inexistente', $error->getMessage());
        }
        $this->assertDatabaseCount('VEH_documents', 0);
        $this->assertDatabaseCount('VEH_document_vehicles', 0);
        Storage::disk('local')->delete($good['storage_path']);
        $this->expectException(RuntimeException::class);
        app(VehicleDocumentImporter::class)->import([$good], $user);
    }

    private function workspace(): array
    {
        $this->travelTo(Carbon::parse('2026-10-09', 'America/Santiago'));
        Storage::fake('local');
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $user = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => 'administrator', 'is_active' => true]);
        $this->actingAs($user)->withSession(['fleet_tenant_id' => $tenant->id]);

        return [$tenant, $user];
    }

    private function vehicle(Tenant $tenant, string $plate): Vehicle
    {
        $id = DB::table('vehicles')->insertGetId(['tenant_id' => $tenant->id, 'rut_empresa' => $tenant->tax_id, 'company_source' => $tenant->code, 'plate' => $plate, 'internal_code' => $plate, 'vehicle_type' => 'Camioneta', 'ownership_type' => 'Propio', 'operational_status' => 'Activa', 'is_active' => true]);

        return Vehicle::findOrFail($id);
    }

    private function document(string $key, string $kind, array $vehicles, string $expires): array
    {
        $pdf = '%PDF-1.4 '.str_repeat($key, 20);
        $hash = hash('sha256', $pdf);
        $path = 'fleet/documents/'.$hash.'.pdf';
        Storage::disk('local')->put($path, $pdf);

        return ['sha256' => $hash, 'storage_path' => $path, 'filename' => $key.'.pdf', 'kind' => $kind, 'title' => $kind === 'soap' ? 'SOAP' : $key, 'policy_number' => 'TEST-'.$key, 'insurer' => 'Aseguradora de prueba', 'tenant_code' => '4N', 'starts_at' => '2026-08-29', 'expires_at' => $expires, 'details' => [], 'vehicles' => $vehicles];
    }
}
