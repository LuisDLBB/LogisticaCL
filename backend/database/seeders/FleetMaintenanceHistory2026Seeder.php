<?php

namespace Database\Seeders;

use App\Fleet\VehicleCatalog;
use App\Models\Provider;
use App\Models\Tenant;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Models\VehicleMaintenanceImport;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FleetMaintenanceHistory2026Seeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        $records = json_decode(file_get_contents(database_path('data/fleet_maintenance_history_2026.json')), true, 512, JSON_THROW_ON_ERROR);
        $vehicles = Vehicle::query()->get()->keyBy('plate');
        $tenants = Tenant::query()->get();
        $providerTenant = $tenants->firstWhere('code', '4N');
        DB::transaction(function () use ($records, $vehicles, $tenants, $providerTenant): void {
            foreach ($records as $record) {
                if (VehicleMaintenanceImport::where('source_key', $record['source_key'])->exists()) {
                    continue;
                }
                $vehicle = $vehicles->get($record['plate']);
                $owner = $vehicle ? app(VehicleCatalog::class)->owner($vehicle, $tenants) : null;
                if (! $owner || strtoupper($owner->code) !== strtoupper($record['company'])) {
                    throw new RuntimeException('Patente o empresa sin coincidencia: '.$record['plate']);
                }
                $raw = $record['raw'];
                $provider = null;
                if ($raw['RUT'] !== '' && $raw['Razón social'] !== '') {
                    [$number, $digit] = explode('-', strtoupper($raw['RUT']));
                    $provider = Provider::firstOrCreate(['tenant_id' => $providerTenant->id, 'tax_id' => strtoupper($raw['RUT'])], [
                        'tax_id_number' => $number, 'tax_id_check_digit' => $digit, 'legal_name' => $raw['Razón social'],
                        'operational_name' => $raw['Taller/Concesionaria'], 'operator_type' => 'Vehículos', 'is_active' => true,
                    ]);
                }
                $status = match ($raw['Estado']) {
                    'Cerrado' => 'closed', 'Pendiente' => 'pending',
                    default => throw new RuntimeException('Estado no reconocido en línea '.$record['source_line']),
                };
                $maintenance = VehicleMaintenance::create([
                    'vehicle_id' => $vehicle->id, 'tenant_id' => $owner->id,
                    'maintenance_type' => $raw['Tipo solicitud'], 'execution_type' => 'external', 'status' => $status,
                    'reported_odometer_km' => $record['odometer_km'], 'provider_id' => $provider?->id,
                    'estimated_cost' => $record['estimated_cost'], 'actual_cost' => $status === 'closed' ? $record['total_amount'] : null,
                    'document_type' => $raw['Tipo documento'] ?: null, 'document_number' => $raw['Folio'] ?: null,
                    'notes' => $raw['Motivo'], 'closing_notes' => $raw['Observacion'] ?: null,
                ]);
                VehicleMaintenanceImport::create([
                    'vehicle_maintenance_id' => $maintenance->id, 'source_key' => $record['source_key'], 'source_line' => $record['source_line'],
                    'requested_at' => $record['requested_at'], 'document_issued_at' => $record['document_issued_at'],
                    'net_amount' => $record['net_amount'], 'vat_amount' => $record['vat_amount'],
                    'billed_company' => $raw['Empresa Facturada'] ?: null, 'source_data' => $raw,
                ]);
                DB::table('vehicle_maintenance_events')->insert([
                    'vehicle_maintenance_id' => $maintenance->id, 'tenant_id' => $owner->id, 'event_type' => 'historical_import',
                    'new_status' => $status, 'odometer_km' => $record['odometer_km'], 'recorded_at' => now(),
                    'details' => json_encode(['source_key' => $record['source_key'], 'requested_at' => $record['requested_at'], 'note' => 'Estado según planilla; fecha y KM de cierre no informados.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }
        });
    }
}
