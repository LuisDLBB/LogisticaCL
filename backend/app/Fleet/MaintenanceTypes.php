<?php

namespace App\Fleet;

use App\Models\VehicleMaintenance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MaintenanceTypes
{
    public function key(string $name): string
    {
        return Str::upper(Str::ascii(trim(preg_replace('/\s+/u', ' ', $name))));
    }

    public function options(int $tenantId, ?string $current = null): Collection
    {
        $names = DB::table('VEH_maintenance_types')->where('tenant_id', $tenantId)->pluck('name')->merge(VehicleMaintenance::where('tenant_id', $tenantId)->distinct()->pluck('maintenance_type'));
        if (filled($current)) {
            $names->push($current);
        }

        return $names->filter(fn ($name): bool => filled($name))->unique(fn ($name): string => $this->key($name))->sortBy(fn ($name): string => $this->key($name))->values();
    }

    public function remember(int $tenantId, string $name, int $actorId): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        DB::table('VEH_maintenance_types')->insertOrIgnore(['tenant_id' => $tenantId, 'name' => $name, 'normalized_name' => $this->key($name), 'actor_user_id' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
    }
}
