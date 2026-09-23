<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\Client;
use App\Models\CostCenter;
use App\Models\CostCenterKey;
use App\Models\CourierImportError;
use App\Models\Provider;
use App\Models\ServiceType;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CostCenterKeyMaintainerController
{
    public function create(Request $request): View
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $merchantName = trim((string) $request->query('merchant'));
        $serviceName = trim((string) $request->query('service'));
        if ($merchantName === '' || $serviceName === '') {
            $keys = CostCenterKey::query()->where('tenant_id', $tenant->id)
                ->with(['provider', 'client', 'serviceType', 'costCenter'])
                ->orderBy('merchant_name')->orderBy('service_name')->orderBy('provider_tax_id')->get();
            $sourceProviders = Provider::query()->where('tenant_id', $tenant->id)
                ->where(function ($query) use ($keys): void {
                    $query->whereIn('id', $keys->pluck('provider_id')->filter()->unique())
                        ->orWhereIn('tax_id', $keys->pluck('provider_tax_id')->filter()->unique());
                })->orderBy('legal_name')->get();
            $selectedClient = trim((string) $request->query('client'));
            $selectedProvider = trim((string) $request->query('provider'));
            $selectedCenter = trim((string) $request->query('center'));
            $selectedPayment = trim((string) $request->query('payment'));
            $viewMode = $request->query('vista') === 'cliente' ? 'cliente' : 'proveedor';
            $matches = fn (CostCenterKey $key, string $except = ''): bool =>
                ($except === 'client' || $selectedClient === '' || $this->clientFilterKey($key) === $selectedClient)
                && ($except === 'provider' || $selectedProvider === '' || $this->providerFilterKey($key) === $selectedProvider)
                && ($except === 'center' || $selectedCenter === '' || ($key->cost_center_code === null ? 'none' : (string) $key->cost_center_code) === $selectedCenter)
                && ($except === 'payment' || $selectedPayment === '' || mb_strtoupper(trim((string) $key->payment_status)) === $selectedPayment);
            $clientOptions = $keys->filter(fn (CostCenterKey $key): bool => $matches($key, 'client'))
                ->groupBy(fn (CostCenterKey $key): string => $this->clientFilterKey($key))
                ->map(fn ($group, $value): array => ['value' => $value, 'label' => $group->first()->client?->source_merchant_name ?: $group->first()->merchant_name])
                ->filter(fn (array $option): bool => filled($option['value']))->sortBy('label')->values();
            $providerOptions = $keys->filter(fn (CostCenterKey $key): bool => $matches($key, 'provider'))
                ->groupBy(fn (CostCenterKey $key): string => $this->providerFilterKey($key))
                ->map(fn ($group, $value): array => ['value' => $value, 'label' => $group->first()->provider?->legal_name ?: ($group->first()->agent_name ?: $group->first()->provider_tax_id)])
                ->filter(fn (array $option): bool => filled($option['value']))->sortBy('label')->values();
            $centerOptions = $keys->filter(fn (CostCenterKey $key): bool => $matches($key, 'center'))
                ->groupBy(fn (CostCenterKey $key): string => $key->cost_center_code === null ? 'none' : (string) $key->cost_center_code)
                ->map(fn ($group, $value): array => ['value' => (string) $value, 'label' => $value === 'none' ? 'Sin centro de costo' : $value.' · '.($group->first()->costCenter?->dispatch_guide_detail ?? 'Centro de costo')])
                ->sortBy('label')->values();
            $paymentOptions = $keys->filter(fn (CostCenterKey $key): bool => $matches($key, 'payment'))
                ->pluck('payment_status')->map(fn ($value): string => mb_strtoupper(trim((string) $value)))
                ->filter()->unique()->sort()->values();
            $paymentStatuses = $keys->pluck('payment_status')->map(fn ($value): string => mb_strtoupper(trim((string) $value)))
                ->merge(['SI', 'NO', 'REVISAR'])->filter()->unique()->sort()->values();
            $filteredKeys = $keys->filter(fn (CostCenterKey $key): bool => $matches($key));
            $filteredKeys = $filteredKeys->sortBy(fn (CostCenterKey $key): string => $viewMode === 'proveedor'
                ? $this->providerFilterKey($key).'|'.$this->clientFilterKey($key).'|'.$key->service_name
                : $this->clientFilterKey($key).'|'.$this->providerFilterKey($key).'|'.$key->service_name)->values();
            $page = max(1, (int) $request->query('page', 1));
            $rows = new LengthAwarePaginator($filteredKeys->forPage($page, 100)->values(), $filteredKeys->count(), 100, $page, [
                'path' => $request->url(), 'query' => $request->query(),
            ]);

            return view('provider-payments::cost-center-keys-index', [
                'keys' => $keys,
                'rows' => $rows,
                'viewMode' => $viewMode,
                'selectedClient' => $selectedClient,
                'selectedProvider' => $selectedProvider,
                'selectedCenter' => $selectedCenter,
                'selectedPayment' => $selectedPayment,
                'clientOptions' => $clientOptions,
                'providerOptions' => $providerOptions,
                'centerOptions' => $centerOptions,
                'paymentOptions' => $paymentOptions,
                'paymentStatuses' => $paymentStatuses,
                'total' => $filteredKeys->count(),
                'allTotal' => $keys->count(),
                'providers' => Provider::query()->where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('legal_name')->get(),
                'sourceProviders' => $sourceProviders,
                'clients' => Client::query()->where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('source_merchant_name')->get(),
                'services' => ServiceType::query()->where('is_active', true)->orderBy('name')->get(),
                'costCenters' => CostCenter::query()->where('is_active', true)->orderBy('cost_center_code')->get(),
            ]);
        }
        $service = ServiceType::query()->where('is_active', true)->where('name', $serviceName)->first();
        $templateRows = $service
            ? CostCenterKey::query()->where('tenant_id', $tenant->id)->where('is_active', true)->where('service_code', $service->service_code)
                ->orderBy('merchant_name')->orderBy('agent_name')->get()
            : collect();
        $templateGroups = $templateRows->groupBy(fn (CostCenterKey $key): string => $key->merchant_name.'|'.$key->service_code)
            ->map(fn ($rows) => (object) [
                'template_id' => $rows->first()->id,
                'merchant_name' => $rows->first()->merchant_name,
                'service_code' => $rows->first()->service_code,
                'service_name' => $rows->first()->service_name,
                'record_count' => $rows->count(),
            ])->values();

        return view('provider-payments::cost-center-key-create', [
            'merchantName' => $merchantName,
            'serviceName' => $serviceName,
            'targetClientRut' => Client::query()->where('tenant_id', $tenant->id)->where('source_merchant_name', $merchantName)->value('tax_id') ?? 'Cliente pendiente',
            'templates' => $templateGroups,
            'templateRows' => $templateGroups->mapWithKeys(function ($group) use ($templateRows): array {
                $rows = $templateRows->where('merchant_name', $group->merchant_name)->where('service_code', $group->service_code)
                    ->map(fn (CostCenterKey $key): array => [
                        'source_id' => $key->id,
                        'provider_tax_id' => $key->provider_tax_id,
                        'agent_name' => $key->agent_name,
                        'payment_status' => $key->payment_status,
                        'cost_center_code' => $key->cost_center_code,
                    ])->values();

                return [(string) $group->template_id => $rows];
            }),
            'costCenters' => CostCenter::query()->where('is_active', true)->orderBy('cost_center_code')
                ->get(['cost_center_code', 'dispatch_guide_detail']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $validated = $request->validate([
            'merchant_name' => ['required', 'string', 'max:255'],
            'service_name' => ['required', 'string', 'max:160'],
            'template_id' => [
                'required',
                'integer',
                Rule::exists('llave_centro_costos', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)->where('is_active', true)),
            ],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.source_id' => ['required', 'integer'],
            'rows.*.provider_tax_id' => ['nullable', 'string', 'max:15'],
            'rows.*.agent_name' => ['nullable', 'string', 'max:160'],
            'rows.*.payment_status' => ['required', 'string', 'max:20'],
            'rows.*.cost_center_code' => ['nullable', 'integer', Rule::exists('cost_centers', 'cost_center_code')],
        ]);

        $client = Client::query()->where('tenant_id', $tenant->id)->where('is_active', true)
            ->whereRaw('LOWER(TRIM(source_merchant_name)) = ?', [mb_strtolower(trim($validated['merchant_name']))])->first();
        $service = ServiceType::query()->where('is_active', true)->where('name', $validated['service_name'])->first();
        if (! $client) {
            throw ValidationException::withMessages(['merchant_name' => 'Primero debes crear o corregir este cliente en el maestro de clientes.']);
        }
        if (! $service) {
            throw ValidationException::withMessages(['service_name' => 'Este servicio no existe en el catálogo de servicios.']);
        }

        $representative = CostCenterKey::query()->where('tenant_id', $tenant->id)->whereKey($validated['template_id'])->firstOrFail();
        if ((int) $representative->service_code !== (int) $service->service_code) {
            throw ValidationException::withMessages(['template_id' => 'La combinación seleccionada debe corresponder al mismo ID Servicio.']);
        }
        $templateRows = CostCenterKey::query()->where('tenant_id', $tenant->id)->where('is_active', true)
            ->where('merchant_name', $representative->merchant_name)
            ->where('service_code', $representative->service_code)
            ->get()->keyBy('id');
        $submittedSourceIds = collect($validated['rows'])->pluck('source_id')->map(fn ($id): int => (int) $id);
        if ($submittedSourceIds->count() !== $templateRows->count() || $submittedSourceIds->diff($templateRows->keys())->isNotEmpty()) {
            throw ValidationException::withMessages(['rows' => 'La vista previa cambió. Selecciona nuevamente la combinación para cargar todos sus registros.']);
        }

        DB::transaction(function () use ($client, $service, $templateRows, $validated): void {
            CostCenterKey::query()
                ->where('tenant_id', $client->tenant_id)
                ->where('service_code', $service->service_code)
                ->where(function ($query) use ($client): void {
                    $query->where('client_id', $client->id)
                        ->orWhereRaw('LOWER(TRIM(merchant_name)) = ?', [mb_strtolower(trim($client->source_merchant_name))]);
                })
                ->delete();
            foreach ($validated['rows'] as $submittedRow) {
                $template = $templateRows->get((int) $submittedRow['source_id']);
                $key = $template->replicate();
                $key->tenant_id = $client->tenant_id;
                $key->provider_tax_id = filled($submittedRow['provider_tax_id'] ?? null) ? strtoupper(trim($submittedRow['provider_tax_id'])) : null;
                $key->provider_id = $key->provider_tax_id
                    ? Provider::query()->where('tenant_id', $client->tenant_id)->where('tax_id', $key->provider_tax_id)->value('id')
                    : null;
                $key->agent_name = filled($submittedRow['agent_name'] ?? null) ? trim($submittedRow['agent_name']) : null;
                $key->payment_status = strtoupper(trim($submittedRow['payment_status']));
                $key->cost_center_code = $submittedRow['cost_center_code'] ?? null;
                $key->client_id = $client->id;
                $key->client_tax_id = $client->tax_id;
                $key->merchant_name = $client->source_merchant_name;
                $key->service_type_id = $service->id;
                $key->service_code = $service->service_code;
                $key->service_name = $service->name;
                $key->key_code = implode('/', [$key->provider_tax_id, $client->tax_id, $service->service_code]);
                $key->key_text = trim((string) $key->agent_name).$client->source_merchant_name.$service->name;
                $key->is_active = true;
                $key->save();
            }

            DB::table('client_service_type')->updateOrInsert(
                ['client_id' => $client->id, 'service_type_id' => $service->id],
                ['is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        });

        $sourceKey = $validated['merchant_name'].' → '.$validated['service_name'];
        $snapshot = $request->session()->get('courier_review');
        if ($snapshot) {
            CourierImportError::query()->where('batch_id', $snapshot['batch_id'])->where('category', 'services')
                ->where('source_key', $sourceKey)->update(['status' => 'RESUELTO', 'exclude_from_import' => false]);
        }

        return redirect()->route('provider-payments.courier-movements.review-parameters')
            ->with('status', "Combinación {$sourceKey} creada con {$templateRows->count()} registros revisados de Llave Centro Costo.");
    }

    public function storeManual(Request $request): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $validated = $request->validate([
            'provider_id' => ['required', Rule::exists('providers', 'id')->where('tenant_id', $tenant->id)],
            'client_id' => ['required', Rule::exists('clients', 'id')->where('tenant_id', $tenant->id)],
            'service_type_id' => ['required', Rule::exists('service_types', 'id')],
            'agent_name' => ['nullable', 'string', 'max:160'], 'payment_status' => ['required', 'string', 'max:20'],
            'cost_center_code' => ['nullable', 'integer', Rule::exists('cost_centers', 'cost_center_code')],
        ]);
        $provider = Provider::findOrFail($validated['provider_id']);
        $client = Client::findOrFail($validated['client_id']);
        $service = ServiceType::findOrFail($validated['service_type_id']);
        $duplicate = CostCenterKey::query()->where('tenant_id', $tenant->id)->where('provider_id', $provider->id)
            ->where('client_id', $client->id)->where('service_type_id', $service->id)
            ->where('cost_center_code', $validated['cost_center_code'] ?? null)->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['provider_id' => 'Esta combinación de proveedor, cliente, servicio y centro de costo ya existe.']);
        }
        CostCenterKey::create([
            'tenant_id' => $tenant->id, 'provider_id' => $provider->id, 'client_id' => $client->id, 'service_type_id' => $service->id,
            'provider_tax_id' => $provider->tax_id, 'agent_name' => $validated['agent_name'] ?? null, 'client_tax_id' => $client->tax_id,
            'merchant_name' => $client->source_merchant_name, 'service_code' => $service->service_code, 'service_name' => $service->name,
            'key_code' => implode('/', [$provider->tax_id, $client->tax_id, $service->service_code]),
            'key_text' => trim((string) ($validated['agent_name'] ?? '')).$client->source_merchant_name.$service->name,
            'payment_status' => strtoupper($validated['payment_status']), 'cost_center_code' => $validated['cost_center_code'] ?? null, 'is_active' => true,
        ]);

        return back()->with('status', 'Llave Centro de Costo creada correctamente.');
    }

    public function replicateProvider(Request $request): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $validated = $request->validate([
            'source_provider_id' => ['required', Rule::exists('providers', 'id')->where('tenant_id', $tenant->id)],
            'target_provider_id' => ['required', 'different:source_provider_id', Rule::exists('providers', 'id')->where('tenant_id', $tenant->id)->where('is_active', true)],
        ]);
        $source = Provider::query()->where('tenant_id', $tenant->id)->findOrFail($validated['source_provider_id']);
        $target = Provider::query()->where('tenant_id', $tenant->id)->findOrFail($validated['target_provider_id']);
        $sourceKeys = CostCenterKey::query()->where('tenant_id', $tenant->id)
            ->where(fn ($query) => $query->where('provider_id', $source->id)->orWhere('provider_tax_id', $source->tax_id))
            ->orderBy('id')->get();
        if ($sourceKeys->isEmpty()) {
            throw ValidationException::withMessages(['source_provider_id' => 'El proveedor de origen no tiene llaves para copiar.']);
        }

        $created = 0;
        $skipped = 0;
        DB::transaction(function () use ($tenant, $target, $sourceKeys, &$created, &$skipped): void {
            $targetKeys = CostCenterKey::query()->where('tenant_id', $tenant->id)
                ->where(fn ($query) => $query->where('provider_id', $target->id)->orWhere('provider_tax_id', $target->tax_id))
                ->get();
            $existing = $targetKeys->mapWithKeys(fn (CostCenterKey $key): array => [$this->combinationIdentity($key) => true])->all();
            foreach ($sourceKeys as $sourceKey) {
                $identity = $this->combinationIdentity($sourceKey);
                if (isset($existing[$identity])) {
                    $skipped++;
                    continue;
                }
                $key = $sourceKey->replicate();
                $key->provider_id = $target->id;
                $key->provider_tax_id = $target->tax_id;
                $key->key_code = implode('/', [$target->tax_id, $key->client_tax_id, $key->service_code]);
                $key->key_text = trim((string) $key->agent_name).$key->merchant_name.$key->service_name;
                $key->save();
                $existing[$identity] = true;
                $created++;
            }
        });

        return redirect()->route('provider-payments.maintainers.llave-centro-costos', ['vista' => 'proveedor'])
            ->with('status', "{$created} llaves copiadas a {$target->legal_name}; {$skipped} combinaciones existentes omitidas.");
    }

    private function combinationIdentity(CostCenterKey $key): string
    {
        $client = strtoupper(trim((string) $key->client_tax_id));
        if ($client === '' || in_array($client, ['#N/D', 'N/A'], true)) {
            $client = mb_strtoupper(trim((string) $key->merchant_name));
        }

        return json_encode([
            $client,
            (int) $key->service_code,
            $key->cost_center_code === null ? null : (int) $key->cost_center_code,
        ]);
    }

    private function clientFilterKey(CostCenterKey $key): string
    {
        return mb_strtolower(trim((string) ($key->client?->source_merchant_name ?: $key->merchant_name)));
    }

    private function providerFilterKey(CostCenterKey $key): string
    {
        return strtoupper(trim((string) ($key->provider?->tax_id ?: $key->provider_tax_id ?: $key->agent_name)));
    }

    public function update(Request $request, CostCenterKey $key): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        abort_unless($key->tenant_id === $tenant->id, 404);
        $data = $request->validate([
            'agent_name' => ['nullable', 'string', 'max:160'], 'payment_status' => ['required', 'string', 'max:20'],
            'cost_center_code' => ['nullable', 'integer', Rule::exists('cost_centers', 'cost_center_code')], 'is_active' => ['required', 'boolean'],
        ]);
        $key->update([...$data, 'payment_status' => strtoupper($data['payment_status'])]);

        return back()->with('status', 'Llave actualizada; proveedor, cliente y servicio permanecieron protegidos.');
    }

    public function updateMany(Request $request): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:100'],
            'rows.*.id' => ['required', 'integer', 'distinct', Rule::exists('llave_centro_costos', 'id')->where('tenant_id', $tenant->id)],
            'rows.*.agent_name' => ['nullable', 'string', 'max:160'],
            'rows.*.payment_status' => ['required', 'string', 'max:20'],
            'rows.*.cost_center_code' => ['nullable', 'integer', Rule::exists('cost_centers', 'cost_center_code')],
            'rows.*.is_active' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($tenant, $validated): void {
            foreach ($validated['rows'] as $row) {
                CostCenterKey::query()->where('tenant_id', $tenant->id)->findOrFail($row['id'])->update([
                    'agent_name' => $row['agent_name'] ?? null,
                    'payment_status' => strtoupper(trim($row['payment_status'])),
                    'cost_center_code' => $row['cost_center_code'] ?? null,
                    'is_active' => $row['is_active'],
                ]);
            }
        });

        return back()->with('status', count($validated['rows']).' llaves modificadas y guardadas.');
    }
}
