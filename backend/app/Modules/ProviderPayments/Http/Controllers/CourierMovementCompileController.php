<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\CourierStatus;
use App\Models\Coverage;
use App\Models\CostCenter;
use App\Models\CostCenterKey;
use App\Models\Client;
use App\Models\Provider;
use App\Models\ServiceType;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CourierMovementCompileController
{
    private const PROCESS_TYPES = ['Variable', 'Lanas', 'Retornos'];
    private const INTERNAL_PROVIDER_NAME = '4 Nortes Logistica SPA';
    private const WORK_FILTER_COLUMNS = [
        'zone' => 'zona',
        'matrix' => 'comuna_matriz',
        'client' => 'comerciante_pila',
        'client_legal_name' => 'razon_social_cliente',
        'provider_legal_name' => 'razon_social_proveedor',
        'operational_name' => 'nombre_operacional',
        'document_type' => 'tipo_documento',
        'courier_name' => 'nombre_repartidor',
        'company' => 'empresa_mandante',
    ];

    public function index(Request $request): View
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $base = CourierPaymentMovement::query()->where('tenant_id', $tenant->id);
        $periods = (clone $base)->select('periodo')->distinct()->orderByDesc('periodo')->pluck('periodo')->all();
        $period = (string) $request->query('period', $periods[0] ?? '');
        if (! in_array($period, $periods, true)) {
            $period = $periods[0] ?? '';
        }
        $loadedProcesses = $period === '' ? collect() : (clone $base)->where('periodo', $period)
            ->selectRaw('nombre_proceso, COUNT(*) AS total')->groupBy('nombre_proceso')->orderBy('nombre_proceso')->get();

        return view('provider-payments::compile', [
            'title' => 'Compilar Movimientos Courier',
            'periods' => $periods,
            'period' => $period,
            'loadedProcesses' => $loadedProcesses,
        ]);
    }

    public function destroyProcess(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'regex:/^\d{6}$/'],
            'process' => ['required', 'in:Variable,Lanas,Retornos'],
        ]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $deleted = CourierPaymentMovement::query()->where('tenant_id', $tenant->id)
            ->where('periodo', $validated['period'])->where('nombre_proceso', $validated['process'])->delete();

        return redirect()->route('provider-payments.courier-movements.compile', ['period' => $validated['period']])
            ->with('status', sprintf('%s-%s: %s registros trabajados eliminados.', $validated['period'], $validated['process'], number_format($deleted, 0, ',', '.')));
    }

    public function work(Request $request): View
    {
        $filters = $request->validate([
            'zone' => ['nullable', 'string', 'max:150'],
            'matrix' => ['nullable', 'string', 'max:150'],
            'client' => ['nullable', 'string', 'max:255'],
            'client_legal_name' => ['nullable', 'string', 'max:255'],
            'provider_legal_name' => ['nullable', 'string', 'max:255'],
            'operational_name' => ['nullable', 'string', 'max:255'],
            'document_type' => ['nullable', 'string', 'max:100'],
            'courier_name' => ['nullable', 'string', 'max:160'],
            'company' => ['nullable', 'string', 'max:20'],
        ]);
        $filters = array_map(fn ($value): string => trim((string) $value), $filters);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $periods = CourierMovement::query()->where('tenant_id', $tenant->id)
            ->where('nombre_proceso', 'like', '______-%')
            ->selectRaw('SUBSTR(nombre_proceso, 1, 6) AS periodo')->distinct()->orderByDesc('periodo')->pluck('periodo')->all();
        $period = (string) $request->query('period', $periods[0] ?? '');
        if (! in_array($period, $periods, true)) {
            $period = $periods[0] ?? '';
        }
        $processes = $period === '' ? collect() : CourierMovement::query()->where('tenant_id', $tenant->id)
            ->whereIn('nombre_proceso', array_map(fn (string $type): string => $period.'-'.$type, self::PROCESS_TYPES))
            ->selectRaw('nombre_proceso, COUNT(*) AS total')->groupBy('nombre_proceso')->orderBy('nombre_proceso')->get();
        $compiled = $period === '' ? collect() : CourierPaymentMovement::query()->where('tenant_id', $tenant->id)
            ->where('periodo', $period)->selectRaw('nombre_proceso, COUNT(*) AS total')->groupBy('nombre_proceso')->pluck('total', 'nombre_proceso');
        $rowsQuery = CourierPaymentMovement::query()->where('tenant_id', $tenant->id)
            ->when($period !== '', fn ($query) => $query->where('periodo', $period), fn ($query) => $query->whereRaw('1 = 0'));
        $filterOptions = [];
        foreach (self::WORK_FILTER_COLUMNS as $filter => $column) {
            $optionsQuery = clone $rowsQuery;
            foreach (self::WORK_FILTER_COLUMNS as $activeFilter => $activeColumn) {
                if ($activeFilter !== $filter && ($filters[$activeFilter] ?? '') !== '') {
                    $optionsQuery->where($activeColumn, $filters[$activeFilter]);
                }
            }
            $filterOptions[$filter] = $optionsQuery->whereNotNull($column)->where($column, '<>', '')
                ->select($column)->distinct()->orderBy($column)->pluck($column);
        }
        foreach (self::WORK_FILTER_COLUMNS as $filter => $column) {
            if (($filters[$filter] ?? '') !== '') {
                $rowsQuery->where($column, $filters[$filter]);
            }
        }
        $rows = $rowsQuery->orderByDesc('id')->paginate(100)->withQueryString();
        $nonPayableStatuses = CourierStatus::query()->where('consider_for_payment', false)->orderBy('name')->pluck('name');
        $nonPayableCounts = $period === '' ? collect() : CourierPaymentMovement::query()
            ->where('tenant_id', $tenant->id)->where('periodo', $period)
            ->whereIn('estado_envio', $nonPayableStatuses)
            ->selectRaw('estado_envio, COUNT(*) AS total')->groupBy('estado_envio')->orderBy('estado_envio')->get();
        $fourNorthCandidates = $period === '' ? 0 : CourierPaymentMovement::query()
            ->where('tenant_id', $tenant->id)->where('periodo', $period)
            ->where('rut_proveedor', '77346078-7')->whereIn('comuna_matriz', ['4N RM', '4N Temuco'])->count();
        $internalProviderCount = $period === '' ? 0 : CourierPaymentMovement::query()
            ->where('tenant_id', $tenant->id)->where('periodo', $period)
            ->where('razon_social_proveedor', self::INTERNAL_PROVIDER_NAME)->count();
        $keyReviewGroups = $period === '' ? collect() : $this->keyReviewGroups($tenant->id, $period);
        $missingKeyProviders = $keyReviewGroups->pluck('provider_tax_id')->unique()->count();
        $missingKeyCombinations = $keyReviewGroups->whereNull('key')->count();
        return view('provider-payments::compile-work', compact('periods', 'period', 'processes', 'compiled', 'rows', 'nonPayableCounts', 'fourNorthCandidates', 'internalProviderCount', 'missingKeyProviders', 'filters', 'filterOptions'));
    }

    public function reviewKeys(Request $request): View
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $period = $request->validate(['period' => ['required', 'regex:/^\d{6}$/']])['period'];
        $groups = $this->keyReviewGroups($tenant->id, $period);
        $missingTotal = $groups->whereNull('key')->count();
        $providerOptions = $groups->groupBy('provider_tax_id')->map(fn ($rows) => $rows->first()->provider_name)->sort();
        $provider = trim((string) $request->query('provider', ''));
        if ($provider !== '') {
            $groups = $groups->where('provider_tax_id', $provider)->values();
        }
        $page = max(1, (int) $request->query('page', 1));
        $rows = new LengthAwarePaginator($groups->forPage($page, 100)->values(), $groups->count(), 100, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);
        $centers = CostCenter::query()->where('is_active', true)->orderBy('cost_center_code')->get(['cost_center_code', 'dispatch_guide_detail']);

        return view('provider-payments::compile-key-review', compact('period', 'rows', 'groups', 'providerOptions', 'provider', 'centers', 'missingTotal'));
    }

    public function saveReviewedKeys(Request $request): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $validated = $request->validate([
            'period' => ['required', 'regex:/^\d{6}$/'],
            'provider' => ['nullable', 'string', 'max:15'],
            'page' => ['nullable', 'integer', 'min:1'],
            'rows' => ['required', 'array', 'min:1', 'max:100'],
            'rows.*.id' => ['required', 'integer', 'distinct', Rule::exists('llave_centro_costos', 'id')->where('tenant_id', $tenant->id)],
            'rows.*.cost_center_code' => ['required', 'integer', Rule::exists('cost_centers', 'cost_center_code')],
            'rows.*.payment_status' => ['required', 'in:SI,NO,REVISAR'],
            'rows.*.is_active' => ['required', 'boolean'],
        ]);
        $allowedIds = $this->keyReviewGroups($tenant->id, $validated['period'])
            ->pluck('key')->filter()->pluck('id')->all();
        $saved = DB::transaction(function () use ($tenant, $validated, $allowedIds): int {
            $saved = 0;
            foreach ($validated['rows'] as $row) {
                abort_unless(in_array((int) $row['id'], $allowedIds, true), 422);
                $key = CostCenterKey::query()->where('tenant_id', $tenant->id)->findOrFail($row['id']);
                $key->fill([
                    'cost_center_code' => $row['cost_center_code'],
                    'payment_status' => $row['payment_status'],
                    'is_active' => $row['is_active'],
                ]);
                if ($key->isDirty()) {
                    $key->save();
                    $saved++;
                }
            }

            return $saved;
        });

        return redirect()->route('provider-payments.courier-movements.compile.keys.review', [
            'period' => $validated['period'], 'provider' => $validated['provider'] ?? '', 'page' => $validated['page'] ?? 1,
        ])->with('status', $saved.' llaves modificadas y guardadas.');
    }

    public function generateMissingKeys(Request $request): RedirectResponse
    {
        $validated = $request->validate(['period' => ['required', 'regex:/^\d{6}$/']]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        CostCenter::query()->firstOrCreate(['cost_center_code' => 0], [
            'dispatch_guide_detail' => 'Sin Costo', 'additional_kilo_value' => 0, 'is_active' => true,
        ]);
        $created = DB::transaction(function () use ($tenant, $validated): int {
            $created = 0;
            foreach ($this->keyReviewGroups($tenant->id, $validated['period']) as $group) {
                if ($group->key !== null) {
                    continue;
                }
                $agent = $group->operational_name ?: $group->provider_name;
                CostCenterKey::create([
                    'tenant_id' => $tenant->id,
                    'provider_id' => $group->provider_id,
                    'provider_tax_id' => $group->provider_tax_id,
                    'agent_name' => $agent,
                    'client_id' => $group->client_id,
                    'client_tax_id' => $group->client_tax_id,
                    'merchant_name' => $group->client_name,
                    'service_type_id' => $group->service_type_id,
                    'service_code' => $group->service_code,
                    'service_name' => $group->service_name,
                    'key_code' => implode('/', [$group->provider_tax_id, $group->client_tax_id, $group->service_code]),
                    'key_text' => $agent.$group->client_name.$group->service_name,
                    'cost_center_code' => 0,
                    'payment_status' => 'NO',
                    'is_active' => false,
                ]);
                $created++;
            }

            return $created;
        });

        return redirect()->route('provider-payments.courier-movements.compile.keys.review', [
            'period' => $validated['period'],
        ])->with('status', number_format($created, 0, ',', '.').' llaves CC generadas con centro 0, pago NO y estado Inactiva.');
    }

    private function keyReviewGroups(int $tenantId, string $period)
    {
        $groups = DB::table('Pago_Movimientos_Courier as payments')
            ->join('movimientos_courier as movements', 'movements.id', '=', 'payments.courier_movement_id')
            ->where('payments.tenant_id', $tenantId)->where('payments.periodo', $period)
            ->whereNotNull('payments.rut_proveedor')->whereNotNull('payments.rut_cliente')->whereNotNull('movements.service_name')
            ->select('payments.rut_proveedor', 'payments.rut_cliente', 'movements.service_name')
            ->selectRaw('COUNT(*) AS movements')
            ->groupBy('payments.rut_proveedor', 'payments.rut_cliente', 'movements.service_name')->get();
        $providers = Provider::query()->where('tenant_id', $tenantId)->get()->keyBy(fn (Provider $provider): string => strtoupper(trim($provider->tax_id)));
        $clients = Client::query()->where('tenant_id', $tenantId)->get()->keyBy(fn (Client $client): string => strtoupper(trim($client->tax_id)));
        $services = ServiceType::query()->get()->keyBy(fn (ServiceType $service): string => mb_strtolower(trim($service->name)));
        $keys = CostCenterKey::query()->where('tenant_id', $tenantId)->with(['provider', 'client'])->get()
            ->groupBy(fn (CostCenterKey $key): string => implode('|', [
                strtoupper(trim((string) ($key->provider?->tax_id ?: $key->provider_tax_id))),
                strtoupper(trim((string) ($key->client?->tax_id ?: $key->client_tax_id))),
                (string) $key->service_code,
            ]));

        return $groups->map(function ($group) use ($keys, $providers, $clients, $services) {
            $provider = $providers->get(strtoupper(trim($group->rut_proveedor)));
            $client = $clients->get(strtoupper(trim($group->rut_cliente)));
            $service = $services->get(mb_strtolower(trim($group->service_name)));
            if ($provider === null || $client === null || $service === null) {
                return null;
            }
            $group->provider_id = $provider->id;
            $group->provider_tax_id = $provider->tax_id;
            $group->provider_name = $provider->legal_name;
            $group->operational_name = $provider->operational_name;
            $group->client_id = $client->id;
            $group->client_tax_id = $client->tax_id;
            $group->client_name = $client->source_merchant_name;
            $group->service_type_id = $service->id;
            $group->service_code = $service->service_code;
            $group->service_name = $service->name;
            $identity = implode('|', [strtoupper(trim($provider->tax_id)), strtoupper(trim($client->tax_id)), (string) $service->service_code]);
            $matches = $keys->get($identity, collect());
            if ($matches->contains(fn (CostCenterKey $key): bool => $key->is_active && $key->cost_center_code !== null)) {
                return null;
            }
            $group->key = $matches->sortByDesc('id')->first();

            return $group;
        })->filter()->sortBy(fn ($group): string => $group->provider_name.'|'.$group->client_name.'|'.$group->service_name)->values();
    }

    public function updateFourNorthProviders(Request $request): RedirectResponse
    {
        $validated = $request->validate(['period' => ['required', 'regex:/^\d{6}$/']]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $assignments = DB::table('Proveedores_usuarios_4N')->get()->keyBy(fn ($row): string => $this->assignmentKey(
            $row->RutProveedor, $row->ComunaMatriz, $row->NombreRepartidor,
        ));
        $providers = Provider::query()->where('tenant_id', $tenant->id)->get()
            ->keyBy(fn (Provider $provider): string => strtoupper(trim($provider->tax_id)));
        $updated = 0;
        $withoutAssignment = 0;
        $notApplicable = 0;

        CourierPaymentMovement::query()->where('tenant_id', $tenant->id)
            ->where('periodo', $validated['period'])->where('rut_proveedor', '77346078-7')
            ->whereIn('comuna_matriz', ['4N RM', '4N Temuco'])
            ->select(['id', 'rut_proveedor', 'comuna_matriz', 'nombre_repartidor'])
            ->chunkById(1000, function ($rows) use ($assignments, $providers, &$updated, &$withoutAssignment, &$notApplicable): void {
                $byProvider = [];
                foreach ($rows as $row) {
                    $assignment = $assignments->get($this->assignmentKey($row->rut_proveedor, $row->comuna_matriz, $row->nombre_repartidor));
                    if ($assignment === null) {
                        $withoutAssignment++;
                        continue;
                    }
                    if (strtoupper(trim($assignment->NuevoRutProveedor)) === 'N/A') {
                        $notApplicable++;
                        continue;
                    }
                    $provider = $providers->get(strtoupper(trim($assignment->NuevoRutProveedor)));
                    if ($provider === null) {
                        $withoutAssignment++;
                        continue;
                    }
                    $byProvider[$provider->id]['provider'] = $provider;
                    $byProvider[$provider->id]['ids'][] = $row->id;
                }
                foreach ($byProvider as $group) {
                    $provider = $group['provider'];
                    DB::table('Pago_Movimientos_Courier')->whereIn('id', $group['ids'])->update([
                        'rut_proveedor' => $provider->tax_id,
                        'razon_social_proveedor' => $provider->legal_name,
                        'nombre_operacional' => $provider->operational_name,
                        'tipo_documento' => $provider->tax_document_type,
                        'updated_at' => now(),
                    ]);
                    $updated += count($group['ids']);
                }
            });

        return redirect()->route('provider-payments.courier-movements.compile.work', ['period' => $validated['period']])
            ->with('status', number_format($updated, 0, ',', '.').' proveedores actualizados. '.number_format($notApplicable, 0, ',', '.').' con N/A conservados; '.number_format($withoutAssignment, 0, ',', '.').' sin cruce completo.');
    }

    public function destroyNonPayable(Request $request): RedirectResponse
    {
        $validated = $request->validate(['period' => ['required', 'regex:/^\d{6}$/']]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $nonPayableStatuses = CourierStatus::query()->where('consider_for_payment', false)->pluck('name');
        $deleted = CourierPaymentMovement::query()->where('tenant_id', $tenant->id)
            ->where('periodo', $validated['period'])->whereIn('estado_envio', $nonPayableStatuses)->delete();

        return redirect()->route('provider-payments.courier-movements.compile.work', ['period' => $validated['period']])
            ->with('status', number_format($deleted, 0, ',', '.').' registros con estados NO PAGAR eliminados de Pago_Movimientos_Courier. Los movimientos originales se conservan.');
    }

    public function destroyInternalProvider(Request $request): RedirectResponse
    {
        $validated = $request->validate(['period' => ['required', 'regex:/^\d{6}$/']]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $deleted = CourierPaymentMovement::query()->where('tenant_id', $tenant->id)
            ->where('periodo', $validated['period'])
            ->where('razon_social_proveedor', self::INTERNAL_PROVIDER_NAME)->delete();

        return redirect()->route('provider-payments.courier-movements.compile.work', ['period' => $validated['period']])
            ->with('status', number_format($deleted, 0, ',', '.').' registros del proveedor interno eliminados de Pago_Movimientos_Courier. Los movimientos originales se conservan.');
    }

    public function compile(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'regex:/^\d{6}$/'],
            'processes' => ['required', 'array', 'min:1'],
            'processes.*' => ['required', 'in:Variable,Lanas,Retornos'],
        ]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $period = $validated['period'];
        $names = array_map(fn (string $type): string => $period.'-'.$type, array_unique($validated['processes']));
        $coverages = Coverage::query()->where('tenant_id', $tenant->id)->where('is_active', true)
            ->with('provider')->get()
            ->groupBy(fn (Coverage $coverage): string => $this->communeKey($coverage->commune_name));
        $providersByRut = Provider::query()->where('tenant_id', $tenant->id)->get()->keyBy('tax_id');
        $count = 0;
        $pendingProviders = 0;
        CourierMovement::query()->where('tenant_id', $tenant->id)->whereIn('nombre_proceso', $names)
            ->with('client')->chunkById(500, function ($movements) use ($tenant, $coverages, $providersByRut, &$count, &$pendingProviders): void {
                $now = now();
                $rows = [];
                foreach ($movements as $movement) {
                    $matches = $coverages->get($this->communeKey((string) $movement->destination_commune_name), collect());
                    $zones = $matches->pluck('zone')->filter()->unique();
                    $matrices = $matches->pluck('matrix_commune_name')->filter()->unique();
                    $providers = $matches->map(fn (Coverage $coverage) => $coverage->provider ?: $providersByRut->get($coverage->provider_tax_id))
                        ->filter()->unique('id');
                    $provider = $providers->count() === 1 ? $providers->first() : null;
                    if ($provider === null) {
                        $pendingProviders++;
                    }
                    $rows[] = [
                        'tenant_id' => $tenant->id,
                        'courier_movement_id' => $movement->id,
                        'zona' => $zones->count() === 1 ? $zones->first() : null,
                        'comuna_matriz' => $matrices->count() === 1 ? $matrices->first() : null,
                        'tipo_pago' => $movement->tipo_pago ?: substr((string) $movement->nombre_proceso, 7),
                        'nombre_proceso' => substr((string) $movement->nombre_proceso, 7),
                        'periodo' => substr((string) $movement->nombre_proceso, 0, 6),
                        'seguimiento_paquete' => $movement->tracking_number,
                        'fecha' => $movement->fecha?->toDateString(),
                        'direccion' => $movement->getRawOriginal('recipient_address'),
                        'comuna_destino' => $movement->destination_commune_name,
                        'comerciante_pila' => $movement->client?->source_merchant_name ?? $movement->merchant_name,
                        'rut_cliente' => $movement->client?->tax_id,
                        'razon_social_cliente' => $movement->client?->legal_name,
                        'peso_final' => $movement->peso_real === null || $movement->peso_transformado === null
                            ? 1 : min($movement->peso_real, $movement->peso_transformado),
                        'estado_envio' => $movement->status,
                        'razon_social_proveedor' => $provider?->legal_name,
                        'rut_proveedor' => $provider?->tax_id,
                        'nombre_operacional' => $provider?->operational_name,
                        'tipo_documento' => $provider?->tax_document_type,
                        'nombre_repartidor' => $movement->courier_name,
                        'usuario_entrega' => $movement->delivery_user_name,
                        'empresa_mandante' => '4N',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('Pago_Movimientos_Courier')->upsert($rows, ['tenant_id', 'courier_movement_id'], array_keys(array_diff_key($rows[0], array_flip(['tenant_id', 'courier_movement_id', 'created_at']))));
                $count += count($rows);
            });

        return redirect()->route('provider-payments.courier-movements.compile.work', ['period' => $period])
            ->with('status', number_format($count, 0, ',', '.').' registros trabajados. '.number_format($pendingProviders, 0, ',', '.').' sin proveedor único en Coberturas; se dejaron pendientes.');
    }

    private function communeKey(string $commune): string
    {
        return Str::of($commune)->squish()->lower()->ascii()->toString();
    }

    private function assignmentKey(?string $rut, ?string $matrix, ?string $courier): string
    {
        return strtoupper(trim((string) $rut)).'|'.$this->communeKey((string) $matrix).'|'.$this->communeKey((string) $courier);
    }
}
