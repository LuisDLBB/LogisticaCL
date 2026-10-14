<?php

namespace App\Http\Middleware;

use App\Fleet\FleetAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireFleetPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permissionCode, int $requiredLevel = 1): Response
    {
        $tenant = app(FleetAccess::class)->tenant($request);
        abort_unless($tenant !== null && $request->user() !== null, 403);
        abort_unless(app(FleetAccess::class)->allows($request->user(), $tenant, $permissionCode, $requiredLevel), 403);

        return $next($request);
    }
}
