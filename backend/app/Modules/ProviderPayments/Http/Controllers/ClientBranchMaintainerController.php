<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\Client;
use App\Models\ClientBranch;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientBranchMaintainerController
{
    public function index(): View
    {
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $clients = Client::where('tenant_id', $tenant->id)->orderBy('legal_name')->get();

        return view('provider-payments::branches-index', ['clients' => $clients, 'branches' => ClientBranch::with('client')->whereIn('client_id', $clients->pluck('id'))->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = Tenant::where('code', '4N')->firstOrFail();
        $clientIds = Client::where('tenant_id', $tenant->id)->pluck('id');
        $data = $request->validate([
            'client_id' => ['required', Rule::in($clientIds->all())], 'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:160'], 'branch_type' => ['required', 'string', 'max:50'],
            'address' => ['required', 'string', 'max:255'], 'commune_name' => ['required', 'string', 'max:100'],
            'region_name' => ['nullable', 'string', 'max:100'], 'service_schedule' => ['nullable', 'string', 'max:255'],
        ]);
        $request->validate(['code' => [Rule::unique('client_branches')->where('client_id', $data['client_id'])]]);
        ClientBranch::create([...$data, 'is_active' => true]);

        return back()->with('status', 'Sucursal creada correctamente.');
    }

    public function update(Request $request, ClientBranch $branch): RedirectResponse
    {
        abort_unless($branch->client?->tenant?->code === '4N', 404);
        $branch->update($request->validate([
            'name' => ['required', 'string', 'max:160'], 'branch_type' => ['required', 'string', 'max:50'],
            'address' => ['required', 'string', 'max:255'], 'commune_name' => ['required', 'string', 'max:100'],
            'region_name' => ['nullable', 'string', 'max:100'], 'service_schedule' => ['nullable', 'string', 'max:255'], 'is_active' => ['required', 'boolean'],
        ]));

        return back()->with('status', 'Sucursal actualizada; cliente y código permanecieron protegidos.');
    }
}
