<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->user()?->tenants()->where('code', '4N')->where('tenants.is_active', true)->wherePivot('is_active', true)->first();
        abort_unless($tenant, 403, 'Tu usuario no tiene acceso activo a 4N. Contacta al administrador.');
        $request->attributes->set('portal_tenant', $tenant);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
