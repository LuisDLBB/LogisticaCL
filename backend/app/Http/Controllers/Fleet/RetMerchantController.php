<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\FleetAccess;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FixedPickup;
use App\Models\ServiceRequest;
use App\Models\ServiceType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RetMerchantController extends Controller
{
    public function index(Request $request, FleetAccess $access): View
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'state' => ['nullable', 'in:active,inactive']]);
        $clients = Client::where('tenant_id', $access->tenant($request)->id)->withCount('branches')
            ->when($data['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('commercial_name', 'like', '%'.$term.'%')->orWhere('legal_name', 'like', '%'.$term.'%')->orWhere('tax_id', 'like', '%'.$term.'%')))
            ->when($data['state'] ?? null, fn ($q, $state) => $q->where('is_active', $state === 'active'))
            ->orderBy('commercial_name')->paginate(15)->withQueryString();

        return view('fleet::merchants.index', compact('clients', 'data'));
    }

    public function show(Request $request, Client $client, FleetAccess $access): View
    {
        abort_unless($client->tenant_id === $access->tenant($request)->id, 404);
        $client->load('branches.contacts', 'serviceTypes');
        $points = $client->branches;
        $points->each(fn ($point) => $point->setRelation('client', $client));
        $types = ServiceType::where('is_active', true)->get();
        $configured = $client->serviceTypes->filter(fn ($type) => $type->pivot->is_active)->modelKeys();
        $fixed = FixedPickup::where('client_id', $client->id)->get();
        $history = ServiceRequest::where('tenant_id', $client->tenant_id)->where('client_id', $client->id)->latest('id')->limit(20)->get();
        $canEdit = $access->allows($request->user(), $access->tenant($request), 'admin.masters', 3);

        return view('fleet::merchants.show', compact('client', 'points', 'types', 'configured', 'fixed', 'history', 'canEdit'));
    }

    public function update(Request $request, Client $client, FleetAccess $access): RedirectResponse
    {
        abort_unless($client->tenant_id === $access->tenant($request)->id, 404);
        $data = $request->validate(['commercial_name' => ['required', 'string', 'max:255'], 'legal_name' => ['required', 'string', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:255'], 'billing_commune_name' => ['nullable', 'string', 'max:100'],
            'business_activity' => ['nullable', 'string', 'max:255'], 'is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($client, $data, $request, $access): void {
            $before = $client->only(array_keys($data));
            $client->update($data);
            $access->audit($request->user(), $access->tenant($request), null, 'merchant_updated', $before, ['client_id' => $client->id] + $data);
        });

        return back()->with('status', 'Hoja de vida actualizada. Los retiros históricos conservan sus antecedentes.');
    }
}
