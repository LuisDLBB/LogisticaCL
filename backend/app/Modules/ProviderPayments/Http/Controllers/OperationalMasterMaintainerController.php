<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\Banco;
use App\Models\CourierStatus;
use App\Models\Provider;
use App\Models\Tenant;
use App\Models\TipoCuentaBancaria;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OperationalMasterMaintainerController
{
    public function providers(): View
    {
        return view('provider-payments::providers-index', [
            'providers' => Provider::with('bankAccounts')->where('tenant_id', $this->tenant()->id)->orderBy('legal_name')->get(),
            'banks' => Banco::query()->where('is_active', true)->orderBy('banco')->get(),
            'accountTypes' => TipoCuentaBancaria::query()->where('is_active', true)->orderBy('id_tipo_cuenta')->get(),
        ]);
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
            'bank_name' => ['nullable', 'string', 'max:100', 'required_with:account_number', Rule::exists('bancos', 'banco')->where('is_active', true)],
            'account_type' => ['nullable', 'string', 'max:80', 'required_with:account_number', Rule::exists('tipos_cuenta_bancaria', 'tipo_cuenta')->where('is_active', true)],
            'account_number' => ['nullable', 'string', 'max:100', 'required_with:bank_name,account_type'],
        ]);
        [$number, $digit] = $this->rutParts($data['tax_id']);
        $provider = Provider::create([...Arr::except($data, ['bank_name', 'account_type', 'account_number']), 'tenant_id' => $tenant->id, 'tax_id' => $number.'-'.$digit, 'tax_id_number' => $number, 'tax_id_check_digit' => $digit, 'is_active' => true]);

        if (filled($data['account_number'] ?? null)) {
            $provider->bankAccounts()->create([
                'account_holder_name' => $provider->legal_name,
                'account_holder_tax_id' => $provider->tax_id,
                'bank_name' => $data['bank_name'],
                'account_type' => $data['account_type'],
                'account_number' => $data['account_number'],
                'is_primary' => true,
                'is_active' => true,
            ]);
        }

        return back()->with('status', 'Proveedor creado correctamente.');
    }

    public function updateProvider(Request $request, Provider $provider): RedirectResponse
    {
        $this->guardTenant($provider->tenant_id);
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'], 'operational_name' => ['nullable', 'string', 'max:160'],
            'operator_type' => ['required', 'string', 'max:20'], 'tax_document_type' => ['nullable', 'string', 'max:80'],
            'commercial_address' => ['nullable', 'string', 'max:255'], 'commercial_commune_name' => ['nullable', 'string', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:160'], 'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:160'], 'is_active' => ['required', 'boolean'],
            'bank_name' => ['nullable', 'string', 'max:100', Rule::exists('bancos', 'banco')->where('is_active', true)],
            'account_type' => ['nullable', 'string', 'max:80', Rule::exists('tipos_cuenta_bancaria', 'tipo_cuenta')->where('is_active', true)],
            'account_number' => ['nullable', 'string', 'max:100'],
        ]);
        $provider->update(Arr::except($data, ['bank_name', 'account_type', 'account_number']));

        $bankAccount = $provider->bankAccounts()->where('is_primary', true)->first()
            ?? $provider->bankAccounts()->first();

        if ($bankAccount && filled($data['bank_name'] ?? null) && filled($data['account_type'] ?? null)) {
            $bankAccountData = [
                'account_holder_name' => $provider->legal_name,
                'account_holder_tax_id' => $provider->tax_id,
                'bank_name' => $data['bank_name'],
                'account_type' => $data['account_type'],
                'is_primary' => true,
                'is_active' => true,
            ];
            if (filled($data['account_number'] ?? null)) {
                $bankAccountData['account_number'] = $data['account_number'];
            }
            $bankAccount->update($bankAccountData);
        } elseif (filled($data['account_number'] ?? null) && filled($data['bank_name'] ?? null) && filled($data['account_type'] ?? null)) {
            $provider->bankAccounts()->create([
                'account_holder_name' => $provider->legal_name,
                'account_holder_tax_id' => $provider->tax_id,
                'bank_name' => $data['bank_name'],
                'account_type' => $data['account_type'],
                'account_number' => $data['account_number'],
                'is_primary' => true,
                'is_active' => true,
            ]);
        }

        return back()->with('status', "Proveedor {$provider->tax_id} actualizado sin modificar su llave.");
    }

    public function banks(): View
    {
        return view('provider-payments::banks-index', [
            'banks' => Banco::query()->orderBy('codigo_sbif')->get(),
            'accountTypes' => TipoCuentaBancaria::query()->orderBy('id_tipo_cuenta')->get(),
        ]);
    }

    public function storeBank(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'banco' => ['required', 'string', 'max:100', Rule::unique('bancos', 'banco')],
            'codigo_sbif' => ['required', 'integer', 'min:1', 'max:65535', Rule::unique('bancos', 'codigo_sbif')],
            'nombre_entidad_financiera' => ['required', 'string', 'max:180'],
            'marcas_productos_asociados' => ['required', 'string', 'max:255'],
        ]);
        Banco::query()->create([...$data, 'id_banco' => ((int) Banco::query()->max('id_banco')) + 1, 'is_active' => true]);

        return back()->with('status', 'Banco creado correctamente.');
    }

    public function updateBank(Request $request, Banco $banco): RedirectResponse
    {
        $banco->update($request->validate([
            'banco' => ['required', 'string', 'max:100', Rule::unique('bancos', 'banco')->ignore($banco)],
            'nombre_entidad_financiera' => ['required', 'string', 'max:180'],
            'marcas_productos_asociados' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]));

        return back()->with('status', "Banco {$banco->codigo_sbif} actualizado sin modificar su código SBIF.");
    }

    public function storeBankAccountType(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tipo_cuenta' => ['required', 'string', 'max:80', Rule::unique('tipos_cuenta_bancaria', 'tipo_cuenta')],
        ]);
        TipoCuentaBancaria::query()->create([
            ...$data,
            'id_tipo_cuenta' => ((int) TipoCuentaBancaria::query()->max('id_tipo_cuenta')) + 1,
            'is_active' => true,
        ]);

        return back()->with('status', 'Tipo de cuenta bancaria creado correctamente.');
    }

    public function updateBankAccountType(Request $request, TipoCuentaBancaria $accountType): RedirectResponse
    {
        $accountType->update($request->validate([
            'tipo_cuenta' => ['required', 'string', 'max:80', Rule::unique('tipos_cuenta_bancaria', 'tipo_cuenta')->ignore($accountType)],
            'is_active' => ['required', 'boolean'],
        ]));

        return back()->with('status', 'Tipo de cuenta bancaria actualizado correctamente.');
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
