<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Operations\Services\OperationAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OperationDriverAccessController extends Controller
{
    public function index(Request $request): View
    {
        OperationAccess::requireSupervisor($request);
        $drivers = DB::table('Ope_Choferes as driver')
            ->leftJoin('MBA_users as account', 'account.id', '=', 'driver.user_id')
            ->where('driver.tenant_id', OperationAccess::tenant($request))
            ->where('driver.is_active', true)
            ->orderBy('driver.name')
            ->get(['driver.id', 'driver.name', 'driver.rut', 'driver.user_id', 'account.username']);

        return view('operations::driver-access', ['drivers' => $drivers]);
    }

    public function store(Request $request, int $driver): RedirectResponse
    {
        OperationAccess::requireSupervisor($request);
        $tenant = OperationAccess::tenant($request);
        $data = $request->validate([
            'username' => ['required', 'string', 'min:4', 'max:100', 'regex:/^[a-zA-Z0-9._-]+$/D'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('MBA_users', 'email')],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        DB::transaction(function () use ($request, $tenant, $driver, $data): void {
            $record = DB::table('Ope_Choferes')->where(['tenant_id' => $tenant, 'id' => $driver, 'is_active' => true])->lockForUpdate()->firstOrFail();
            if ($record->user_id !== null) {
                throw ValidationException::withMessages(['username' => 'Este chofer ya tiene acceso vinculado.']);
            }
            if (User::query()->whereRaw('LOWER(username) = ?', [Str::lower($data['username'])])->exists()) {
                throw ValidationException::withMessages(['username' => 'Este nombre de usuario ya está ocupado.']);
            }
            $account = User::create(['name' => $record->name, 'email' => $data['email'] ?? null, 'password' => $data['password']]);
            $account->username = $data['username'];
            $account->profile_name = 'Chofer';
            $account->tax_id = $record->rut;
            $account->save();
            $account->tenants()->attach($tenant, ['role_code' => 'driver', 'is_active' => true]);
            DB::table('Ope_Choferes')->where('id', $driver)->update(['user_id' => $account->id, 'updated_at' => now()]);
            OperationAccess::audit($tenant, $request->user()->id, 'Habilitar acceso de chofer', 'chofer', $driver, ['user_id' => $account->id, 'username' => $data['username']]);
        });

        return back()->with('status', 'Acceso del chofer habilitado. Entrégale su usuario y contraseña por un canal seguro.');
    }
}
