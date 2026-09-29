<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProviderPaymentsAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isProviderPaymentsAdministrator(), 403, 'Solo los administradores pueden acceder a Pago Proveedores.');

        return $next($request);
    }
}
