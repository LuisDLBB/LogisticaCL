<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        return $request->user() ? redirect()->route('portal.home') : view('portal.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:1024']]);
        $key = 'portal-login:'.hash('sha256', Str::lower(trim($data['username'])).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['username' => 'Demasiados intentos. Intenta nuevamente en un minuto.']);
        }
        $identifier = Str::lower(trim($data['username']));
        $candidates = User::query()->where(function ($query) use ($identifier): void {
            $query->whereRaw('LOWER(username) = ?', [$identifier])->orWhereRaw('LOWER(email) = ?', [$identifier]);
        })->limit(2)->get();
        $user = $candidates->count() === 1 ? $candidates->first() : null;
        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->tenants()->where('code', '4N')->where('MBA_tenants.is_active', true)->wherePivot('is_active', true)->exists()) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['username' => 'Usuario o contraseña incorrectos, o acceso no habilitado.']);
        }
        RateLimiter::clear($key);
        Auth::login($user);
        $request->session()->regenerate();
        $this->record($request, 'Inicio de sesión', 'Acceso');

        return redirect()->intended(route('portal.home'));
    }

    public function home(Request $request): View
    {
        $query = DB::table('user_activities')->where('user_id', $request->user()->id)->where('tenant_id', $request->attributes->get('portal_tenant')->id);

        return view('portal.home', [
            'activities' => (clone $query)->latest('id')->paginate(10),
            'todayCount' => (clone $query)->where('created_at', '>=', now()->timezone('America/Santiago')->startOfDay()->utc())->count(),
            'moduleCount' => (clone $query)->where('created_at', '>=', now()->timezone('America/Santiago')->startOfDay()->utc())->whereNotIn('module', ['Acceso', 'Mi cuenta'])->distinct()->count('module'),
        ]);
    }

    public function module(Request $request, string $module): View|RedirectResponse
    {
        abort_if($request->attributes->get('portal_tenant')?->pivot?->role_code === 'driver', 403, 'Tu acceso de chofer solo permite consultar tus rutas.');
        if ($module === 'operaciones') {
            return redirect()->route('operations.dashboard');
        }

        $modules = ['comercial' => 'Comercial', 'post-venta' => 'Post Venta', 'flota' => 'Flota', 'operaciones' => 'Operaciones', 'finanzas' => 'Finanzas'];
        abort_unless(isset($modules[$module]), 404);

        return view('portal.module', ['title' => $modules[$module]]);
    }

    public function profile(): View
    {
        return view('portal.profile');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [$request->attributes->get('portal_tenant')?->pivot?->role_code === 'driver' ? 'nullable' : 'required', 'email', 'max:255', Rule::unique('MBA_users')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'current_password' => ['required', 'current_password'],
        ], ['current_password.current_password' => 'La contraseña actual no es correcta.', 'current_password.required' => 'Confirma tu contraseña actual para guardar.', 'email.unique' => 'Ese correo ya está en uso.']);
        DB::transaction(function () use ($user, $data, $request): void {
            if ($user->email !== ($data['email'] ?? null)) {
                $user->email_verified_at = null;
            }
            $user->name = $data['name'];
            $user->email = $data['email'] ?? null;
            $user->phone = $data['phone'];
            $user->save();
            $this->record($request, 'Datos personales actualizados', 'Mi cuenta');
        });

        return back()->with('status', 'Tus datos personales se guardaron correctamente.');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed', 'different:current_password']], [
            'current_password.current_password' => 'La contraseña actual no es correcta.',
            'password.min' => 'La nueva contraseña debe tener al menos 12 caracteres.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'password.different' => 'Elige una contraseña diferente a la actual.',
        ]);
        DB::transaction(function () use ($request, $data): void {
            $request->user()->password = $data['password'];
            $request->user()->remember_token = Str::random(60);
            $request->user()->save();
            DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();
            $this->record($request, 'Contraseña actualizada', 'Mi cuenta');
        });
        $request->session()->regenerate();

        return back()->with('status', 'Contraseña actualizada. Las otras sesiones se cerraron.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->record($request, 'Cierre de sesión', 'Acceso');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function record(Request $request, string $action, string $module): void
    {
        $tenant = $request->attributes->get('portal_tenant') ?? $request->user()->tenants()->where('code', '4N')->firstOrFail();
        DB::table('user_activities')->insert(['user_id' => $request->user()->id, 'tenant_id' => $tenant->id, 'action' => $action, 'module' => $module, 'created_at' => now()]);
    }
}
