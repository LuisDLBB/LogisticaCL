<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\FleetAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function switch(Request $request, FleetAccess $access): RedirectResponse
    {
        $validated = $request->validate(['tenant_id' => ['required', 'integer']]);
        $tenant = $access->availableTenants($request->user())->firstWhere('id', (int) $validated['tenant_id']);
        abort_unless($tenant !== null, 403);

        $request->session()->put('fleet_tenant_id', $tenant->id);

        return redirect()->route('fleet.home');
    }
}
