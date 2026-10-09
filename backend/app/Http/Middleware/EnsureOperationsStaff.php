<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOperationsStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->attributes->get('portal_tenant')?->pivot?->role_code === 'driver', 403, 'Tu acceso de chofer solo permite consultar tus rutas.');

        return $next($request);
    }
}
