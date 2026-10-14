<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\FleetAccess;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserAdministrationController extends Controller
{
    public function index(Request $request, FleetAccess $access): View
    {
        $tenant = $access->tenant($request);
        $users = User::query()
            ->whereDoesntHave('tenants')
            ->orWhereHas('tenants', fn ($query) => $query->where('MBA_tenants.id', $tenant->id))
            ->orderBy('name')
            ->get();
        $memberships = DB::table('MBA_tenant_users as memberships')
            ->join('fleet_membership_profiles as fleet_memberships', 'fleet_memberships.tenant_user_id', '=', 'memberships.id')
            ->join('fleet_profiles as profiles', 'profiles.id', '=', 'fleet_memberships.fleet_profile_id')
            ->where('memberships.tenant_id', $tenant->id)
            ->get(['fleet_memberships.id', 'memberships.user_id', 'profiles.code as role_code', 'fleet_memberships.is_active', 'memberships.is_active as membership_active'])
            ->keyBy('user_id');
        $profiles = DB::table('fleet_profiles')->where('tenant_id', $tenant->id)->orderBy('name')->get();

        return view('fleet::users', compact('tenant', 'users', 'memberships', 'profiles'));
    }

    public function create(Request $request, FleetAccess $access): RedirectResponse
    {
        $tenant = $access->tenant($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('MBA_users', 'email')],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'role_code' => ['required', Rule::exists('fleet_profiles', 'code')->where('tenant_id', $tenant->id)->where('is_active', true)],
        ]);

        DB::transaction(function () use ($request, $access, $tenant, $validated): void {
            $user = User::create([
                'name' => $validated['name'], 'email' => $validated['email'], 'password' => $validated['password'],
            ]);
            $membershipId = DB::table('MBA_tenant_users')->insertGetId([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => 'fleet',
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $profileId = DB::table('fleet_profiles')->where('tenant_id', $tenant->id)->where('code', $validated['role_code'])->value('id');
            DB::table('fleet_membership_profiles')->insert([
                'tenant_user_id' => $membershipId, 'fleet_profile_id' => $profileId,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $access->audit($request->user(), $tenant, $user->id, 'user_created_and_associated', null, [
                'email' => $user->email, 'role_code' => $validated['role_code'], 'is_active' => true,
            ]);
        });

        return back()->with('status', 'Usuario creado y asociado a la empresa activa.');
    }

    public function associate(Request $request, FleetAccess $access): RedirectResponse
    {
        $tenant = $access->tenant($request);
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'role_code' => ['required', Rule::exists('fleet_profiles', 'code')->where('tenant_id', $tenant->id)->where('is_active', true)],
        ]);
        $user = User::query()->where('email', $validated['email'])->first();
        if ($user === null) {
            throw ValidationException::withMessages(['email' => 'No existe una cuenta con ese correo.']);
        }
        $membership = DB::table('MBA_tenant_users')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
        if ($membership !== null && DB::table('fleet_membership_profiles')->where('tenant_user_id', $membership->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'El usuario ya tiene un perfil de Flota en esta empresa.']);
        }

        DB::transaction(function () use ($request, $access, $tenant, $user, $validated, $membership): void {
            $membershipId = $membership?->id ?? DB::table('MBA_tenant_users')->insertGetId([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => 'fleet',
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $profileId = DB::table('fleet_profiles')->where('tenant_id', $tenant->id)->where('code', $validated['role_code'])->value('id');
            DB::table('fleet_membership_profiles')->insert([
                'tenant_user_id' => $membershipId, 'fleet_profile_id' => $profileId,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $access->audit($request->user(), $tenant, $user->id, 'user_associated', null, [
                'role_code' => $validated['role_code'], 'is_active' => true,
            ]);
        });

        return back()->with('status', 'Usuario asociado a la empresa activa.');
    }

    public function update(Request $request, FleetAccess $access, int $membership): RedirectResponse
    {
        $tenant = $access->tenant($request);
        $row = DB::table('fleet_membership_profiles as fleet_memberships')
            ->join('MBA_tenant_users as memberships', 'memberships.id', '=', 'fleet_memberships.tenant_user_id')
            ->join('fleet_profiles as profiles', 'profiles.id', '=', 'fleet_memberships.fleet_profile_id')
            ->where('fleet_memberships.id', $membership)->where('memberships.tenant_id', $tenant->id)
            ->first(['fleet_memberships.id', 'fleet_memberships.is_active', 'memberships.user_id', 'profiles.code as role_code']);
        abort_unless($row !== null, 404);
        $validated = $request->validate([
            'role_code' => ['required', Rule::exists('fleet_profiles', 'code')->where('tenant_id', $tenant->id)->where('is_active', true)],
            'is_active' => ['required', 'boolean'],
        ]);
        $active = (bool) $validated['is_active'];
        if ($row->role_code === 'administrator' && ($validated['role_code'] !== 'administrator' || ! $active)) {
            $activeAdmins = DB::table('fleet_membership_profiles as fleet_memberships')
                ->join('MBA_tenant_users as memberships', 'memberships.id', '=', 'fleet_memberships.tenant_user_id')
                ->join('fleet_profiles as profiles', 'profiles.id', '=', 'fleet_memberships.fleet_profile_id')
                ->where('memberships.tenant_id', $tenant->id)->where('profiles.code', 'administrator')
                ->where('memberships.is_active', true)->where('fleet_memberships.is_active', true)->count();
            if ($activeAdmins <= 1) {
                throw ValidationException::withMessages(['role_code' => 'Debe permanecer al menos un administrador activo en esta empresa.']);
            }
        }

        DB::transaction(function () use ($request, $access, $tenant, $row, $validated, $active): void {
            $profileId = DB::table('fleet_profiles')->where('tenant_id', $tenant->id)->where('code', $validated['role_code'])->value('id');
            DB::table('fleet_membership_profiles')->where('id', $row->id)->update([
                'fleet_profile_id' => $profileId, 'is_active' => $active, 'updated_at' => now(),
            ]);
            $access->audit($request->user(), $tenant, $row->user_id, 'membership_updated', [
                'role_code' => $row->role_code, 'is_active' => (bool) $row->is_active,
            ], [
                'role_code' => $validated['role_code'], 'is_active' => $active,
            ]);
        });

        return back()->with('status', 'Acceso actualizado.');
    }
}
