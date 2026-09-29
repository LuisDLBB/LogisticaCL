<?php

use App\Http\Middleware\FleetLocalAdminLogin;
use App\Http\Middleware\RequireFleetPermission;
use App\Http\Middleware\ResolveFleetTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToGroup('web', FleetLocalAdminLogin::class);
        $middleware->redirectGuestsTo('/control-flota/ingresar');
        $middleware->redirectUsersTo('/control-flota');
        $middleware->alias([
            'fleet.tenant' => ResolveFleetTenant::class,
            'fleet.permission' => RequireFleetPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
