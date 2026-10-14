<?php

namespace Tests\Feature;

use App\Fleet\VehicleCertificateParser;
use App\Fleet\VehicleCertificateReader;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FleetVehicleCertificateUploadTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(string $role = 'administrator'): Vehicle
    {
        $this->travelTo(Carbon::parse('2026-10-10', 'America/Santiago'));
        Storage::fake('local');
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $user = User::factory()->create();
        DB::table('tenant_users')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $role, 'is_active' => true]);
        $this->actingAs($user)->withSession(['fleet_tenant_id' => $tenant->id]);
        $id = DB::table('vehicles')->insertGetId(['tenant_id' => $tenant->id, 'rut_empresa' => $tenant->tax_id, 'company_source' => $tenant->code, 'plate' => 'TEST31', 'internal_code' => 'TEST31', 'vehicle_type' => 'Camioneta', 'ownership_type' => 'Propio', 'operational_status' => 'Activa', 'odometer_km' => 150000, 'is_active' => true, 'technical_inspection_expires_at' => '2026-04-30', 'gas_certificate_expires_at' => '2026-04-30']);

        return Vehicle::findOrFail($id);
    }

    private function pdf(string $content = 'document'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('certificado.pdf', '%PDF-1.4 '.$content."\n%%EOF");
    }

    private function reading(string $text): void
    {
        $this->mock(VehicleCertificateReader::class)->shouldReceive('read')->once()->andReturn(['pages' => [$text], 'method' => 'ocr']);
    }

    public function test_saving_certificate_updates_expiry_automatically_keeps_audit_and_duplicate_does_not_regress(): void
    {
        $vehicle = $this->workspace();
        $this->reading('CERTIFICADO DE REVISION TECNICA FECHA REVISION: 30 ABRIL 2026 PLACA PATENTE TEST31 APROBADO VALIDO HASTA ABRIL 2027');
        $this->post(route('fleet.vehicles.documents.store', $vehicle), ['document' => $this->pdf(), 'technical_inspection_expires_at' => '2099-01-01'])->assertRedirect(route('fleet.vehicles.show', $vehicle))->assertSessionHas('status', 'PDF guardado. Fechas y alertas actualizadas automáticamente.');
        $vehicle->refresh();
        $this->assertSame('2027-04-30', $vehicle->technical_inspection_expires_at->toDateString());
        $this->assertSame('2026-04-30', $vehicle->gas_certificate_expires_at->toDateString());
        $this->assertSame(150000, $vehicle->odometer_km);
        $this->assertSame('Activa', $vehicle->operational_status);
        $link = json_decode(DB::table('VEH_document_vehicles')->value('details'), true);
        $this->assertSame('2026-04-30', $link['upload_audit']['previous_expirations']['technical_inspection_expires_at']);
        $this->assertSame('month', $link['verified_expirations']['technical_inspection_expires_at']['precision']);
        Storage::disk('local')->assertExists(DB::table('VEH_documents')->value('storage_path'));
        $this->get(route('fleet.vehicles.show', $vehicle))->assertOk()->assertSee('abril 2027')->assertSee('Guardar documento');
        $this->post(route('fleet.vehicles.documents.store', $vehicle), ['document' => $this->pdf()])->assertRedirect();
        $this->assertDatabaseCount('VEH_documents', 1);
        $this->assertDatabaseCount('VEH_document_vehicles', 1);
    }

    public function test_unreadable_or_wrong_plate_pdf_is_visible_pending_without_changing_dates(): void
    {
        $vehicle = $this->workspace();
        $this->reading('CERTIFICADO EMISIONES CONTAMINANTES FECHA REVISION: 30 ABRIL 2026 PLACA PATENTE OTRA31 APROBADO VALIDO HASTA OCTUBRE 2027');
        $this->post(route('fleet.vehicles.documents.store', $vehicle), ['document' => $this->pdf()])->assertRedirect()->assertSessionHas('status', 'PDF guardado y visible. Lectura pendiente de revisión; las fechas actuales se conservaron.');
        $this->assertSame('2026-04-30', $vehicle->fresh()->gas_certificate_expires_at->toDateString());
        $this->assertDatabaseCount('VEH_documents', 1);
        $this->get(route('fleet.vehicles.show', $vehicle))->assertOk()->assertSee('Pendiente de revisión');
        $this->get(route('fleet.vehicles.documents.show', ['vehicle' => $vehicle, 'document' => DB::table('VEH_documents')->value('id')]))->assertOk();
    }

    public function test_gas_updates_independently_and_older_pdf_does_not_replace_current_expiry(): void
    {
        $vehicle = $this->workspace();
        $this->reading('CERTIFICADO EMISIONES CONTAMINANTES FECHA: 7 AGOSTO 2026 PATENTE TEST31 APROBADO VALIDA HASTA 05 FEBRERO 2027');
        $this->post(route('fleet.vehicles.documents.store', $vehicle), ['document' => $this->pdf('new')])->assertRedirect();
        $this->assertSame('2027-02-05', $vehicle->fresh()->gas_certificate_expires_at->toDateString());
        $this->reading('CERTIFICADO EMISIONES CONTAMINANTES FECHA: 7 AGOSTO 2025 PATENTE TEST31 APROBADO VALIDA HASTA 05 FEBRERO 2026');
        $this->post(route('fleet.vehicles.documents.store', $vehicle), ['document' => $this->pdf('old')])->assertRedirect();
        $this->assertSame('2027-02-05', $vehicle->fresh()->gas_certificate_expires_at->toDateString());
        $this->assertDatabaseCount('VEH_documents', 2);
    }

    public function test_read_only_cannot_upload_or_change_expirations(): void
    {
        $vehicle = $this->workspace('read_only');
        $this->post(route('fleet.vehicles.documents.store', $vehicle), ['document' => $this->pdf()])->assertForbidden();
        $this->assertDatabaseCount('VEH_documents', 0);
        $this->get(route('fleet.vehicles.show', $vehicle))->assertOk()->assertDontSee('Guardar documento');
    }

    public function test_parser_rejects_conflicting_expirations_and_rejected_certificates(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10', 'America/Santiago'));
        $parser = app(VehicleCertificateParser::class);
        $this->assertSame([], $parser->parse(['CERTIFICADO DE REVISION TECNICA FECHA REVISION: 30 ABRIL 2026 PATENTE TEST31 RECHAZADO VALIDO HASTA ABRIL 2027'], 'TEST31'));
        $first = 'CERTIFICADO DE REVISION TECNICA FECHA REVISION: 30 ABRIL 2026 PATENTE TEST31 APROBADO VALIDO HASTA ABRIL 2027';
        $this->assertSame([], $parser->parse([$first.' '.str_replace('ABRIL 2027', 'MAYO 2027', $first)], 'TEST31'));
        $this->assertSame([], $parser->parse([str_replace('30 ABRIL 2026', '31 FEBRERO 2026', $first)], 'TEST31'));
        $this->assertSame([], $parser->parse([str_replace('APROBADO VALIDO', 'APROBADO RECHAZADO RESULTADO: RECHAZADO VALIDO', $first)], 'TEST31'));
    }

    public function test_combined_pdf_reads_separate_certificates_with_table_layout_and_keeps_page_numbers(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10', 'America/Santiago'));
        $fields = app(VehicleCertificateParser::class)->parse([
            'PERMISO DE CIRCULACION · documento no reconocido',
            'FECHA 7 AGOSTO 2026 PATENTE TEST31 CERTIFICADO EMISIONES CONTAMINANTES VALIDA HASTA HUMO AZUL VEHICULOS DIESEL CAR: XXXX 05 FEBRERO 2027 APROBADO',
            'FECHA 7 AGOSTO 2026 PATENTE TEST31 CERTIFICADO DE REVISION TECNICA APROBADO RECHAZADO REVISION TECNICA VALIDA HASTA 05 FEBRERO 2027 RESULTADO: APROBADO',
        ], 'TEST31');
        $this->assertSame('2027-02-05', $fields['gas_certificate_expires_at']['date']);
        $this->assertSame(2, $fields['gas_certificate_expires_at']['page']);
        $this->assertSame('2027-02-05', $fields['technical_inspection_expires_at']['date']);
        $this->assertSame(3, $fields['technical_inspection_expires_at']['page']);
    }

    public function test_homologation_reads_abbreviated_month_without_inventing_an_exact_printed_day(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10', 'America/Santiago'));
        $fields = app(VehicleCertificateParser::class)->parse(['CERTIFICADO DE HOMOLOGACION INDIVIDUAL ELECTRONICO FECHA DE EMISION 13/MAR/2024 PATENTE TEST-31 VALIDO HASTA SEP/2026'], 'TEST31');
        $this->assertSame('2026-09-30', $fields['technical_inspection_expires_at']['date']);
        $this->assertSame('month', $fields['technical_inspection_expires_at']['precision']);
        $this->assertSame('homologation', $fields['technical_inspection_expires_at']['type']);
    }
}
