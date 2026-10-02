<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\Client;
use App\Models\CourierImportError;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ClientMaintainerController
{
    public function create(Request $request): View
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $merchantName = trim((string) $request->query('merchant'));
        if (! $request->boolean('new') && $merchantName === '') {
            $clients = Client::query()->where('tenant_id', $tenant->id)->withCount('courierMovements')->orderBy('legal_name')->get();
            $totalMovements = $clients->sum('courier_movements_count');

            return view('provider-payments::clients-index', [
                'clients' => $clients,
                'activeClients' => $clients->where('is_active', true)->count(),
                'totalMovements' => $totalMovements,
            ]);
        }

        return view('provider-payments::client-create', [
            'merchantName' => $merchantName,
            'templates' => Client::query()->where('tenant_id', $tenant->id)->where('is_active', true)
                ->orderBy('legal_name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $validated = $request->validate([
            'source_merchant_name' => ['required', 'string', 'max:255'],
            'tax_id' => ['required', 'string', 'max:15'],
            'legal_name' => ['required', 'string', 'max:255'],
            'commercial_name' => ['nullable', 'required_without:template_id', 'string', 'max:160'],
            'billing_company_code' => ['nullable', 'string', 'max:20'],
            'billing_address' => ['nullable', 'string', 'max:255'],
            'billing_commune_name' => ['nullable', 'string', 'max:100'],
            'business_activity' => ['nullable', 'string'],
            'template_id' => [
                'nullable',
                'integer',
                Rule::exists('MBA_clients', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)->where('is_active', true)),
            ],
        ]);

        [$taxId, $taxIdNumber, $checkDigit] = $this->validatedTaxId($validated['tax_id']);
        if (Client::query()->where('tenant_id', $tenant->id)->where('tax_id_number', $taxIdNumber)->exists()) {
            throw ValidationException::withMessages(['tax_id' => 'Este RUT ya pertenece a otro cliente de 4N.']);
        }

        $template = isset($validated['template_id'])
            ? Client::query()->where('tenant_id', $tenant->id)->whereKey($validated['template_id'])->firstOrFail()
            : null;
        $client = $template?->replicate() ?? new Client;
        $client->tenant_id = $tenant->id;
        $client->tax_id = $taxId;
        $client->tax_id_number = $taxIdNumber;
        $client->tax_id_check_digit = $checkDigit;
        $client->source_merchant_name = trim($validated['source_merchant_name']);
        $client->legal_name = trim($validated['legal_name']);
        foreach (['commercial_name', 'billing_company_code', 'billing_address', 'billing_commune_name', 'business_activity'] as $field) {
            if (array_key_exists($field, $validated)) {
                $client->{$field} = filled($validated[$field]) ? trim($validated[$field]) : null;
            }
        }
        $client->is_active = true;
        $client->save();

        $snapshot = $request->session()->get('courier_review');
        if ($snapshot) {
            CourierImportError::query()->where('batch_id', $snapshot['batch_id'])->where('category', 'clients')
                ->where('source_key', $client->source_merchant_name)->update(['status' => 'RESUELTO', 'exclude_from_import' => false]);
        }

        $route = $snapshot ? 'provider-payments.courier-movements.review-parameters' : 'provider-payments.maintainers.clientes';

        return redirect()->route($route)->with('status', "Cliente {$client->legal_name} creado con RUT {$client->tax_id}.");
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        abort_unless($client->tenant_id === $tenant->id, 404);
        $client->update($request->validate([
            'source_merchant_name' => ['required', 'string', 'max:255'], 'legal_name' => ['required', 'string', 'max:255'],
            'commercial_name' => ['required', 'string', 'max:160'], 'billing_company_code' => ['nullable', 'string', 'max:20'],
            'billing_address' => ['nullable', 'string', 'max:255'], 'billing_commune_name' => ['nullable', 'string', 'max:100'],
            'business_activity' => ['nullable', 'string'], 'is_active' => ['required', 'boolean'],
        ]));

        return back()->with('status', "Cliente {$client->tax_id} actualizado sin modificar su RUT.");
    }

    /** @return array{string, string, string} */
    private function validatedTaxId(string $value): array
    {
        $clean = strtoupper((string) preg_replace('/[^0-9K]/i', '', $value));
        if (! preg_match('/^(\d{7,8})([0-9K])$/', $clean, $matches)) {
            throw ValidationException::withMessages(['tax_id' => 'Ingresa un RUT válido, por ejemplo 77346078-7.']);
        }

        $number = $matches[1];
        $checkDigit = $matches[2];
        $sum = 0;
        $multiplier = 2;
        for ($position = strlen($number) - 1; $position >= 0; $position--) {
            $sum += ((int) $number[$position]) * $multiplier;
            $multiplier = $multiplier === 7 ? 2 : $multiplier + 1;
        }
        $expected = 11 - ($sum % 11);
        $expectedDigit = $expected === 11 ? '0' : ($expected === 10 ? 'K' : (string) $expected);
        if ($checkDigit !== $expectedDigit) {
            throw ValidationException::withMessages(['tax_id' => 'El dígito verificador del RUT no es correcto.']);
        }

        return ["{$number}-{$checkDigit}", $number, $checkDigit];
    }
}
