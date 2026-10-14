<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FleetMaintenanceTypesSeeder extends Seeder
{
    public function run(): void
    {
        $labels = ['MANTENCION' => 'Mantención', 'REPARACION' => 'Reparación', 'NEUMATICOS' => 'Neumáticos', 'REPUESTO' => 'Repuesto', 'PLATAFORMA' => 'Plataforma', 'ELECTRICO' => 'Eléctrico', 'PARABRISAS' => 'Parabrisas', 'ASISTENCIA' => 'Asistencia', 'GARANTIA' => 'Garantía'];
        $history = json_decode(file_get_contents(database_path('data/fleet_maintenance_history_2026.json')), true, 512, JSON_THROW_ON_ERROR);
        $keys = collect($history)->map(fn (array $row): string => Str::upper(Str::ascii(trim($row['raw']['Tipo solicitud'] ?? ''))))->unique()->filter(fn (string $key): bool => isset($labels[$key]));
        foreach (Tenant::whereIn('code', ['4N', 'PMCB'])->get() as $tenant) {
            foreach ($keys as $key) {
                DB::table('VEH_maintenance_types')->insertOrIgnore(['tenant_id' => $tenant->id, 'name' => $labels[$key], 'normalized_name' => $key, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
