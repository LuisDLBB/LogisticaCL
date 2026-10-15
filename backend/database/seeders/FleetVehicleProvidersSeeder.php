<?php

namespace Database\Seeders;

use App\Models\Provider;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FleetVehicleProvidersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $records = json_decode(file_get_contents(database_path('data/fleet_vehicle_providers.json')), true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($records, $tenant): void {
            foreach ($records as [$name, $rut, $legal]) {
                [$number, $digit] = explode('-', $rut);
                Provider::firstOrCreate(['tenant_id' => $tenant->id, 'tax_id' => $rut], ['tax_id_number' => $number, 'tax_id_check_digit' => $digit, 'legal_name' => $legal, 'operational_name' => $name, 'operator_type' => 'Vehículos', 'is_active' => true]);
            }
        });
    }
}
