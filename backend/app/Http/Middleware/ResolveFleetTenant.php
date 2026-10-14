<?php

namespace App\Http\Middleware;

use App\Fleet\FleetAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveFleetTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $tenants = app(FleetAccess::class)->availableTenants($user);
        abort_if($tenants->isEmpty(), 403, 'No tienes una empresa activa para Control de Flota.');

        $selectedId = (int) $request->session()->get('fleet_tenant_id', 0);
        $tenant = $tenants->firstWhere('id', $selectedId) ?? $tenants->first();
        $request->session()->put('fleet_tenant_id', $tenant->id);
        $request->attributes->set('fleet_tenant', $tenant);

        return $next($request);
    }
}
