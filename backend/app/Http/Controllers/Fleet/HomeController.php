<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\FleetAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(Request $request, FleetAccess $access): View
    {
        $tenant = $access->tenant($request);
        $visiblePages = collect(FleetAccess::PAGES)
            ->filter(fn (array $page, string $code): bool => $access->allows($request->user(), $tenant, $code));

        return view('fleet::home', compact('tenant', 'visiblePages'));
    }

    public function placeholder(Request $request, FleetAccess $access, string $permissionCode): View
    {
        abort_unless(array_key_exists($permissionCode, FleetAccess::PAGES), 404);

        return view('fleet::placeholder', [
            'tenant' => $access->tenant($request),
            'page' => FleetAccess::PAGES[$permissionCode],
        ]);
    }
}
