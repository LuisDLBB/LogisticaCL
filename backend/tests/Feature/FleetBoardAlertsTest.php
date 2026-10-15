<?php

namespace Tests\Feature;

use App\Fleet\VehicleBoardAlerts;
use App\Mail\VehiclePaperworkAlert;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FleetBoardAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_upcoming_export_includes_today_and_seventh_day_without_changing_vehicle_state(): void
    {
        [$tenant, $user, $vehicle] = $this->context();
        $vehicle->forceFill(['operational_status' => 'En mantención', 'availability_reason' => 'Trabajo real', 'technical_inspection_expires_at' => '2026-10-17', 'gas_certificate_expires_at' => '2026-10-10', 'circulation_permit_expires_at' => '2026-10-18'])->save();
        $document = DB::table('VEH_documents')->insertGetId(['tenant_id' => $tenant->id, 'sha256' => str_repeat('b', 64), 'kind' => 'inspection_bundle', 'title' => 'Certificados', 'filename' => 'certificados.pdf', 'storage_path' => 'fleet/certificados.pdf', 'details' => '{}']);
        DB::table('VEH_document_vehicles')->insert(['document_id' => $document, 'vehicle_id' => $vehicle->id, 'details' => json_encode(['verified_expirations' => ['technical_inspection_expires_at' => ['date' => '2026-10-17'], 'gas_certificate_expires_at' => ['date' => '2026-10-10']]])]);
        $before = $vehicle->fresh()->getAttributes();
        $response = $this->get(route('fleet.vehicles.upcoming-documents.export'));
        $response->assertOk()->assertDownload('documentos-proximos-a-vencer-2026-10-10-al-2026-10-17.xlsx');
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
        $this->assertCount(3, $xml->sheetData->row, $zip->getFromName('xl/worksheets/sheet1.xml'));
        $this->assertSame('Revisión técnica', (string) $xml->sheetData->row[1]->c[1]->is->t);
        $this->assertSame('Certificado de gases', (string) $xml->sheetData->row[2]->c[1]->is->t);
        $this->assertStringNotContainsString('Permiso de circulación', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        unlink($path);
        $this->assertSame($before, $vehicle->fresh()->getAttributes());
        $this->get(route('fleet.page.operations-fleet'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->firstWhere('vehicle.id', $vehicle->id)['display_state'] === 'unavailable');
        $vehicle->forceFill(['technical_inspection_expires_at' => '2026-10-09', 'gas_certificate_expires_at' => '2026-10-09', 'circulation_permit_expires_at' => '2026-10-09'])->save();
        $empty = $this->get(route('fleet.vehicles.upcoming-documents.export'))->assertOk();
        $path = $empty->baseResponse->getFile()->getPathname();
        $zip->open($path);
        $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
        $this->assertCount(1, $xml->sheetData->row);
        $zip->close();
        unlink($path);
    }

    public function test_upcoming_export_requires_fleet_permission(): void
    {
        $this->context('driver');
        $this->get(route('fleet.vehicles.upcoming-documents.export'))->assertForbidden();
    }

    private function context(string $role = 'administrator'): array
    {
        $this->travelTo(Carbon::parse('2026-10-10 12:00:00', 'America/Santiago'));
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $user = User::factory()->create();
        FleetTestMembership::insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $role, 'is_active' => true]);
        $this->actingAs($user)->withSession(['fleet_tenant_id' => $tenant->id]);
        $id = DB::table('MBA_vehicles')->insertGetId(['tenant_id' => $tenant->id, 'rut_empresa' => $tenant->tax_id, 'company_source' => $tenant->code, 'plate' => 'TEST31', 'internal_code' => 'TEST31', 'vehicle_type' => 'Camioneta', 'ownership_type' => 'Propio', 'operational_status' => 'Activa', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return [$tenant, $user, Vehicle::findOrFail($id)];
    }

    public function test_future_complete_schedule_returns_available_and_due_week_returns_warning_without_manual_override(): void
    {
        [$tenant,$user,$vehicle] = $this->context();
        $vehicle->update(['next_maintenance_at' => '2026-10-10']);
        $job = VehicleMaintenance::create(['tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'maintenance_type' => 'Preventiva', 'execution_type' => 'internal', 'status' => 'scheduled', 'scheduled_at' => '2026-10-16 10:00:00', 'responsible_user_id' => $user->id, 'created_by_user_id' => $user->id]);
        $this->get(route('fleet.page.operations-fleet'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->firstWhere('vehicle.id', $vehicle->id)['display_state'] === 'available');
        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'America/Santiago'));
        $this->get(route('fleet.page.operations-fleet'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->firstWhere('vehicle.id', $vehicle->id)['display_state'] === 'warning');
        $this->patch(route('fleet.vehicles.status', $vehicle), ['target_state' => 'available', 'reason' => 'Habilitar', 'expected_status' => 'Activa', 'expected_revision' => $vehicle->updated_at->toISOString()])->assertSessionHasErrors('vehicle_state');
        $job->update(['status' => 'in_progress']);
        $this->get(route('fleet.page.operations-fleet'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->firstWhere('vehicle.id', $vehicle->id)['unavailability_category'] === 'En mantención');
    }

    public function test_incomplete_future_maintenance_does_not_clear_km_alert_and_cancelled_job_does_not_resolve_it(): void
    {
        [$tenant,$user,$vehicle] = $this->context();
        $job = VehicleMaintenance::create(['tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'maintenance_type' => 'Preventiva', 'execution_type' => 'external', 'status' => 'scheduled', 'scheduled_at' => '2026-10-20', 'created_by_user_id' => $user->id]);
        $this->get(route('fleet.page.operations-fleet'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->firstWhere('vehicle.id', $vehicle->id)['display_state'] === 'warning');
        $km = ['in_workshop' => false, 'maintenance_needed' => true, 'remaining' => 1000];
        $job->update(['status' => 'cancelled']);
        $this->assertTrue(app(VehicleBoardAlerts::class)->maintenanceRequired($vehicle, collect([$job]), $km));
    }

    public function test_expired_papers_have_their_column_block_availability_and_renewal_restores_it(): void
    {
        [$tenant,$user,$vehicle] = $this->context();
        $vehicle->update(['circulation_permit_expires_at' => '2026-10-09']);
        $this->get(route('fleet.page.operations-fleet'))->assertOk()->assertSee('Papeles vencidos')->assertSee('Permiso de circulación · 09/10/2026')->assertViewHas('rows', fn ($rows) => $rows->firstWhere('vehicle.id', $vehicle->id)['display_state'] === 'papers');
        $this->patch(route('fleet.vehicles.status', $vehicle), ['target_state' => 'available', 'reason' => 'Habilitar', 'expected_status' => 'Activa', 'expected_revision' => $vehicle->updated_at->toISOString()])->assertSessionHasErrors('vehicle_state');
        $vehicle->update(['circulation_permit_expires_at' => '2027-10-09']);
        $this->get(route('fleet.page.operations-fleet'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->firstWhere('vehicle.id', $vehicle->id)['display_state'] === 'available');
    }

    public function test_unverified_certificate_does_not_make_a_false_expired_alert_and_expiry_day_is_valid(): void
    {
        [$tenant,$user,$vehicle] = $this->context();
        $vehicle->update(['technical_inspection_expires_at' => '2026-01-01', 'circulation_permit_expires_at' => '2026-10-10']);
        $this->get(route('fleet.page.operations-fleet'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->firstWhere('vehicle.id', $vehicle->id)['display_state'] === 'available');
        $items = app(VehicleBoardAlerts::class)->paperwork($vehicle);
        $this->assertCount(1, $items);
        $this->assertFalse($items[0]['expired']);
    }

    public function test_document_settings_validate_recipients_and_are_scoped_to_active_company(): void
    {
        [$tenant] = $this->context();
        $this->put(route('fleet.documents.settings.update'), ['enabled' => 1, 'warning_days' => 7, 'emails' => ''])->assertSessionHasErrors('recipients');
        $this->put(route('fleet.documents.settings.update'), ['enabled' => 1, 'warning_days' => 7, 'emails' => 'no-es-correo'])->assertSessionHasErrors('recipients.0');
        $this->put(route('fleet.documents.settings.update'), ['enabled' => 1, 'warning_days' => 7, 'emails' => ' Ops@example.test;ops@example.test supervisor@example.test'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('VEH_document_alert_settings', ['tenant_id' => $tenant->id, 'warning_days' => 7, 'enabled' => 1, 'recipients' => '["ops@example.test","supervisor@example.test"]']);
        $this->assertDatabaseCount('VEH_document_alert_settings', 1);
        $this->get(route('fleet.maintenance.settings'))->assertOk()->assertSee('ops@example.test');
    }

    public function test_read_only_user_cannot_change_email_settings(): void
    {
        $this->context('read_only');
        $this->put(route('fleet.documents.settings.update'), ['enabled' => 1, 'warning_days' => 7, 'emails' => 'ops@example.test'])->assertForbidden();
        $this->assertDatabaseCount('VEH_document_alert_settings', 0);
    }

    public function test_automatic_mail_is_once_per_expiry_recipient_excludes_archived_and_other_company_and_new_expiry_generates_a_new_notice(): void
    {
        [$tenant,$user,$vehicle] = $this->context();
        Mail::fake();
        DB::table('VEH_document_alert_settings')->insert(['tenant_id' => $tenant->id, 'enabled' => true, 'warning_days' => 7, 'recipients' => '["ops@example.test"]', 'created_at' => now(), 'updated_at' => now()]);
        $vehicle->update(['circulation_permit_expires_at' => '2026-10-17']);
        $sold = $vehicle->replicate();
        $sold->plate = 'SOLD31';
        $sold->internal_code = 'SOLD31';
        $sold->operational_status = 'Vendido';
        $sold->save();
        $other = $vehicle->replicate();
        $other->plate = 'OTRA31';
        $other->internal_code = 'OTRA31';
        $other->rut_empresa = Tenant::where('code', 'PMCB')->value('tax_id');
        $other->company_source = 'PMCB';
        $other->save();
        $this->artisan('fleet:document-alerts')->assertSuccessful();
        $this->artisan('fleet:document-alerts')->assertSuccessful();
        Mail::assertSent(VehiclePaperworkAlert::class, fn ($mail) => $mail->plate === 'TEST31' && $mail->hasTo('ops@example.test'));
        Mail::assertSentCount(1);
        $this->assertDatabaseCount('VEH_document_alert_deliveries', 1);
        $vehicle->update(['circulation_permit_expires_at' => '2026-10-18']);
        $this->artisan('fleet:document-alerts')->assertSuccessful();
        Mail::assertSentCount(1);
        $this->travelTo(Carbon::parse('2026-10-11', 'America/Santiago'));
        $this->artisan('fleet:document-alerts')->assertSuccessful();
        Mail::assertSentCount(2);
    }

    public function test_verified_inspection_and_gas_show_each_expired_paper_in_floating_detail_and_insurance_is_excluded(): void
    {
        [$tenant,$user,$vehicle] = $this->context();
        $vehicle->forceFill(['technical_inspection_expires_at' => '2026-10-08', 'gas_certificate_expires_at' => '2026-10-09', 'circulation_permit_expires_at' => '2026-10-07', 'insurance_expires_at' => '2026-01-01'])->save();
        $document = DB::table('VEH_documents')->insertGetId(['tenant_id' => $tenant->id, 'sha256' => str_repeat('a', 64), 'kind' => 'inspection_bundle', 'title' => 'Certificados', 'filename' => 'certificados.pdf', 'storage_path' => 'fleet/certificados.pdf', 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('VEH_document_vehicles')->insert(['document_id' => $document, 'vehicle_id' => $vehicle->id, 'details' => json_encode(['verified_expirations' => ['technical_inspection_expires_at' => ['date' => '2026-10-08'], 'gas_certificate_expires_at' => ['date' => '2026-10-09']]]), 'created_at' => now(), 'updated_at' => now()]);
        $this->get(route('fleet.page.operations-fleet'))->assertOk()->assertSee('id="fleet-papers-dialog"', false)->assertSee('TEST31')->assertSee('Revisión técnica')->assertSee('Certificado de gases')->assertSee('Permiso de circulación')->assertViewHas('rows', fn ($rows) => count($rows->firstWhere('vehicle.id', $vehicle->id)['paperwork_alerts']) === 3);
        $this->get(route('fleet.monitor.index'))->assertOk()->assertViewHas('fleetSummary', fn ($summary) => $summary['papers'] === 1);
    }

    public function test_mail_failure_is_retryable_and_disabled_settings_do_not_send(): void
    {
        [$tenant,$user,$vehicle] = $this->context();
        $vehicle->update(['circulation_permit_expires_at' => '2026-10-17']);
        DB::table('VEH_document_alert_settings')->insert(['tenant_id' => $tenant->id, 'enabled' => false, 'warning_days' => 7, 'recipients' => '["ops@example.test"]', 'created_at' => now(), 'updated_at' => now()]);
        $mailManager = app('mail.manager');
        Mail::fake();
        $this->artisan('fleet:document-alerts')->assertSuccessful();
        Mail::assertNothingSent();
        DB::table('VEH_document_alert_settings')->where('tenant_id', $tenant->id)->update(['enabled' => true]);
        Mail::shouldReceive('to')->once()->with('ops@example.test')->andThrow(new \RuntimeException('SMTP no disponible'));
        $this->artisan('fleet:document-alerts')->assertExitCode(1);
        $this->assertDatabaseCount('VEH_document_alert_deliveries', 0);
        Mail::swap($mailManager);
        Mail::fake();
        $this->artisan('fleet:document-alerts')->assertSuccessful();
        Mail::assertSentCount(1);
    }

    public function test_mail_content_contains_plate_document_due_date_and_fiche_link(): void
    {
        $mail = new VehiclePaperworkAlert('TEST31', '4N', ['label' => 'Permiso de circulación', 'display' => '17/10/2026', 'expired' => false], 'https://example.test/ficha');
        $mail->assertSeeInHtml('TEST31')->assertSeeInHtml('Permiso de circulación')->assertSeeInHtml('17/10/2026')->assertSeeInHtml('https://example.test/ficha');
    }

    public function test_excel_export_contains_only_verified_expired_documents_and_limits_vehicle_selection(): void
    {
        [$tenant, $user, $vehicle] = $this->context();
        $vehicle->update(['circulation_permit_expires_at' => '2026-10-09', 'technical_inspection_expires_at' => '2026-01-01', 'insurance_expires_at' => '2026-01-01']);

        $response = $this->get(route('fleet.vehicles.expired-documents.export', ['vehicle' => $vehicle->id]));

        $response->assertOk()->assertDownload('documentos-vencidos-2026-10-10.xlsx');
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
        $rows = $xml->sheetData->row;
        $this->assertCount(2, $rows);
        $this->assertSame('TEST31', (string) $rows[1]->c[0]->is->t);
        $this->assertSame('Permiso de circulación', (string) $rows[1]->c[1]->is->t);
        $this->assertSame('46304', (string) $rows[1]->c[2]->v);
        $zip->close();
        unlink($path);
        $this->get(route('fleet.vehicles.expired-documents.export', ['vehicle' => 999999]))->assertNotFound();
        $this->get(route('fleet.vehicles.expired-documents.export', ['vehicle' => 'invalid']))->assertSessionHasErrors('vehicle');
    }
}
