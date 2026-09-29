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
            ->orWhereHas('tenants', fn ($query) => $query->where('tenants.id', $tenant->id))
            ->orderBy('name')
            ->get();
        $memberships = DB::table('tenant_users')->where('tenant_id', $tenant->id)->get()->keyBy('user_id');
        $profiles = DB::table('fleet_profiles')->where('tenant_id', $tenant->id)->orderBy('name')->get();

        return view('fleet::users', compact('tenant', 'users', 'memberships', 'profiles'));
    }

    public function create(Request $request, FleetAccess $access): RedirectResponse
    {
        $tenant = $access->tenant($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'role_code' => ['required', Rule::exists('fleet_profiles', 'code')->where('tenant_id', $tenant->id)->where('is_active', true)],
        ]);

        DB::transaction(function () use ($request, $access, $tenant, $validated): void {
            $user = User::create([
                'name' => $validated['name'], 'email' => $validated['email'], 'password' => $validated['password'],
            ]);
            DB::table('tenant_users')->insert([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $validated['role_code'],
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
        if (DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'El usuario ya está asociado a esta empresa.']);
        }

        DB::transaction(function () use ($request, $access, $tenant, $user, $validated): void {
            DB::table('tenant_users')->insert([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $validated['role_code'],
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
        $row = DB::table('tenant_users')->where('id', $membership)->where('tenant_id', $tenant->id)->first();
        abort_unless($row !== null, 404);
        $validated = $request->validate([
            'role_code' => ['required', Rule::exists('fleet_profiles', 'code')->where('tenant_id', $tenant->id)->where('is_active', true)],
            'is_active' => ['required', 'boolean'],
        ]);
        $active = (bool) $validated['is_active'];
        if ($row->role_code === 'administrator' && ($validated['role_code'] !== 'administrator' || ! $active)) {
            $activeAdmins = DB::table('tenant_users')->where('tenant_id', $tenant->id)
                ->where('role_code', 'administrator')->where('is_active', true)->count();
            if ($activeAdmins <= 1) {
                throw ValidationException::withMessages(['role_code' => 'Debe permanecer al menos un administrador activo en esta empresa.']);
            }
        }

        DB::transaction(function () use ($request, $access, $tenant, $row, $validated, $active): void {
            DB::table('tenant_users')->where('id', $row->id)->update([
                'role_code' => $validated['role_code'], 'is_active' => $active, 'updated_at' => now(),
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
