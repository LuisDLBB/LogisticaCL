<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\FleetAccess;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PermissionAdministrationController extends Controller
{
    public function index(Request $request, FleetAccess $access): View
    {
        $tenant = $access->tenant($request);
        $profiles = DB::table('fleet_profiles')->where('tenant_id', $tenant->id)->orderBy('name')->get();
        $memberships = DB::table('tenant_users')->join('users', 'users.id', '=', 'tenant_users.user_id')
            ->where('tenant_users.tenant_id', $tenant->id)
            ->select('tenant_users.*', 'users.name', 'users.email')->orderBy('users.name')->get();
        $profileLevels = DB::table('fleet_profile_permissions')
            ->whereIn('fleet_profile_id', $profiles->pluck('id'))
            ->get()->groupBy('fleet_profile_id');
        $overrides = DB::table('fleet_user_permission_overrides')
            ->whereIn('tenant_user_id', $memberships->pluck('id'))
            ->get()->groupBy('tenant_user_id');
        $audits = DB::table('fleet_permission_audits')->where('tenant_id', $tenant->id)
            ->orderByDesc('id')->limit(50)->get();
        $userNames = User::query()->pluck('name', 'id');

        return view('fleet::permissions', compact('tenant', 'profiles', 'memberships', 'profileLevels', 'overrides', 'audits', 'userNames'));
    }

    public function updateProfile(Request $request, FleetAccess $access, int $profile): RedirectResponse
    {
        $tenant = $access->tenant($request);
        $row = DB::table('fleet_profiles')->where('id', $profile)->where('tenant_id', $tenant->id)->first();
        abort_unless($row !== null, 404);
        $levels = $this->validatedLevels($request, false);
        if ($row->code === 'administrator' && ($levels['admin.permissions'] < 3 || $levels['admin.users'] < 3)) {
            throw ValidationException::withMessages(['levels' => 'El perfil Administrador debe conservar la administración de usuarios y permisos.']);
        }
        $before = DB::table('fleet_profile_permissions')->where('fleet_profile_id', $row->id)
            ->pluck('access_level', 'permission_code')->toArray();

        DB::transaction(function () use ($request, $access, $tenant, $row, $levels, $before): void {
            foreach ($levels as $scope => $level) {
                DB::table('fleet_profile_permissions')->updateOrInsert(
                    ['fleet_profile_id' => $row->id, 'permission_code' => $scope],
                    ['access_level' => $level, 'updated_at' => now(), 'created_at' => now()],
                );
            }
            $access->audit($request->user(), $tenant, null, 'profile_permissions_updated',
                ['profile' => $row->code, 'levels' => $before], ['profile' => $row->code, 'levels' => $levels]);
        });

        return back()->with('status', 'Permisos del perfil actualizados.');
    }

    public function updateUser(Request $request, FleetAccess $access, int $membership): RedirectResponse
    {
        $tenant = $access->tenant($request);
        $row = DB::table('tenant_users')->where('id', $membership)->where('tenant_id', $tenant->id)->first();
        abort_unless($row !== null, 404);
        $levels = $this->validatedLevels($request, true);
        if ($row->role_code === 'administrator' && ($levels['admin.permissions'] ?? -1) !== -1 && $levels['admin.permissions'] < 3) {
            throw ValidationException::withMessages(['levels' => 'Para reducir un administrador, cambia primero su perfil.']);
        }
        $before = DB::table('fleet_user_permission_overrides')->where('tenant_user_id', $row->id)
            ->pluck('access_level', 'permission_code')->toArray();

        DB::transaction(function () use ($request, $access, $tenant, $row, $levels, $before): void {
            foreach ($levels as $scope => $level) {
                if ($level === -1) {
                    DB::table('fleet_user_permission_overrides')->where('tenant_user_id', $row->id)
                        ->where('permission_code', $scope)->delete();
                } else {
                    DB::table('fleet_user_permission_overrides')->updateOrInsert(
                        ['tenant_user_id' => $row->id, 'permission_code' => $scope],
                        ['access_level' => $level, 'updated_at' => now(), 'created_at' => now()],
                    );
                }
            }
            $access->audit($request->user(), $tenant, $row->user_id, 'user_permissions_updated',
                ['levels' => $before], ['levels' => array_filter($levels, fn (int $level): bool => $level !== -1)]);
        });

        return back()->with('status', 'Excepciones del usuario actualizadas.');
    }

    private function validatedLevels(Request $request, bool $allowInherit): array
    {
        $submitted = $request->input('levels');
        if (! is_array($submitted)) {
            throw ValidationException::withMessages(['levels' => 'Faltan los niveles de acceso.']);
        }

        $levels = [];
        foreach (array_keys(FleetAccess::SCOPES) as $scope) {
            $value = $submitted[$scope] ?? null;
            $allowed = $allowInherit ? ['-1', '0', '1', '2', '3'] : ['0', '1', '2', '3'];
            if (! is_scalar($value) || ! in_array((string) $value, $allowed, true)) {
                throw ValidationException::withMessages(['levels' => "Nivel inválido para {$scope}."]);
            }
            $levels[$scope] = (int) $value;
        }

        return $levels;
    }
}
