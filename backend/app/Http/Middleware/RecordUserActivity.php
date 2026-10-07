<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RecordUserActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($response->getStatusCode() >= 400 || $request->session()->has('errors') || $request->isMethod('HEAD')) {
            return $response;
        }
        $module = $request->routeIs('provider-payments.*') ? 'Pago Proveedores' : ($request->routeIs('operations.*') ? 'Operaciones' : ['comercial' => 'Comercial', 'post-venta' => 'Post Venta', 'flota' => 'Flota', 'operaciones' => 'Operaciones', 'finanzas' => 'Finanzas'][$request->route('module')] ?? 'Inicio');
        $action = $request->isMethod('GET') ? 'Consulta de '.$module : 'Acción realizada en '.$module;
        DB::table('user_activities')->insert([
            'user_id' => $request->user()->id, 'tenant_id' => $request->attributes->get('portal_tenant')->id,
            'module' => $module, 'action' => $action, 'created_at' => now(),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
