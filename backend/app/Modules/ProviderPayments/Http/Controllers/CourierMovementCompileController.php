<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\Client;
use App\Models\CostCenter;
use App\Models\CostCenterKey;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\CourierStatus;
use App\Models\Coverage;
use App\Models\Provider;
use App\Models\ServiceType;
use App\Models\Tenant;
use App\Modules\ProviderPayments\Services\CalamaProviderTransition;
use App\Modules\ProviderPayments\Services\CourierPaymentAssigner;
use App\Modules\ProviderPayments\Services\CourierPaymentSummary;
use App\Modules\ProviderPayments\Services\CourierWorkedConsolidatedExport;
use App\Modules\ProviderPayments\Services\MaestroPagoTaxCalculator;
use App\Modules\ProviderPayments\Services\MonthlyPaymentClosingService;
use App\Modules\ProviderPayments\Services\PeumoPaymentAssigner;
use App\Modules\ProviderPayments\Services\ProcessDeletionAuthorizer;
use App\Modules\ProviderPayments\Services\ProviderZone;
use App\Modules\ProviderPayments\Services\PurchaseOrderAssigner;
use App\Modules\ProviderPayments\Services\PurchaseOrderDocument;
use App\Modules\ProviderPayments\Services\PurchaseOrderExcelExport;
use App\Modules\ProviderPayments\Services\PurchaseOrderPdfExport;
use App\Modules\ProviderPayments\Services\PurchaseOrderProviderName;
use App\Modules\ProviderPayments\Services\PurchaseOrderSummaryExcelExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourierMovementCompileController
{
    private const PROCESS_TYPES = ['Variable', 'Lanas', 'Retornos', 'Peumo'];

    private const INTERNAL_PROVIDER_NAME = '4 Nortes Logistica SPA';

    private const WORK_FILTER_COLUMNS = [
        'process' => 'nombre_proceso',
        'zone' => 'zona',
        'matrix' => 'comuna_matriz',
        'client' => 'comerciante_pila',
        'payment_condition' => 'condicion_pago',
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
        $closure = $period === '' ? null : DB::table('PPR_Cierres_Pagos')->where('tenant_id', $tenant->id)->where('periodo', $period)->first();
        $purchaseOrders = $closure === null ? collect() : DB::table('PPR_Maestro_Pagos')
            ->where('tenant_id', $tenant->id)->where('periodo', $period)
            ->selectRaw('oc, MIN(zona) AS zona, MIN(razon_social_proveedor) AS razon_social_proveedor, MIN(rut_proveedor) AS rut_proveedor, MIN(empresa_mandante) AS empresa_mandante, COUNT(*) AS registros, COALESCE(SUM(valor), 0) AS total, COALESCE(SUM(valor_impuesto), 0) AS total_impuesto, COALESCE(SUM(valor_final_total), 0) AS total_final')
            ->groupBy('oc')->orderBy('oc')->get()
            ->each(fn (object $order) => $order->razon_social_proveedor = PurchaseOrderProviderName::display(
                $order->rut_proveedor, $order->razon_social_proveedor,
            ));
        $payable = $period === '' ? null : (clone $base)->where('periodo', $period)
            ->whereRaw('UPPER(TRIM(condicion_pago)) = ?', ['SI'])
            ->selectRaw('COUNT(*) AS registros, COALESCE(SUM(valor), 0) AS total')->first();

        return view('provider-payments::compile', [
            'title' => 'Compilar Movimientos Courier',
            'periods' => $periods,
            'period' => $period,
            'loadedProcesses' => $loadedProcesses,
            'closure' => $closure,
            'purchaseOrders' => $purchaseOrders,
            'payable' => $payable,
        ]);
    }

    public function closePeriod(Request $request, MonthlyPaymentClosingService $closing): RedirectResponse
    {
        $period = $request->validate(['period' => ['required', 'regex:/^\d{6}$/']])['period'];
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $result = $closing->close($tenant->id, $period);

        return redirect()->route('provider-payments.courier-movements.compile', ['period' => $period])
            ->with('status', sprintf('Período %s cerrado definitivamente. %s pagos SI guardados en PPR_Maestro_Pagos por $ %s.',
                $period, number_format($result['registros'], 0, ',', '.'), number_format($result['total'], 0, ',', '.')));
    }

    public function exportConsolidated(Request $request, CourierWorkedConsolidatedExport $export): StreamedResponse
    {
        $period = $request->validate(['period' => ['required', 'regex:/^\d{6}$/']])['period'];
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();

        return $export->download($tenant->id, $period);
    }

    public function purchaseOrders(Request $request, PurchaseOrderAssigner $assigner, MaestroPagoTaxCalculator $taxCalculator): View
    {
        $period = $request->validate(['period' => ['required', 'regex:/^\d{6}$/']])['period'];
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $closed = DB::table('PPR_Cierres_Pagos')->where('tenant_id', $tenant->id)->where('periodo', $period)->exists();
        $assigned = $closed ? [] : $assigner->forPeriod($tenant->id, $period);
        $rows = DB::table($closed ? 'PPR_Maestro_Pagos' : 'PPR_Pago_Movimientos_Courier')
            ->where('tenant_id', $tenant->id)->where('periodo', $period);
        if (! $closed) {
            $rows->whereRaw('UPPER(TRIM(condicion_pago)) = ?', ['SI']);
        }
        $summary = [];
        $taxTotals = ['IVA' => 0, 'Retencion' => 0];
        $taxBases = [];
        $taxAmounts = [];
        foreach ($rows->orderBy($closed ? 'seguimiento_paquete' : 'id')->cursor() as $payment) {
            if (! $closed && $payment->valor === null) {
                throw ValidationException::withMessages(['period' => "El pago {$payment->id} no tiene monto para calcular impuestos."]);
            }
            $oc = $closed ? $payment->oc : $assigned[$assigner->groupKey($payment)];
            $tax = $closed ? [
                'impuesto' => $payment->impuesto,
                'porcentaje_impuesto' => $payment->porcentaje_impuesto,
                'valor_impuesto' => $payment->valor_impuesto,
                'valor_final_total' => $payment->valor_final_total,
            ] : $taxCalculator->allocateForOrder(
                $payment->tipo_documento, (int) $payment->valor, (int) $payment->id,
                $oc, $taxBases, $taxAmounts,
            );
            if (! isset($summary[$oc])) {
                $summary[$oc] = [
                    'oc' => $oc,
                    'zona' => ProviderZone::isDsGroup((string) $payment->rut_proveedor) ? 'RM' : $payment->zona,
                    'razon_social_proveedor' => PurchaseOrderProviderName::display($payment->rut_proveedor, $payment->razon_social_proveedor),
                    'rut_proveedor' => $payment->rut_proveedor,
                    'empresa_mandante' => $payment->empresa_mandante,
                    'concepto' => $assigner->concept($payment),
                    'impuesto' => $tax['impuesto'],
                    'porcentaje_impuesto' => $tax['porcentaje_impuesto'],
                    'registros' => 0,
                    'total' => 0,
                    'valor_impuesto' => 0,
                    'valor_final_total' => 0,
                ];
            }
            if ($summary[$oc]['impuesto'] !== $tax['impuesto'] || $summary[$oc]['porcentaje_impuesto'] !== $tax['porcentaje_impuesto']) {
                $summary[$oc]['impuesto'] = 'Mixto';
                $summary[$oc]['porcentaje_impuesto'] = null;
            }
            $summary[$oc]['registros']++;
            $summary[$oc]['total'] += (int) $payment->valor;
            $summary[$oc]['valor_impuesto'] += (int) $tax['valor_impuesto'];
            $summary[$oc]['valor_final_total'] += (int) $tax['valor_final_total'];
            if (isset($taxTotals[$tax['impuesto']])) {
                $taxTotals[$tax['impuesto']] += (int) $tax['valor_impuesto'];
            }
        }
        ksort($summary);

        return view('provider-payments::compile-purchase-orders', compact('period', 'closed', 'summary', 'taxTotals'));
    }

    public function purchaseOrderPdf(string $oc, PurchaseOrderDocument $documents, PurchaseOrderPdfExport $export): StreamedResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();

        return $export->download($documents->load($tenant->id, $oc));
    }

    public function purchaseOrderExcel(string $oc, PurchaseOrderDocument $documents, PurchaseOrderExcelExport $export): StreamedResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();

        return $export->download($documents->load($tenant->id, $oc));
    }

    public function purchaseOrdersSummaryExcel(Request $request, PurchaseOrderSummaryExcelExport $export): StreamedResponse
    {
        $period = $request->validate(['period' => ['required', 'regex:/^\d{6}$/']])['period'];
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();

        return $export->download($tenant->id, $period);
    }

    public function destroyProcess(Request $request, ProcessDeletionAuthorizer $authorizer): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'regex:/^\d{6}$/'],
            'process' => ['required', 'in:Variable,Lanas,Retornos,Peumo'],
        ]);
        $authorizer->authorize($request);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        MonthlyPaymentClosingService::assertOpen($tenant->id, $validated['period']);
        $deleted = CourierPaymentMovement::query()->where('tenant_id', $tenant->id)
            ->where('periodo', $validated['period'])
            ->whereIn('nombre_proceso', [$validated['process'], $validated['period'].'-'.$validated['process']])->delete();

        return redirect()->route('provider-payments.courier-movements.compile', ['period' => $validated['period']])
            ->with('status', sprintf('%s-%s: %s registros trabajados eliminados.', $validated['period'], $validated['process'], number_format($deleted, 0, ',', '.')));
    }

    public function work(Request $request, CourierPaymentSummary $paymentSummary): View
    {
        $filters = $request->validate([
            'process' => ['nullable', 'string', 'max:100'],
            'service' => ['nullable', 'string', 'max:160'],
            'zone' => ['nullable', 'string', 'max:150'],
            'matrix' => ['nullable', 'string', 'max:150'],
            'client' => ['nullable', 'string', 'max:255'],
            'payment_condition' => ['nullable', 'in:SI,NO,__unset__'],
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
            ->selectRaw('nombre_proceso, COUNT(*) AS total')->groupBy('nombre_proceso')->get()
            ->sortBy(fn (CourierMovement $process): int => array_search(substr($process->nombre_proceso, 7), self::PROCESS_TYPES, true))
            ->values();
        $loadedProcessOptions = CourierMovement::query()->where('tenant_id', $tenant->id)
            ->where('nombre_proceso', 'like', '______-%')
            ->selectRaw('nombre_proceso, COUNT(*) AS total')->groupBy('nombre_proceso')->orderByDesc('nombre_proceso')->get();
        $paymentProcessCounts = CourierPaymentMovement::query()->where('tenant_id', $tenant->id)
            ->selectRaw('periodo, nombre_proceso, COUNT(*) AS total')
            ->groupBy('periodo', 'nombre_proceso')->get()
            ->groupBy(fn (CourierPaymentMovement $payment): string => str_starts_with($payment->nombre_proceso, $payment->periodo.'-')
                ? $payment->nombre_proceso : $payment->periodo.'-'.$payment->nombre_proceso)
            ->map(fn ($payments): int => (int) $payments->sum('total'));
        $rowsQuery = CourierPaymentMovement::query()->where('tenant_id', $tenant->id)
            ->when($period !== '', fn ($query) => $query->where('periodo', $period), fn ($query) => $query->whereRaw('1 = 0'));
        $processOptions = (clone $rowsQuery)->select('nombre_proceso')->distinct()->orderBy('nombre_proceso')->pluck('nombre_proceso');
        if (($filters['process'] ?? '') !== '' && ! $processOptions->contains($filters['process'])) {
            $filters['process'] = '';
        }
        $processOptionLabels = $processOptions->mapWithKeys(function (string $option) use ($period, $paymentProcessCounts): array {
            $processName = str_starts_with($option, $period.'-') ? $option : $period.'-'.$option;

            return [$option => $processName.' ('.number_format($paymentProcessCounts[$processName] ?? 0, 0, ',', '.').')'];
        });
        $filterOptions = ['process' => $processOptions];
        $serviceOptionsQuery = DB::table('PPR_Pago_Movimientos_Courier as payments')
            ->join('PPR_movimientos_courier as movements', 'movements.id', '=', 'payments.courier_movement_id')
            ->where('payments.tenant_id', $tenant->id)->where('payments.periodo', $period);
        foreach (self::WORK_FILTER_COLUMNS as $activeFilter => $activeColumn) {
            if (($filters[$activeFilter] ?? '') !== '') {
                if ($activeFilter === 'payment_condition' && $filters[$activeFilter] === '__unset__') {
                    $serviceOptionsQuery->whereNull('payments.'.$activeColumn);
                } else {
                    $serviceOptionsQuery->where('payments.'.$activeColumn, $filters[$activeFilter]);
                }
            }
        }
        $filterOptions['service'] = $serviceOptionsQuery->whereNotNull('movements.service_name')
            ->where('movements.service_name', '<>', '')
            ->select('movements.service_name')->distinct()->orderBy('movements.service_name')
            ->pluck('movements.service_name');
        $serviceMovements = fn () => CourierMovement::query()->select('id')->where('tenant_id', $tenant->id)
            ->where('service_name', $filters['service'] ?? '');
        foreach (self::WORK_FILTER_COLUMNS as $filter => $column) {
            if ($filter === 'process') {
                continue;
            }
            $optionsQuery = clone $rowsQuery;
            if (($filters['service'] ?? '') !== '') {
                $optionsQuery->whereIn('courier_movement_id', $serviceMovements());
            }
            foreach (self::WORK_FILTER_COLUMNS as $activeFilter => $activeColumn) {
                if ($activeFilter !== $filter && ($filters[$activeFilter] ?? '') !== '') {
                    if ($activeFilter === 'payment_condition' && $filters[$activeFilter] === '__unset__') {
                        $optionsQuery->whereNull($activeColumn);
                    } else {
                        $optionsQuery->where($activeColumn, $filters[$activeFilter]);
                    }
                }
            }
            $filterOptions[$filter] = $filter === 'payment_condition'
                ? $optionsQuery->select($column)->distinct()->orderBy($column)->pluck($column)
                    ->map(fn ($value): string => $value === null ? '__unset__' : $value)
                : $optionsQuery->whereNotNull($column)->where($column, '<>', '')
                    ->select($column)->distinct()->orderBy($column)->pluck($column);
        }
        foreach (self::WORK_FILTER_COLUMNS as $filter => $column) {
            if (($filters[$filter] ?? '') !== '') {
                if ($filter === 'payment_condition' && $filters[$filter] === '__unset__') {
                    $rowsQuery->whereNull($column);
                } else {
                    $rowsQuery->where($column, $filters[$filter]);
                }
            }
        }
        if (($filters['service'] ?? '') !== '') {
            $rowsQuery->whereIn('courier_movement_id', $serviceMovements());
        }
        $rows = $rowsQuery->with('courierMovement:id,service_name,dispatch_guide')->orderByDesc('id')->paginate(100)->withQueryString();
        $nonPayableStatuses = CourierStatus::query()->where('consider_for_payment', false)->orderBy('name')->pluck('name');
        $nonPayableCounts = $period === '' ? collect() : CourierPaymentMovement::query()
            ->where('tenant_id', $tenant->id)->where('periodo', $period)
            ->excludingSpecials()
            ->where(fn ($query) => $query->whereNull('condicion_pago')->orWhere('condicion_pago', '<>', 'NO'))
            ->whereIn('estado_envio', $nonPayableStatuses)
            ->selectRaw('estado_envio, COUNT(*) AS total')->groupBy('estado_envio')->orderBy('estado_envio')->get();
        $fourNorthSummary = $period === '' ? ['actionable' => 0, 'not_applicable' => 0, 'without_user' => 0, 'without_match' => 0]
            : $this->fourNorthSummary($tenant->id, $period);
        $fourNorthCandidates = $fourNorthSummary['actionable'];
        $internalProviderCount = $period === '' ? 0 : CourierPaymentMovement::query()
            ->where('tenant_id', $tenant->id)->where('periodo', $period)
            ->excludingSpecials()
            ->where('razon_social_proveedor', self::INTERNAL_PROVIDER_NAME)
            ->where(fn ($query) => $query->whereNull('condicion_pago')->orWhere('condicion_pago', '<>', 'NO'))->count();
        $paymentDashboard = $paymentSummary->forPeriod($tenant->id, $period);
        $keyReviewGroups = $period === '' ? collect() : $this->keyReviewGroups($tenant->id, $period);
        $missingKeyProviders = $keyReviewGroups->pluck('provider_tax_id')->unique()->count();
        $missingKeyCombinations = $keyReviewGroups->whereNull('key')->count();

        return view('provider-payments::compile-work', compact('periods', 'period', 'processes', 'loadedProcessOptions', 'paymentProcessCounts', 'processOptionLabels', 'rows', 'nonPayableCounts', 'fourNorthCandidates', 'fourNorthSummary', 'internalProviderCount', 'paymentDashboard', 'missingKeyProviders', 'filters', 'filterOptions'));
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

    public function saveReviewedKeys(Request $request, CourierPaymentAssigner $paymentAssigner): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $validated = $request->validate([
            'period' => ['required', 'regex:/^\d{6}$/'],
            'provider' => ['nullable', 'string', 'max:15'],
            'page' => ['nullable', 'integer', 'min:1'],
            'assign_payments' => ['nullable', 'boolean'],
            'rows' => ['required', 'array', 'min:1', 'max:100'],
            'rows.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('PPR_llave_centro_costos', 'id')->where('tenant_id', $tenant->id)],
            'rows.*.create' => ['nullable', 'boolean'],
            'rows.*.provider_tax_id' => ['nullable', 'string', 'max:15'],
            'rows.*.client_tax_id' => ['nullable', 'string', 'max:15'],
            'rows.*.service_code' => ['nullable', 'integer'],
            'rows.*.cost_center_code' => ['required', 'integer', Rule::exists('PPR_cost_centers', 'cost_center_code')],
            'rows.*.payment_status' => ['required', 'in:SI,NO,REVISAR'],
            'rows.*.is_active' => ['required', 'boolean'],
        ]);
        MonthlyPaymentClosingService::assertOpen($tenant->id, $validated['period']);
        $availableGroups = $this->keyReviewGroups($tenant->id, $validated['period'])->keyBy(
            fn ($group): string => implode('|', [$group->provider_tax_id, $group->client_tax_id, $group->service_code]),
        );
        [$saved, $created, $providerRuts] = DB::transaction(function () use ($tenant, $validated, $availableGroups): array {
            $saved = 0;
            $created = 0;
            $providerRuts = [];
            $newIdentities = [];
            foreach ($validated['rows'] as $row) {
                if (isset($row['id'])) {
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
                    $providerRut = $key->provider?->tax_id ?: $key->provider_tax_id;
                    if (filled($providerRut)) {
                        $providerRuts[$providerRut] = true;
                    }

                    continue;
                }

                if (! ($row['create'] ?? false)) {
                    continue;
                }
                $identity = implode('|', [$row['provider_tax_id'] ?? '', $row['client_tax_id'] ?? '', $row['service_code'] ?? '']);
                $group = $availableGroups->get($identity);
                if ($group === null || $group->key !== null || isset($newIdentities[$identity])) {
                    throw ValidationException::withMessages(['rows' => 'Una combinación nueva cambió o está repetida. Actualiza la página y vuelve a revisar.']);
                }
                if (CostCenterKey::query()->where('tenant_id', $tenant->id)
                    ->where('provider_tax_id', $group->provider_tax_id)
                    ->where('client_tax_id', $group->client_tax_id)
                    ->where('service_code', $group->service_code)->exists()) {
                    throw ValidationException::withMessages(['rows' => 'Esta combinación ya fue creada. Actualiza la página antes de continuar.']);
                }
                if ($row['payment_status'] === 'SI' && ((int) $row['cost_center_code'] === 0 || ! (bool) $row['is_active'])) {
                    throw ValidationException::withMessages(['rows' => 'Para generar un pago SI, selecciona un centro de costo distinto de 0 y deja la llave Activa.']);
                }
                $this->createReviewedKey($tenant->id, $group, (int) $row['cost_center_code'], $row['payment_status'], (bool) $row['is_active']);
                $newIdentities[$identity] = true;
                $providerRuts[$group->provider_tax_id] = true;
                $created++;
            }

            return [$saved, $created, array_keys($providerRuts)];
        });

        $message = $created.' llaves nuevas y '.$saved.' llaves existentes guardadas.';
        if (($validated['assign_payments'] ?? false) && $providerRuts !== []) {
            $paid = 0;
            $notPaid = 0;
            $pending = 0;
            foreach ($providerRuts as $providerRut) {
                MonthlyPaymentClosingService::assertOpen($tenant->id, $validated['period']);
                $result = $paymentAssigner->assign($tenant->id, $validated['period'], $providerRut);
                $paid += $result['paid'];
                $notPaid += $result['not_paid'];
                $pending += $result['missing_key'] + $result['ambiguous_key'] + $result['missing_rate'];
            }
            $message .= ' En los proveedores editados: '.$paid.' pagos SI, '.$notPaid.' NO; '.$pending.' pendientes de llave o tarifa.';
        }

        return redirect()->route('provider-payments.courier-movements.compile.keys.review', [
            'period' => $validated['period'], 'provider' => $validated['provider'] ?? '', 'page' => $validated['page'] ?? 1,
        ])->with('status', $message);
    }

    public function generateMissingKeys(Request $request): RedirectResponse
    {
        $validated = $request->validate(['period' => ['required', 'regex:/^\d{6}$/']]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        MonthlyPaymentClosingService::assertOpen($tenant->id, $validated['period']);
        CostCenter::query()->firstOrCreate(['cost_center_code' => 0], [
            'dispatch_guide_detail' => 'Sin Costo', 'additional_kilo_value' => 0, 'is_active' => true,
        ]);
        $created = DB::transaction(function () use ($tenant, $validated): int {
            $created = 0;
            foreach ($this->keyReviewGroups($tenant->id, $validated['period']) as $group) {
                if ($group->key !== null) {
                    continue;
                }
                $this->createReviewedKey($tenant->id, $group, 0, 'NO', false);
                $created++;
            }

            return $created;
        });

        return redirect()->route('provider-payments.courier-movements.compile.keys.review', [
            'period' => $validated['period'],
        ])->with('status', number_format($created, 0, ',', '.').' llaves CC generadas con centro 0, pago NO y estado Inactiva.');
    }

    private function createReviewedKey(int $tenantId, object $group, int $costCenterCode, string $paymentStatus, bool $isActive): CostCenterKey
    {
        $agent = $group->operational_name ?: $group->provider_name;

        return CostCenterKey::create([
            'tenant_id' => $tenantId,
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
            'cost_center_code' => $costCenterCode,
            'payment_status' => $paymentStatus,
            'is_active' => $isActive,
        ]);
    }

    private function keyReviewGroups(int $tenantId, string $period)
    {
        $groups = DB::table('PPR_Pago_Movimientos_Courier as payments')
            ->join('PPR_movimientos_courier as movements', 'movements.id', '=', 'payments.courier_movement_id')
            ->where('payments.tenant_id', $tenantId)->where('payments.periodo', $period)
            ->whereNotIn('payments.tipo_pago', ['Especiales', 'Ruta CV', 'Servicios', 'Acuerdos', 'Apoyo Alza', 'Visitas Diarias'])
            ->whereNotIn('payments.nombre_proceso', ['Especiales', 'Ruta CV', 'Servicios', 'Acuerdos', 'Apoyo', 'Visitas', 'Peumo'])
            ->where('payments.nombre_proceso', 'not like', '%-Especiales')
            ->where('payments.nombre_proceso', 'not like', '%-Ruta CV')
            ->where('payments.nombre_proceso', 'not like', '%-Servicios')
            ->where('payments.nombre_proceso', 'not like', '%-Acuerdos')
            ->where('payments.nombre_proceso', 'not like', '%-Apoyo')
            ->where('payments.nombre_proceso', 'not like', '%-Visitas')
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
            if ($matches->contains(fn (CostCenterKey $key): bool => $key->is_active && (
                $key->payment_status === 'NO'
                || ($key->payment_status === 'SI' && $key->cost_center_code > 0)
            ))) {
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
        MonthlyPaymentClosingService::assertOpen($tenant->id, $validated['period']);
        $assignments = DB::table('PPR_Proveedores_usuarios_4N')->get()->keyBy(fn ($row): string => $this->assignmentKey(
            $row->RutProveedor, $row->ComunaMatriz, $row->NombreRepartidor,
        ));
        $providers = Provider::query()->where('tenant_id', $tenant->id)->get()
            ->keyBy(fn (Provider $provider): string => strtoupper(trim($provider->tax_id)));
        $updated = 0;
        $withoutAssignment = 0;
        $notApplicable = 0;

        CourierPaymentMovement::query()->where('tenant_id', $tenant->id)
            ->where('periodo', $validated['period'])->where('rut_proveedor', '77346078-7')
            ->excludingSpecials()
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
                    DB::table('PPR_Pago_Movimientos_Courier')->whereIn('id', $group['ids'])->update([
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

    private function fourNorthSummary(int $tenantId, string $period): array
    {
        $summary = ['actionable' => 0, 'not_applicable' => 0, 'without_user' => 0, 'without_match' => 0];
        $assignments = DB::table('PPR_Proveedores_usuarios_4N')->get()->keyBy(fn ($row): string => $this->assignmentKey(
            $row->RutProveedor, $row->ComunaMatriz, $row->NombreRepartidor,
        ));
        $providerRuts = Provider::query()->where('tenant_id', $tenantId)->pluck('tax_id')
            ->map(fn (string $rut): string => strtoupper(trim($rut)))->flip();
        $groups = CourierPaymentMovement::query()->where('tenant_id', $tenantId)->where('periodo', $period)
            ->excludingSpecials()
            ->where('rut_proveedor', '77346078-7')->whereIn('comuna_matriz', ['4N RM', '4N Temuco'])
            ->select('comuna_matriz', 'nombre_repartidor')->selectRaw('COUNT(*) AS total')
            ->groupBy('comuna_matriz', 'nombre_repartidor')->get();
        foreach ($groups as $group) {
            $total = (int) $group->total;
            if (trim((string) $group->nombre_repartidor) === '') {
                $summary['without_user'] += $total;

                continue;
            }
            $assignment = $assignments->get($this->assignmentKey('77346078-7', $group->comuna_matriz, $group->nombre_repartidor));
            if ($assignment === null) {
                $summary['without_match'] += $total;

                continue;
            }
            $rut = strtoupper(trim($assignment->NuevoRutProveedor));
            if ($rut === 'N/A') {
                $summary['not_applicable'] += $total;
            } elseif ($providerRuts->has($rut)) {
                $summary['actionable'] += $total;
            } else {
                $summary['without_match'] += $total;
            }
        }

        return $summary;
    }

    public function markNonPayable(Request $request): RedirectResponse
    {
        $validated = $request->validate(['period' => ['required', 'regex:/^\d{6}$/']]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        MonthlyPaymentClosingService::assertOpen($tenant->id, $validated['period']);
        $nonPayableStatuses = CourierStatus::query()->where('consider_for_payment', false)->pluck('name');
        $updated = CourierPaymentMovement::query()->where('tenant_id', $tenant->id)
            ->where('periodo', $validated['period'])
            ->excludingSpecials()
            ->where(fn ($query) => $query->whereNull('condicion_pago')->orWhere('condicion_pago', '<>', 'NO'))
            ->whereIn('estado_envio', $nonPayableStatuses)->update(['condicion_pago' => 'NO', 'valor' => null]);

        return redirect()->route('provider-payments.courier-movements.compile.work', ['period' => $validated['period']])
            ->with('status', number_format($updated, 0, ',', '.').' registros con estados NO PAGAR marcados con condición de pago NO. Ningún registro fue eliminado.');
    }

    public function markInternalProvider(Request $request): RedirectResponse
    {
        $validated = $request->validate(['period' => ['required', 'regex:/^\d{6}$/']]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        MonthlyPaymentClosingService::assertOpen($tenant->id, $validated['period']);
        $updated = CourierPaymentMovement::query()->where('tenant_id', $tenant->id)
            ->where('periodo', $validated['period'])
            ->excludingSpecials()
            ->where('razon_social_proveedor', self::INTERNAL_PROVIDER_NAME)
            ->where(fn ($query) => $query->whereNull('condicion_pago')->orWhere('condicion_pago', '<>', 'NO'))
            ->update(['condicion_pago' => 'NO', 'valor' => null]);

        return redirect()->route('provider-payments.courier-movements.compile.work', ['period' => $validated['period']])
            ->with('status', number_format($updated, 0, ',', '.').' registros del proveedor interno marcados con condición de pago NO. Ningún registro fue eliminado.');
    }

    public function assignPayments(Request $request, CourierPaymentAssigner $assigner): RedirectResponse
    {
        set_time_limit(900);
        $validated = $request->validate(['period' => ['required', 'regex:/^\d{6}$/']]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        MonthlyPaymentClosingService::assertOpen($tenant->id, $validated['period']);
        $result = $assigner->assign($tenant->id, $validated['period']);

        return redirect()->route('provider-payments.courier-movements.compile.work', ['period' => $validated['period']])
            ->with('status', sprintf(
                'Asignar Pagos: %s pesos finales recalculados (%s Lanas sin peso ajustadas a 1 kg), %s con pago SI y valor, %s con pago NO. Pendientes: %s sin llave, %s con llaves ambiguas, %s sin tarifa, %s Retornos sin cobertura, %s con coberturas ambiguas y %s sin valor de retorno. Peumo: %s bultos con tarifa, %s pendientes (%s sin guía, %s sin tarifa, %s con tarifa ambigua y %s sin cliente/proveedor).',
                number_format($result['weights_recalculated'], 0, ',', '.'),
                number_format($result['weight_defaulted'], 0, ',', '.'),
                number_format($result['paid'], 0, ',', '.'), number_format($result['not_paid'], 0, ',', '.'),
                number_format($result['missing_key'], 0, ',', '.'), number_format($result['ambiguous_key'], 0, ',', '.'),
                number_format($result['missing_rate'], 0, ',', '.'),
                number_format($result['missing_coverage'], 0, ',', '.'), number_format($result['ambiguous_coverage'], 0, ',', '.'),
                number_format($result['missing_return_value'], 0, ',', '.'),
                number_format($result['peumo']['paid'], 0, ',', '.'), number_format($result['peumo']['pending'], 0, ',', '.'),
                number_format($result['peumo']['missing_guide'], 0, ',', '.'), number_format($result['peumo']['missing_rate'], 0, ',', '.'),
                number_format($result['peumo']['ambiguous_rate'], 0, ',', '.'), number_format($result['peumo']['missing_identity'], 0, ',', '.'),
            ));
    }

    public function compile(Request $request, PeumoPaymentAssigner $peumoAssigner, CalamaProviderTransition $calamaTransition): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'regex:/^\d{6}$/'],
            'processes' => ['required', 'array', 'min:1'],
            'processes.*' => ['required', 'in:Variable,Lanas,Retornos,Peumo'],
        ]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $period = $validated['period'];
        MonthlyPaymentClosingService::assertOpen($tenant->id, $period);
        set_time_limit(900);
        $names = array_map(fn (string $type): string => $period.'-'.$type, array_unique($validated['processes']));
        $coverages = Coverage::query()->where('tenant_id', $tenant->id)->where('is_active', true)
            ->with('provider')->get()
            ->groupBy(fn (Coverage $coverage): string => $this->communeKey($coverage->commune_name));
        $providersByRut = Provider::query()->where('tenant_id', $tenant->id)->get()->keyBy('tax_id');
        $providerAssignments = DB::table('PPR_Proveedores_usuarios_4N')
            ->get()
            ->keyBy(fn ($row): string => $this->assignmentKey($row->RutProveedor, $row->ComunaMatriz, $row->NombreRepartidor));
        $count = 0;
        $pendingProviders = 0;
        CourierMovement::query()->where('tenant_id', $tenant->id)->whereIn('nombre_proceso', $names)
            ->whereNotIn('id', DB::table('PPR_Pago_Movimientos_Courier')
                ->where('tenant_id', $tenant->id)
                ->where(fn ($query) => $query->where('tipo_pago', 'Especiales')
                    ->orWhere('nombre_proceso', 'Especiales')
                    ->orWhere('nombre_proceso', 'like', '%-Especiales'))
                ->select('courier_movement_id'))
            ->with('client')->chunkById(500, function ($movements) use ($tenant, $period, $coverages, $providersByRut, $providerAssignments, $calamaTransition, &$count, &$pendingProviders): void {
                $now = now();
                $rows = [];
                $sourceWeightUpdates = [];
                $externalTrackings = DB::table('PPR_envios_externos')->where('tenant_id', $tenant->id)
                    ->where('exclude_provider_payment', true)
                    ->whereIn('tracking_number', $movements->pluck('tracking_number'))
                    ->pluck('tracking_number')->flip();
                foreach ($movements as $movement) {
                    $finalWeight = CourierMovement::pesoFinal($movement->peso_real, $movement->peso_transformado);
                    if ($movement->peso_final !== $finalWeight) {
                        $sourceWeightUpdates[$finalWeight][] = $movement->id;
                    }
                    $matches = $coverages->get($this->communeKey((string) $movement->destination_commune_name), collect());
                    $zones = $matches->pluck('zone')->filter()->unique();
                    $matrices = $matches->pluck('matrix_commune_name')->filter()->unique();
                    $matrix = $matrices->count() === 1 ? $matrices->first() : null;
                    $providerRuts = $matches->map(fn (Coverage $coverage): string => strtoupper(trim((string) (
                        $coverage->provider?->tax_id ?: $coverage->provider_tax_id
                    ))))->filter()->unique()->values();
                    $providerRut = $providerRuts->count() === 1 ? $providerRuts->first() : null;
                    $assignment = $providerRut === null ? null : $providerAssignments->get($this->assignmentKey($providerRut, $matrix, $movement->courier_name));
                    if ($assignment !== null && strtoupper(trim($assignment->NuevoRutProveedor)) !== 'N/A'
                        && $providersByRut->has(strtoupper(trim($assignment->NuevoRutProveedor)))) {
                        $providerRut = strtoupper(trim($assignment->NuevoRutProveedor));
                    }
                    $process = $movement->tipo_pago ?: substr((string) $movement->nombre_proceso, 7);
                    $calamaRut = $calamaTransition->providerRut($period, $process, $movement->destination_commune_name, $movement->courier_name);
                    if ($calamaRut !== null && $providersByRut->has($calamaRut)) {
                        $providerRut = $calamaRut;
                        $matrix = $calamaTransition->matrixFor($calamaRut);
                    }
                    $provider = $providerRut ? $providersByRut->get($providerRut) : null;
                    $coverageProvider = $providerRut ? $matches->first(fn (Coverage $coverage): bool => strtoupper(trim((string) ($coverage->provider?->tax_id ?: $coverage->provider_tax_id))) === $providerRut
                    ) : null;
                    if ($providerRut === null) {
                        $pendingProviders++;
                    }
                    $rows[] = [
                        'tenant_id' => $tenant->id,
                        'courier_movement_id' => $movement->id,
                        'zona' => ProviderZone::resolve($providerRut, $provider?->id, $zones->count() === 1 ? $zones->first() : null),
                        'comuna_matriz' => $matrix,
                        'tipo_pago' => $process,
                        'nombre_proceso' => CourierPaymentMovement::withoutPeriodPrefix((string) $movement->nombre_proceso),
                        'periodo' => substr((string) $movement->nombre_proceso, 0, 6),
                        'seguimiento_paquete' => $movement->tracking_number,
                        'fecha' => $movement->fecha?->toDateString(),
                        'direccion' => $movement->getRawOriginal('recipient_address'),
                        'comuna_destino' => $movement->destination_commune_name,
                        'comerciante_pila' => $movement->client?->source_merchant_name ?? $movement->merchant_name,
                        'rut_cliente' => $movement->client?->tax_id,
                        'razon_social_cliente' => $movement->client?->legal_name,
                        'peso_final' => $finalWeight,
                        'estado_envio' => $movement->status,
                        'condicion_pago' => $externalTrackings->has($movement->tracking_number) || $this->communeKey((string) $matrix) === 'envio externo' ? 'NO' : null,
                        'razon_social_proveedor' => $provider?->legal_name ?: $coverageProvider?->provider_name_source,
                        'rut_proveedor' => $providerRut,
                        'nombre_operacional' => $provider?->operational_name,
                        'tipo_documento' => $provider?->tax_document_type,
                        'nombre_repartidor' => $movement->courier_name,
                        'usuario_entrega' => $movement->delivery_user_name,
                        'empresa_mandante' => '4N',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                foreach ($sourceWeightUpdates as $weight => $ids) {
                    DB::table('PPR_movimientos_courier')->where('tenant_id', $tenant->id)->whereIn('id', $ids)
                        ->update(['peso_final' => $weight, 'updated_at' => $now]);
                }
                DB::table('PPR_Pago_Movimientos_Courier')->upsert($rows, ['tenant_id', 'courier_movement_id'], array_keys(array_diff_key($rows[0], array_flip(['tenant_id', 'courier_movement_id', 'created_at', 'condicion_pago']))));
                $count += count($rows);
            });

        DB::table('PPR_Pago_Movimientos_Courier')->where('tenant_id', $tenant->id)->where('periodo', $period)
            ->whereIn('seguimiento_paquete', DB::table('PPR_envios_externos')->where('tenant_id', $tenant->id)
                ->where('exclude_provider_payment', true)->select('tracking_number'))
            ->where(fn ($query) => $query->whereNull('condicion_pago')->orWhere('condicion_pago', '<>', 'NO')
                ->orWhereNull('valor')->orWhere('valor', '<>', 0))
            ->update(['condicion_pago' => 'NO', 'valor' => 0, 'updated_at' => now()]);

        DB::table('PPR_Pago_Movimientos_Courier')->where('tenant_id', $tenant->id)->where('periodo', $period)
            ->whereNotIn('tipo_pago', ['Especiales', 'Ruta CV', 'Servicios', 'Acuerdos', 'Apoyo Alza', 'Visitas Diarias'])
            ->whereNotIn('nombre_proceso', ['Especiales', 'Ruta CV', 'Servicios', 'Acuerdos', 'Apoyo', 'Visitas'])
            ->where('nombre_proceso', 'not like', '%-Especiales')
            ->where('nombre_proceso', 'not like', '%-Ruta CV')
            ->where('nombre_proceso', 'not like', '%-Servicios')
            ->where('nombre_proceso', 'not like', '%-Acuerdos')
            ->where('nombre_proceso', 'not like', '%-Apoyo')
            ->where('nombre_proceso', 'not like', '%-Visitas')
            ->whereNotIn('seguimiento_paquete', DB::table('PPR_envios_externos')->where('tenant_id', $tenant->id)
                ->where('exclude_provider_payment', true)->select('tracking_number'))
            ->whereRaw('LOWER(TRIM(comuna_matriz)) = ?', ['envio externo'])
            ->where(fn ($query) => $query->whereNull('condicion_pago')
                ->orWhere('condicion_pago', '!=', 'NO')->orWhereNotNull('valor'))
            ->update(['condicion_pago' => 'NO', 'valor' => null, 'updated_at' => now()]);

        $peumo = in_array('Peumo', $validated['processes'], true)
            ? $peumoAssigner->assign($tenant->id, $period) : null;

        return redirect()->route('provider-payments.courier-movements.compile.work', ['period' => $period])
            ->with('status', number_format($count, 0, ',', '.').' registros trabajados. '.number_format($pendingProviders, 0, ',', '.').' sin proveedor único en Coberturas; se dejaron pendientes.'
                .($peumo === null ? '' : ' Peumo: '.number_format($peumo['paid'], 0, ',', '.').' bultos con Valor asignado y '.number_format($peumo['pending'], 0, ',', '.').' pendientes.'));
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
