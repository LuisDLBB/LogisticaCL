<?php

namespace App\Http\Middleware;

use App\Fleet\FleetAccess;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class FleetLocalAdminLogin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $email = config('fleet.local_admin_email');
        $remoteAddress = $request->server('REMOTE_ADDR');

        if (config('app.env') === 'local'
            && is_string($email)
            && $email !== ''
            && in_array($request->getHost(), ['localhost', '127.0.0.1', '::1'], true)
            && in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
            $user = User::query()->where('email', $email)->first();

            if ($user !== null && app(FleetAccess::class)->canEnter($user) && Auth::id() !== $user->id) {
                Auth::login($user);
            }
        }

        return $next($request);
    }
}
