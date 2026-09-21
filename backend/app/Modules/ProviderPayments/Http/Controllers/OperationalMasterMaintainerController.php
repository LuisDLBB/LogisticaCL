<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\CourierStatus;
use App\Models\Provider;
use App\Models\ProviderBankAccount;
use App\Models\Tenant;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OperationalMasterMaintainerController
{
    public function providers(): View
    {
        return view('provider-payments::providers-index', ['providers' => Provider::where('tenant_id', $this->tenant()->id)->orderBy('legal_name')->get()]);
    }

    public function storeProvider(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'tax_id' => ['required', 'string', 'max:15', Rule::unique('providers')->where('tenant_id', $tenant->id)],
            'legal_name' => ['required', 'string', 'max:255'], 'operational_name' => ['nullable', 'string', 'max:160'],
            'operator_type' => ['required', 'string', 'max:20'], 'tax_document_type' => ['nullable', 'string', 'max:80'],
            'commercial_address' => ['nullable', 'string', 'max:255'], 'commercial_commune_name' => ['nullable', 'string', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:160'], 'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:160'],
        ]);
        [$number, $digit] = $this->rutParts($data['tax_id']);
        Provider::create([...$data, 'tenant_id' => $tenant->id, 'tax_id' => $number.'-'.$digit, 'tax_id_number' => $number, 'tax_id_check_digit' => $digit, 'is_active' => true]);

        return back()->with('status', 'Proveedor creado correctamente.');
    }

    public function updateProvider(Request $request, Provider $provider): RedirectResponse
    {
        $this->guardTenant($provider->tenant_id);
        $provider->update($request->validate([
            'legal_name' => ['required', 'string', 'max:255'], 'operational_name' => ['nullable', 'string', 'max:160'],
            'operator_type' => ['required', 'string', 'max:20'], 'tax_document_type' => ['nullable', 'string', 'max:80'],
            'commercial_address' => ['nullable', 'string', 'max:255'], 'commercial_commune_name' => ['nullable', 'string', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:160'], 'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:160'], 'is_active' => ['required', 'boolean'],
        ]));

        return back()->with('status', "Proveedor {$provider->tax_id} actualizado sin modificar su llave.");
    }

    public function banks(): View
    {
        $providers = Provider::where('tenant_id', $this->tenant()->id)->orderBy('legal_name')->get();

        return view('provider-payments::banks-index', ['providers' => $providers, 'accounts' => ProviderBankAccount::with('provider')->whereIn('provider_id', $providers->pluck('id'))->latest()->get()]);
    }

    public function storeBank(Request $request): RedirectResponse
    {
        $providerIds = Provider::where('tenant_id', $this->tenant()->id)->pluck('id');
        $data = $request->validate([
            'provider_id' => ['required', Rule::in($providerIds->all())], 'account_holder_name' => ['required', 'string', 'max:255'],
            'account_holder_tax_id' => ['required', 'string', 'max:15'], 'bank_name' => ['required', 'string', 'max:100'],
            'account_type' => ['required', 'string', 'max:80'], 'account_number' => ['required', 'string', 'max:100'],
            'is_primary' => ['nullable', 'boolean'],
        ]);
        ProviderBankAccount::create([...$data, 'is_primary' => (bool) ($data['is_primary'] ?? false), 'is_active' => true]);

        return back()->with('status', 'Cuenta bancaria creada correctamente.');
    }

    public function updateBank(Request $request, ProviderBankAccount $account): RedirectResponse
    {
        $this->guardTenant($account->provider()->value('tenant_id'));
        $account->update($request->validate([
            'account_holder_name' => ['required', 'string', 'max:255'], 'bank_name' => ['required', 'string', 'max:100'],
            'account_type' => ['required', 'string', 'max:80'], 'is_primary' => ['required', 'boolean'], 'is_active' => ['required', 'boolean'],
        ]));

        return back()->with('status', 'Datos bancarios actualizados; la cuenta y el RUT permanecieron protegidos.');
    }

    public function vehicles(): View
    {
        return view('provider-payments::vehicles-index', ['vehicles' => Vehicle::where('tenant_id', $this->tenant()->id)->orderBy('internal_code')->get()]);
    }

    public function storeVehicle(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'rut_empresa' => ['required', 'string', 'max:15'], 'internal_code' => ['required', 'string', 'max:50', Rule::unique('vehicles')->where('tenant_id', $tenant->id)],
            'plate' => ['required', 'string', 'max:12', Rule::unique('vehicles')->where('tenant_id', $tenant->id)], 'vehicle_type' => ['required', 'string', 'max:50'],
            'ownership_type' => ['required', 'string', 'max:30'], 'operational_status' => ['required', 'string', 'max:30'],
            'brand' => ['nullable', 'string', 'max:80'], 'model' => ['nullable', 'string', 'max:100'], 'manufacture_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
        ]);
        Vehicle::create([...$data, 'tenant_id' => $tenant->id, 'plate' => strtoupper($data['plate']), 'is_active' => true]);

        return back()->with('status', 'Vehículo creado correctamente.');
    }

    public function updateVehicle(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->guardTenant($vehicle->tenant_id);
        $vehicle->update($request->validate([
            'vehicle_type' => ['required', 'string', 'max:50'], 'ownership_type' => ['required', 'string', 'max:30'],
            'operational_status' => ['required', 'string', 'max:30'], 'brand' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:100'], 'manufacture_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'color' => ['nullable', 'string', 'max:40'], 'odometer_km' => ['nullable', 'integer', 'min:0'], 'notes' => ['nullable', 'string'], 'is_active' => ['required', 'boolean'],
        ]));

        return back()->with('status', "Vehículo {$vehicle->plate} actualizado sin modificar sus llaves.");
    }

    public function statuses(): View
    {
        return view('provider-payments::statuses-index', ['statuses' => CourierStatus::orderBy('name')->get()]);
    }

    public function storeStatus(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('estados', 'name')], 'consider_for_payment' => ['required', 'boolean']]);
        CourierStatus::create($data);

        return back()->with('status', 'Estado creado correctamente.');
    }

    public function updateStatus(Request $request, CourierStatus $status): RedirectResponse
    {
        $status->update($request->validate(['consider_for_payment' => ['required', 'boolean']]));

        return back()->with('status', "Condición de pago de {$status->name} actualizada; el nombre permaneció protegido.");
    }

    private function tenant(): Tenant
    {
        return Tenant::where('code', '4N')->firstOrFail();
    }

    private function guardTenant(?int $tenantId): void
    {
        abort_unless($tenantId === $this->tenant()->id, 404);
    }

    private function rutParts(string $rut): array
    {
        $clean = strtoupper(preg_replace('/[^0-9K]/i', '', $rut));

        return [substr($clean, 0, -1), substr($clean, -1)];
    }
}
