<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\ApoyoAlza;
use App\Models\CourierPaymentMovement;
use App\Models\Provider;
use App\Models\Tenant;
use App\Modules\ProviderPayments\Services\ApoyoAlzaCalculator;
use App\Modules\ProviderPayments\Services\ApoyoAlzaClosingService;
use App\Modules\ProviderPayments\Services\ApoyoAlzaImporter;
use App\Modules\ProviderPayments\Services\MonthlyPaymentClosingService;
use App\Modules\ProviderPayments\Services\ProcessDeletionAuthorizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ApoyoAlzaController
{
    public function index(Request $request): View
    {
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $loadedPeriods = ApoyoAlza::query()->where('tenant_id', $tenantId)
            ->select('periodo')->distinct()->pluck('periodo');
        $paymentPeriods = CourierPaymentMovement::query()->where('tenant_id', $tenantId)
            ->select('periodo')->distinct()->pluck('periodo');
        $periods = $loadedPeriods->merge($paymentPeriods)->unique()->sortDesc()->values();
        $period = (string) $request->query('periodo', $loadedPeriods->sortDesc()->first() ?? $periods->first() ?? now()->format('Ym'));
        if (! preg_match('/^\d{6}$/', $period)) {
            $period = $periods->first() ?? now()->format('Ym');
        }
        $process = in_array($request->query('proceso'), ['Acuerdos', 'Variables', 'Ruta CV'], true)
            ? $request->query('proceso') : '';
        $status = in_array($request->query('estado'), ['todos', 'calculado', 'no_pagar', 'pendientes'], true)
            ? $request->query('estado') : 'todos';
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $base = ApoyoAlza::query()->where('tenant_id', $tenantId)->where('periodo', $period);
        $summary = (clone $base)->selectRaw('COUNT(*) AS registros,
            SUM(CASE WHEN estado_calculo = ? THEN 1 ELSE 0 END) AS calculados,
            SUM(CASE WHEN estado_calculo = ? THEN 1 ELSE 0 END) AS no_pagar,
            SUM(CASE WHEN closed_at IS NOT NULL THEN 1 ELSE 0 END) AS cerrados,
            COALESCE(SUM(monto_apoyo), 0) AS monto', ['calculado', 'no_pagar'])->first();
        $monthClosed = $period !== '' && DB::table('Cierres_Pagos')->where('tenant_id', $tenantId)->where('periodo', $period)->exists();
        $isClosed = $monthClosed || ((int) $summary->registros > 0 && (int) $summary->cerrados === (int) $summary->registros);
        $rows = (clone $base)
            ->when($process !== '', fn ($query) => $query->where('proceso_base', $process))
            ->when($status === 'calculado', fn ($query) => $query->where('estado_calculo', 'calculado'))
            ->when($status === 'no_pagar', fn ($query) => $query->where('estado_calculo', 'no_pagar'))
            ->when($status === 'pendientes', fn ($query) => $query->whereNotIn('estado_calculo', ['calculado', 'no_pagar']))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('proveedor_origen', 'like', '%'.$search.'%')
                ->orWhere('rut_proveedor_origen', 'like', '%'.$search.'%')
                ->orWhere('agencia', 'like', '%'.$search.'%')
                ->orWhere('servicio_acuerdo', 'like', '%'.$search.'%')))
            ->with('provider')->orderByRaw("CASE WHEN estado_calculo NOT IN ('calculado', 'no_pagar') THEN 0 WHEN estado_calculo = 'no_pagar' THEN 1 ELSE 2 END")
            ->orderByRaw('LOWER(proveedor_origen)')
            ->orderByRaw('LOWER(proceso_base)')
            ->orderByRaw("LOWER(COALESCE(servicio_acuerdo, ''))")
            ->orderBy('id')->paginate(25)->withQueryString();
        $providers = Provider::query()->where('tenant_id', $tenantId)->orderBy('operational_name')->get();

        return view('provider-payments::apoyo-alza', compact(
            'periods', 'period', 'process', 'status', 'search', 'summary', 'rows', 'providers', 'isClosed', 'monthClosed',
        ));
    }

    public function import(Request $request, ApoyoAlzaImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'periodo' => ['required', 'date_format:Y-m'],
            'file' => ['required', 'file', 'extensions:xlsx', 'max:20480'],
        ]);
        $period = str_replace('-', '', $validated['periodo']);
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $result = $importer->import($validated['file']->getRealPath(), $validated['file']->getClientOriginalName(), $tenantId, $period);

        return redirect()->route('provider-payments.courier-movements.apoyo-alza', ['periodo' => $period])
            ->with('status', $result['imported'] > 0
                ? "{$result['imported']} apoyos cargados. {$result['calculated']} calculados y {$result['pending']} pendientes de revisión."
                : "El archivo ya estaba cargado: {$result['existing']} apoyos conservados sin duplicar.");
    }

    public function recalculate(Request $request, ApoyoAlzaCalculator $calculator): RedirectResponse
    {
        $period = $request->validate(['periodo' => ['required', 'date_format:Ym']])['periodo'];
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        if (! ApoyoAlza::query()->where('tenant_id', $tenantId)->where('periodo', $period)->exists()) {
            throw ValidationException::withMessages(['periodo' => 'No hay apoyos cargados para este período.']);
        }
        $this->assertOpen($tenantId, $period);
        $result = $calculator->recalculate($tenantId, $period);

        return redirect()->route('provider-payments.courier-movements.apoyo-alza', ['periodo' => $period])
            ->with('status', "Bases actualizadas: {$result['calculados']} apoyos calculados, {$result['no_pagar']} sin pago y {$result['pendientes']} pendientes.");
    }

    public function update(Request $request, ApoyoAlzaCalculator $calculator): RedirectResponse
    {
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $validated = $request->validate([
            'periodo' => ['required', 'date_format:Ym'],
            'rows' => ['required', 'array', 'min:1', 'max:25'],
            'rows.*.provider_id' => ['present', 'nullable', 'integer', Rule::exists('providers', 'id')->where('tenant_id', $tenantId)],
            'rows.*.servicio_acuerdo' => ['nullable', 'string', 'max:160'],
            'rows.*.porcentaje' => ['nullable', 'numeric', 'between:0,100'],
            'rows.*.monto_dia' => ['nullable', 'integer', 'min:0'],
            'rows.*.empresa_mandante' => ['required', 'string', 'max:20'],
            'rows.*.agencia' => ['required', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'],
            'proceso' => ['nullable', 'in:Acuerdos,Variables,Ruta CV'],
            'estado' => ['nullable', 'in:todos,calculado,pendientes'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $period = $validated['periodo'];
        DB::transaction(function () use ($tenantId, $period, $validated, $calculator): void {
            $this->assertOpen($tenantId, $period);
            $rows = ApoyoAlza::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                ->whereIn('id', array_keys($validated['rows']))->lockForUpdate()->get()->keyBy('id');
            abort_unless($rows->count() === count($validated['rows']), 404);
            foreach ($validated['rows'] as $id => $change) {
                $row = $rows[$id];
                if ($row->factor === 'Dia de Ruta CV') {
                    if (! isset($change['monto_dia'])) {
                        throw ValidationException::withMessages(['rows' => "Indica el monto por día de la fila {$row->fila_origen}."]);
                    }
                    $change['porcentaje'] = null;
                } else {
                    if (! isset($change['porcentaje']) || ($row->proceso_base === 'Acuerdos' && trim((string) ($change['servicio_acuerdo'] ?? '')) === '')) {
                        throw ValidationException::withMessages(['rows' => "Indica porcentaje y servicio de la fila {$row->fila_origen}."]);
                    }
                    $change['porcentaje'] = number_format((float) $change['porcentaje'] / 100, 6, '.', '');
                    $change['monto_dia'] = null;
                }
                $row->update($change);
            }
            $calculator->recalculate($tenantId, $period);
        });

        return redirect()->route('provider-payments.courier-movements.apoyo-alza', array_filter([
            'periodo' => $period,
            'page' => $validated['page'] ?? null,
            'proceso' => $validated['proceso'] ?? null,
            'estado' => $validated['estado'] ?? null,
            'q' => $validated['q'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''))
            ->with('status', count($validated['rows']).' apoyos guardados y recalculados.');
    }

    public function close(Request $request, ApoyoAlzaClosingService $closing): RedirectResponse
    {
        $period = $request->validate(['periodo' => ['required', 'date_format:Ym']])['periodo'];
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $count = $closing->close($tenantId, $period);

        return redirect()->route('provider-payments.courier-movements.apoyo-alza', ['periodo' => $period])
            ->with('status', "Período {$period} cerrado. {$count} pagos de Apoyo Alza grabados y edición bloqueada.");
    }

    public function reopen(Request $request, ProcessDeletionAuthorizer $authorizer, ApoyoAlzaClosingService $closing): RedirectResponse
    {
        $period = $request->validate(['periodo' => ['required', 'date_format:Ym']])['periodo'];
        $authorizer->authorize($request);
        $tenantId = Tenant::query()->where('code', '4N')->firstOrFail()->id;
        $count = $closing->reopen($tenantId, $period);

        return redirect()->route('provider-payments.courier-movements.apoyo-alza', ['periodo' => $period])
            ->with('status', "Período {$period} reabierto. {$count} pagos de Apoyo Alza retirados; ya puedes corregir y volver a cerrar.");
    }

    private function assertOpen(int $tenantId, string $period): void
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);
        if (ApoyoAlza::query()->where('tenant_id', $tenantId)->where('periodo', $period)
            ->whereNotNull('closed_at')->exists()) {
            throw ValidationException::withMessages(['periodo' => 'El período de Apoyo Alza está cerrado. Reábrelo con la clave maestra antes de modificarlo.']);
        }
    }
}
