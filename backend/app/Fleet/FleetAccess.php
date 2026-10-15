<?php

namespace App\Fleet;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FleetAccess
{
    public const SCOPES = [
        'warehouse.inventory' => 'WMS · stock y movimientos',
        'warehouse.configure' => 'WMS · parámetros y reportes',
        'operations.feasibility' => 'Evaluación operativa',
        'finance.feasibility.validate' => 'Validar costos',
        'commercial.feasibility.approve' => 'Aprobación comercial final',
        'admin.area' => 'Administrador', 'admin.users' => 'Usuarios', 'admin.permissions' => 'Permisos',
        'admin.masters' => 'Maestros', 'admin.procedures' => 'Procedimientos',
        'management.area' => 'Gerencia', 'management.executive' => 'Informe ejecutivo', 'management.consolidated' => 'Consolidado',
        'coordination.area' => 'Coordinación', 'coordination.requests' => 'Solicitudes', 'coordination.fixed-pickups' => 'Retiros fijos',
        'coordination.feasibility' => 'Factibilidad de servicios', 'coordination.reprogramming.approve' => 'Autorizar reprogramaciones',
        'coordination.reprogramming.request' => 'Solicitar reprogramaciones',
        'operations.area' => 'Operaciones', 'operations.fleet' => 'Flota', 'operations.maintenance' => 'Mantenciones',
        'operations.scheduling' => 'Programación', 'operations.fuel' => 'Combustible', 'operations.mail' => 'Correos', 'operations.tag' => 'TAG',
        'operations.vehicle_pool' => 'Pool operacional compartido 4N / PMCB',
        'operations.scheduling.overload.approve' => 'Autorizar sobrecarga de programación',
        'operations.maintenance.close' => 'Cerrar mantenciones',
        'operations.maintenance.correct' => 'Corregir mantenciones cerradas',
        'commercial.area' => 'Comercial', 'commercial.feasibility' => 'Factibilidad comercial', 'finance.feasibility' => 'Revisión financiera',
    ];

    public const PAGES = [
        'operations.feasibility' => ['area' => 'Operaciones', 'title' => 'Factibilidad', 'slug' => 'operaciones/factibilidad'],
        'admin.masters' => ['area' => 'Administrador', 'title' => 'Maestros', 'slug' => 'administrador/maestros'],
        'admin.procedures' => ['area' => 'Administrador', 'title' => 'Procedimientos', 'slug' => 'administrador/procedimientos'],
        'management.executive' => ['area' => 'Gerencia', 'title' => 'Informe ejecutivo de flota', 'slug' => 'gerencia/ejecutivo'],
        'coordination.requests' => ['area' => 'Coordinación', 'title' => 'Solicitudes', 'slug' => 'coordinacion/solicitudes'],
        'coordination.fixed-pickups' => ['area' => 'Coordinación', 'title' => 'Retiros fijos', 'slug' => 'coordinacion/retiros-fijos'],
        'coordination.feasibility' => ['area' => 'Coordinación', 'title' => 'Factibilidad de servicios', 'slug' => 'coordinacion/factibilidad'],
        'operations.fleet' => ['area' => 'Operaciones', 'title' => 'Flota', 'slug' => 'operaciones/flota'],
        'operations.maintenance' => ['area' => 'Operaciones', 'title' => 'Mantenciones', 'slug' => 'operaciones/mantenciones'],
        'operations.scheduling' => ['area' => 'Operaciones', 'title' => 'Programación', 'slug' => 'operaciones/programacion'],
        'operations.fuel' => ['area' => 'Operaciones', 'title' => 'Combustible', 'slug' => 'operaciones/combustible'],
        'operations.mail' => ['area' => 'Operaciones', 'title' => 'Correos', 'slug' => 'operaciones/correos'],
        'operations.tag' => ['area' => 'Operaciones', 'title' => 'TAG', 'slug' => 'operaciones/tag'],
        'commercial.feasibility' => ['area' => 'Comercial', 'title' => 'Factibilidad', 'slug' => 'comercial/factibilidad'],
        'finance.feasibility' => ['area' => 'Coordinación', 'title' => 'Revisión financiera', 'slug' => 'coordinacion/revision-financiera'],
    ];

    public function availableTenants(User $user): Collection
    {
        return Tenant::query()
            ->join('MBA_tenant_users', 'MBA_tenants.id', '=', 'MBA_tenant_users.tenant_id')
            ->join('fleet_membership_profiles', 'fleet_membership_profiles.tenant_user_id', '=', 'MBA_tenant_users.id')
            ->join('fleet_profiles', 'fleet_profiles.id', '=', 'fleet_membership_profiles.fleet_profile_id')
            ->where('MBA_tenant_users.user_id', $user->id)
            ->where('MBA_tenant_users.is_active', true)
            ->where('fleet_membership_profiles.is_active', true)
            ->where('MBA_tenants.is_active', true)
            ->where('fleet_profiles.is_active', true)
            ->whereColumn('fleet_profiles.tenant_id', 'MBA_tenants.id')
            ->select('MBA_tenants.*')
            ->orderBy('MBA_tenants.code')
            ->get()
            ->filter(fn (Tenant $tenant): bool => $this->hasAnyScope($user, $tenant))
            ->values();
    }

    public function tenant(Request $request): ?Tenant
    {
        $tenant = $request->attributes->get('fleet_tenant');

        return $tenant instanceof Tenant ? $tenant : null;
    }

    public function level(User $user, Tenant $tenant, string $permissionCode): int
    {
        if (! array_key_exists($permissionCode, self::SCOPES)) {
            return 0;
        }

        $membership = DB::table('MBA_tenant_users')
            ->join('fleet_membership_profiles', 'fleet_membership_profiles.tenant_user_id', '=', 'MBA_tenant_users.id')
            ->join('fleet_profiles', 'fleet_profiles.id', '=', 'fleet_membership_profiles.fleet_profile_id')
            ->where('MBA_tenant_users.user_id', $user->id)
            ->where('MBA_tenant_users.tenant_id', $tenant->id)
            ->where('MBA_tenant_users.is_active', true)
            ->where('fleet_membership_profiles.is_active', true)
            ->where('fleet_profiles.is_active', true)
            ->where('fleet_profiles.tenant_id', $tenant->id)
            ->select('MBA_tenant_users.id', 'fleet_profiles.id as fleet_profile_id')
            ->first();
        if ($membership === null || ! $tenant->is_active) {
            return 0;
        }

        $override = DB::table('fleet_user_permission_overrides')->where('tenant_user_id', $membership->id)
            ->where('permission_code', $permissionCode)->first();
        if ($override !== null) {
            return (int) $override->access_level;
        }

        return (int) (DB::table('fleet_profile_permissions')->where('fleet_profile_id', $membership->fleet_profile_id)
            ->where('permission_code', $permissionCode)->value('access_level') ?? 0);
    }

    public function allows(User $user, Tenant $tenant, string $permissionCode, int $requiredLevel = 1): bool
    {
        return $this->level($user, $tenant, $permissionCode) >= $requiredLevel;
    }

    public function canEnter(User $user): bool
    {
        return $this->availableTenants($user)->isNotEmpty();
    }

    private function hasAnyScope(User $user, Tenant $tenant): bool
    {
        foreach (array_keys(self::SCOPES) as $scope) {
            if ($this->allows($user, $tenant, $scope)) {
                return true;
            }
        }

        return false;
    }

    public function audit(?User $actor, ?Tenant $tenant, ?int $targetUserId, string $action, ?array $before, ?array $after): void
    {
        DB::table('fleet_permission_audits')->insert([
            'tenant_id' => $tenant?->id, 'actor_user_id' => $actor?->id, 'target_user_id' => $targetUserId,
            'action' => $action,
            'previous_values' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'new_values' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
