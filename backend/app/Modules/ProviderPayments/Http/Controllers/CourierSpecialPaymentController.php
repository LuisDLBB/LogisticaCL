<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\Client;
use App\Models\CourierMovement;
use App\Models\CourierSpecialPayment;
use App\Models\Coverage;
use App\Models\MaestroPago;
use App\Models\Provider;
use App\Models\ServiceType;
use App\Models\Tenant;
use App\Modules\ProviderPayments\Services\CourierSpecialPaymentFinalizer;
use App\Modules\ProviderPayments\Services\CourierSpecialPaymentImporter;
use App\Modules\ProviderPayments\Services\CourierSpecialPaymentMatcher;
use App\Modules\ProviderPayments\Services\CourierSpecialPaymentProviderCorrection;
use App\Modules\ProviderPayments\Services\CourierSpecialPaymentRollback;
use App\Modules\ProviderPayments\Services\CourierSpecialPaymentTrackingMatcher;
use App\Modules\ProviderPayments\Services\MonthlyPaymentClosingService;
use App\Modules\ProviderPayments\Services\PaidTrackingReport;
use App\Modules\ProviderPayments\Services\ProcessDeletionAuthorizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CourierSpecialPaymentController
{
    public function index(Request $request, CourierSpecialPaymentMatcher $matcher): View
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $periods = CourierSpecialPayment::query()->where('tenant_id', $tenant->id)
            ->selectRaw('periodo, COUNT(*) as total, SUM(monto) as monto_total, SUM(CASE WHEN finalized_at IS NOT NULL THEN 1 ELSE 0 END) as finalized_total')
            ->groupBy('periodo')->orderByDesc('periodo')->get();
        $selectedPeriod = (string) $request->query('periodo', $periods->first()?->periodo ?? '');
        if ($selectedPeriod !== '' && ! $periods->contains('periodo', $selectedPeriod)) {
            $selectedPeriod = $periods->first()?->periodo ?? '';
        }
        $payments = CourierSpecialPayment::query()->where('tenant_id', $tenant->id)
            ->when($selectedPeriod !== '', fn ($query) => $query->where('periodo', $selectedPeriod))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                foreach (['agente', 'codigo_seguimiento', 'localidad', 'cliente', 'descripcion'] as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            }))
            ->orderByRaw('CASE WHEN provider_id IS NULL AND client_id IS NULL THEN 0 WHEN provider_id IS NULL OR client_id IS NULL THEN 1 ELSE 2 END')
            ->orderByDesc('fecha')->orderByDesc('id')->paginate(100)->withQueryString();
        $providers = Provider::query()->where('tenant_id', $tenant->id)->orderBy('legal_name')->get();
        $clients = Client::query()->where('tenant_id', $tenant->id)->orderBy('commercial_name')->get();
        $serviceTypes = ServiceType::query()->orderBy('name')->get();
        $coverages = Coverage::query()->where('tenant_id', $tenant->id)->where('is_active', true)
            ->get(['provider_id', 'provider_tax_id', 'commune_name']);
        $providerSuggestions = [];
        $clientSuggestions = [];
        $associations = [];
        foreach ($payments as $payment) {
            $providerSuggestions[$payment->agente] ??= $matcher->provider($payment->agente, $coverages, $providers);
            $clientSuggestions[$payment->cliente ?? ''] ??= $matcher->client($payment->cliente, $clients);
            $associations[$payment->id] = [
                'provider' => $providerSuggestions[$payment->agente],
                'client' => $clientSuggestions[$payment->cliente ?? ''],
            ];
        }
        $pendingTrackingCodes = $payments->filter(fn (CourierSpecialPayment $payment): bool => $payment->service_type_id === null)
            ->pluck('codigo_seguimiento')->filter()->unique()->values()->all();
        $foundTrackingCodes = CourierMovement::query()->where('tenant_id', $tenant->id)
            ->when($pendingTrackingCodes !== [], fn ($query) => $query->where(fn ($query) => $query
                ->whereIn('tracking_number', $pendingTrackingCodes)
                ->orWhereIn('tracking_code', $pendingTrackingCodes)))
            ->when($pendingTrackingCodes === [], fn ($query) => $query->whereRaw('1 = 0'))
            ->get(['tracking_number', 'tracking_code'])
            ->flatMap(fn (CourierMovement $movement): array => [$movement->tracking_number, $movement->tracking_code])
            ->filter()->unique()->flip();
        $missingAssociations = CourierSpecialPayment::query()->where('tenant_id', $tenant->id)
            ->where('periodo', $selectedPeriod)
            ->where(fn ($query) => $query->whereNull('provider_id')->orWhereNull('client_id')->orWhereNull('service_type_id'))
            ->count();
        $finalizedCount = CourierSpecialPayment::query()->where('tenant_id', $tenant->id)
            ->where('periodo', $selectedPeriod)->whereNotNull('finalized_at')->count();
        $periodTotal = (int) ($periods->firstWhere('periodo', $selectedPeriod)?->total ?? 0);
        $monthClosed = $selectedPeriod !== '' && DB::table('PPR_Cierres_Pagos')
            ->where('tenant_id', $tenant->id)->where('periodo', substr($selectedPeriod, 0, 6))->exists();
        $isClosed = $monthClosed || ($periodTotal > 0 && $finalizedCount === $periodTotal);
        $closedPeriods = $periods->filter(fn ($period): bool => (int) $period->total > 0 && (int) $period->total === (int) $period->finalized_total)
            ->pluck('periodo')->values()->all();
        $monthlyClosures = DB::table('PPR_Cierres_Pagos')->where('tenant_id', $tenant->id)->pluck('periodo')
            ->map(fn (string $period): string => $period.'-Especiales')->all();
        $closedPeriods = array_values(array_unique(array_merge($closedPeriods, $monthlyClosures)));

        return view('provider-payments::courier-special-payments', compact('periods', 'selectedPeriod', 'payments', 'search', 'providers', 'clients', 'serviceTypes', 'associations', 'foundTrackingCodes', 'missingAssociations', 'finalizedCount', 'periodTotal', 'isClosed', 'monthClosed', 'closedPeriods'));
    }

    public function store(Request $request, CourierSpecialPaymentImporter $importer, CourierSpecialPaymentTrackingMatcher $tracker, PaidTrackingReport $reports): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx', 'max:102400'],
            'period_month' => ['required', 'date_format:Y-m'],
        ]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $file = $validated['file'];
        $period = str_replace('-', '', $validated['period_month']).'-Especiales';
        $this->assertPeriodOpen($tenant->id, $period);
        $result = $importer->import($file->getRealPath(), $file->getClientOriginalName(), $tenant->id, $period);
        $tracker->sync($tenant->id, $period);
        $reportToken = $reports->store($result['paid_rows'], $file->getClientOriginalName(), $period);
        if ($reportToken !== null) {
            $request->session()->push('paid_tracking_reports', $reportToken);
        }

        return redirect()->route('provider-payments.courier-movements.especiales')
            ->with('status', "Período {$period}: {$result['imported']} pagos cargados, {$result['reassigned']} corregidos, {$result['existing']} filas ya registradas y ".count($result['paid_rows']).' seguimientos ya pagados que no se cargaron.')
            ->with('paid_report_token', $reportToken);
    }

    public function update(Request $request, int $payment, CourierSpecialPaymentTrackingMatcher $tracker): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $specialPayment = CourierSpecialPayment::query()
            ->where('tenant_id', $tenant->id)->findOrFail($payment);
        $this->assertEditable($specialPayment);
        $validated = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d'],
            'usuario_ingresa' => ['required', 'string', 'max:160'],
            'autoriza' => ['required', 'string', 'max:160'],
            'agente' => ['required', 'string', 'max:255'],
            'zona_tipo' => ['required', 'string', 'max:40'],
            'codigo_seguimiento' => ['nullable', 'string', 'max:100'],
            'localidad' => ['required', 'string', 'max:160'],
            'cliente' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'monto' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'provider_id' => ['nullable', 'integer', Rule::exists('MBA_providers', 'id')->where('tenant_id', $tenant->id)],
            'client_id' => ['nullable', 'integer', Rule::exists('MBA_clients', 'id')->where('tenant_id', $tenant->id)],
            'return_q' => ['nullable', 'string', 'max:100'],
            'return_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $previousTracking = $specialPayment->codigo_seguimiento;
        $tracking = MaestroPago::trackingKey((string) ($validated['codigo_seguimiento'] ?? ''));
        if ($tracking !== '' && $tracking !== 'N/A' && MaestroPago::query()->whereKey($tracking)->exists()) {
            throw ValidationException::withMessages(['codigo_seguimiento' => "El seguimiento {$tracking} ya está cerrado en Maestro_Pagos."]);
        }
        $specialPayment->update(collect($validated)->only([
            'fecha', 'usuario_ingresa', 'autoriza', 'agente', 'zona_tipo',
            'codigo_seguimiento', 'localidad', 'cliente', 'descripcion', 'monto', 'provider_id', 'client_id',
        ])->map(fn ($value) => is_string($value) ? trim($value) : $value)->all());
        if ($specialPayment->codigo_seguimiento !== $previousTracking) {
            $specialPayment->update(['client_id' => null, 'service_type_id' => null]);
            $tracker->sync($tenant->id, $specialPayment->periodo);
        }

        return redirect()->route('provider-payments.courier-movements.especiales', array_filter([
            'periodo' => $specialPayment->periodo,
            'q' => $validated['return_q'] ?? null,
            'page' => $validated['return_page'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== ''))
            ->with('status', 'Pago especial actualizado.');
    }

    public function associate(Request $request, int $payment): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $specialPayment = CourierSpecialPayment::query()
            ->where('tenant_id', $tenant->id)->findOrFail($payment);
        $this->assertEditable($specialPayment);
        $validated = $request->validate([
            'provider_id' => ['nullable', 'integer', Rule::exists('MBA_providers', 'id')->where('tenant_id', $tenant->id)],
            'client_id' => ['nullable', 'integer', Rule::exists('MBA_clients', 'id')->where('tenant_id', $tenant->id)],
            'service_type_id' => ['nullable', 'integer', Rule::exists('PPR_service_types', 'id')],
            'return_q' => ['nullable', 'string', 'max:100'],
            'return_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $specialPayment->update([
            'provider_id' => $validated['provider_id'] ?? null,
            'client_id' => $validated['client_id'] ?? null,
            'service_type_id' => $validated['service_type_id'] ?? null,
        ]);

        return redirect()->route('provider-payments.courier-movements.especiales', array_filter([
            'periodo' => $specialPayment->periodo,
            'q' => $validated['return_q'] ?? null,
            'page' => $validated['return_page'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== ''))
            ->with('status', 'Proveedor, cliente y servicio asociados al pago especial.');
    }

    public function associatePage(Request $request): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $validated = $request->validate([
            'periodo' => ['required', 'string', 'regex:/^\d{6}-Especiales$/'],
            'rows' => ['required', 'array', 'min:1', 'max:100'],
            'rows.*.id' => ['required', 'integer', 'distinct', Rule::exists('PPR_courier_special_payments', 'id')
                ->where('tenant_id', $tenant->id)],
            'rows.*.provider_id' => ['present', 'nullable', 'integer', Rule::exists('MBA_providers', 'id')->where('tenant_id', $tenant->id)],
            'rows.*.client_id' => ['present', 'nullable', 'integer', Rule::exists('MBA_clients', 'id')->where('tenant_id', $tenant->id)],
            'rows.*.service_type_id' => ['present', 'nullable', 'integer', Rule::exists('PPR_service_types', 'id')],
            'return_q' => ['nullable', 'string', 'max:100'],
            'return_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $updated = DB::transaction(function () use ($tenant, $validated): int {
            MonthlyPaymentClosingService::assertOpen($tenant->id, substr($validated['periodo'], 0, 6));
            $rows = collect($validated['rows']);
            $payments = CourierSpecialPayment::query()->where('tenant_id', $tenant->id)
                ->where('periodo', $validated['periodo'])
                ->whereIn('id', $rows->pluck('id'))
                ->lockForUpdate()->get()->keyBy('id');
            if ($payments->count() !== $rows->count()) {
                throw ValidationException::withMessages(['rows' => 'Algunas filas ya no están disponibles en este período.']);
            }
            if ($payments->contains(fn (CourierSpecialPayment $payment): bool => $payment->finalized_at !== null)) {
                throw ValidationException::withMessages(['rows' => 'El período ya contiene pagos especiales finalizados. Reábrelo con la clave maestra antes de corregirlos.']);
            }

            foreach ($rows as $row) {
                $payments->get($row['id'])->update([
                    'provider_id' => $row['provider_id'],
                    'client_id' => $row['client_id'],
                    'service_type_id' => $row['service_type_id'],
                ]);
            }

            return $rows->count();
        });

        return redirect()->route('provider-payments.courier-movements.especiales', array_filter([
            'periodo' => $validated['periodo'],
            'q' => $validated['return_q'] ?? null,
            'page' => $validated['return_page'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== ''))
            ->with('status', "{$updated} pagos especiales guardados.");
    }

    public function destroy(Request $request, int $payment): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $specialPayment = CourierSpecialPayment::query()->where('tenant_id', $tenant->id)->findOrFail($payment);
        MonthlyPaymentClosingService::assertOpen($tenant->id, substr($specialPayment->periodo, 0, 6));
        if ($specialPayment->finalized_at !== null) {
            throw ValidationException::withMessages(['payment' => 'Este registro ya fue finalizado y no puede eliminarse desde la carga.']);
        }
        $period = $specialPayment->periodo;
        $specialPayment->delete();

        return redirect()->route('provider-payments.courier-movements.especiales', ['periodo' => $period])
            ->with('status', 'Pago especial eliminado.');
    }

    public function finalize(Request $request, CourierSpecialPaymentFinalizer $finalizer): RedirectResponse
    {
        $validated = $request->validate([
            'periodo' => ['required', 'string', 'regex:/^\d{6}-Especiales$/'],
        ]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $result = $finalizer->finalize($tenant->id, $validated['periodo']);

        return redirect()->route('provider-payments.courier-movements.especiales', ['periodo' => $validated['periodo']])
            ->with('status', "Proceso finalizado: {$result['updated']} movimientos actualizados y {$result['created']} creados.");
    }

    public function correctProvider(Request $request, int $payment, ProcessDeletionAuthorizer $authorizer, CourierSpecialPaymentProviderCorrection $correction): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $validated = $request->validate([
            'provider_id' => ['required', 'integer', Rule::exists('MBA_providers', 'id')->where('tenant_id', $tenant->id)],
            'return_q' => ['nullable', 'string', 'max:100'],
            'return_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $authorizer->authorize($request);
        $correction->correct($tenant->id, $payment, $validated['provider_id']);
        $special = CourierSpecialPayment::query()->where('tenant_id', $tenant->id)->findOrFail($payment);

        return redirect()->route('provider-payments.courier-movements.especiales', array_filter([
            'periodo' => $special->periodo,
            'q' => $validated['return_q'] ?? null,
            'page' => $validated['return_page'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== ''))
            ->with('status', 'Proveedor del pago especial corregido y actualizado en pagos de Courier.');
    }

    public function reopen(Request $request, ProcessDeletionAuthorizer $authorizer, CourierSpecialPaymentRollback $rollback): RedirectResponse
    {
        $period = $request->validate(['periodo' => ['required', 'regex:/^\d{6}-Especiales$/']])['periodo'];
        $authorizer->authorize($request);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $this->assertClosed($tenant->id, $period);
        $result = $rollback->rollback($tenant->id, $period);

        return redirect()->route('provider-payments.courier-movements.especiales', ['periodo' => $period])
            ->with('status', "Período {$period} reabierto. {$result['restored']} pagos anteriores restaurados y {$result['deleted_payments']} pagos especiales retirados. Ya puedes corregir y finalizar nuevamente.");
    }

    private function assertEditable(CourierSpecialPayment $payment): void
    {
        MonthlyPaymentClosingService::assertOpen($payment->tenant_id, substr($payment->periodo, 0, 6));
        if ($payment->finalized_at !== null) {
            throw ValidationException::withMessages(['periodo' => 'Este pago especial pertenece a un período cerrado. Reábrelo con la clave maestra antes de corregirlo.']);
        }
    }

    private function assertPeriodOpen(int $tenantId, string $period): void
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, substr($period, 0, 6));
        if (CourierSpecialPayment::query()->where('tenant_id', $tenantId)->where('periodo', $period)->whereNotNull('finalized_at')->exists()) {
            throw ValidationException::withMessages(['periodo' => "El período {$period} está cerrado. Reábrelo con la clave maestra antes de cargar otra base."]);
        }
    }

    private function assertClosed(int $tenantId, string $period): void
    {
        $total = CourierSpecialPayment::query()->where('tenant_id', $tenantId)->where('periodo', $period)->count();
        $finalized = CourierSpecialPayment::query()->where('tenant_id', $tenantId)->where('periodo', $period)->whereNotNull('finalized_at')->count();
        if ($total === 0 || $total !== $finalized) {
            throw ValidationException::withMessages(['periodo' => 'Este período no está cerrado.']);
        }
    }
}
