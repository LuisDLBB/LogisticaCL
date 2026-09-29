<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\BaseServicio;
use App\Models\Client;
use App\Models\Provider;
use App\Models\Tenant;
use App\Modules\ProviderPayments\Services\BaseServicioClosingService;
use App\Modules\ProviderPayments\Services\BaseServicioImporter;
use App\Modules\ProviderPayments\Services\MonthlyPaymentClosingService;
use App\Modules\ProviderPayments\Services\ProcessDeletionAuthorizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BaseServicioController
{
    public function index(Request $request): View
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $periods = BaseServicio::query()->where('tenant_id', $tenant->id)
            ->selectRaw('periodo, COUNT(*) AS total, SUM(valor_final) AS monto_total')
            ->groupBy('periodo')->orderByDesc('periodo')->get();
        $period = (string) $request->query('periodo', $periods->first()?->periodo ?? '');
        if ($period !== '' && ! $periods->contains('periodo', $period)) {
            $period = $periods->first()?->periodo ?? '';
        }
        $status = in_array($request->query('estado'), ['todos', 'pendientes', 'completos'], true)
            ? $request->query('estado') : 'pendientes';
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $clientId = filter_var($request->query('cliente'), FILTER_VALIDATE_INT) ?: null;
        $providerId = filter_var($request->query('proveedor'), FILTER_VALIDATE_INT) ?: null;
        $base = BaseServicio::query()->where('tenant_id', $tenant->id)
            ->when($period !== '', fn ($query) => $query->where('periodo', $period), fn ($query) => $query->whereRaw('1 = 0'));
        $summary = (clone $base)->selectRaw('COUNT(*) AS total, COALESCE(SUM(valor_final), 0) AS amount,
            SUM(CASE WHEN client_id IS NULL THEN 1 ELSE 0 END) AS missing_clients,
            SUM(CASE WHEN provider_id IS NULL THEN 1 ELSE 0 END) AS missing_providers,
            SUM(CASE WHEN client_id IS NOT NULL AND provider_id IS NOT NULL THEN 1 ELSE 0 END) AS complete,
            SUM(CASE WHEN closed_at IS NOT NULL THEN 1 ELSE 0 END) AS closed_count')->first();
        $monthClosed = $period !== '' && DB::table('Cierres_Pagos')->where('tenant_id', $tenant->id)->where('periodo', $period)->exists();
        $isClosed = $monthClosed || ((int) $summary->total > 0 && (int) $summary->closed_count === (int) $summary->total);
        if (! $request->has('estado') && (int) $summary->missing_clients + (int) $summary->missing_providers === 0) {
            $status = 'todos';
        }
        $rows = (clone $base)
            ->when($status === 'pendientes', fn ($query) => $query->where(fn ($query) => $query->whereNull('client_id')->orWhereNull('provider_id')))
            ->when($status === 'completos', fn ($query) => $query->whereNotNull('client_id')->whereNotNull('provider_id'))
            ->when($clientId, fn ($query) => $query->where('client_id', $clientId))
            ->when($providerId, fn ($query) => $query->where('provider_id', $providerId))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                foreach (['cliente_origen', 'rut_cliente_origen', 'transportista', 'razon_social_proveedor_origen',
                    'rut_proveedor_origen', 'usuario', 'seguimiento_paquete', 'comuna_destino', 'servicio'] as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            }))
            ->orderBy('fecha_carga')->orderBy('id')->paginate(50)->withQueryString();
        $clients = Client::query()->where('tenant_id', $tenant->id)->orderBy('commercial_name')->get();
        $providers = Provider::query()->where('tenant_id', $tenant->id)->orderBy('operational_name')->get();

        return view('provider-payments::base-servicios', compact(
            'periods', 'period', 'status', 'search', 'clientId', 'providerId', 'summary', 'isClosed', 'monthClosed', 'rows', 'clients', 'providers',
        ));
    }

    public function store(Request $request, BaseServicioImporter $importer): RedirectResponse
    {
        $validated = $request->validate(['file' => ['required', 'file', 'extensions:xlsx,csv', 'max:20480']]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $file = $validated['file'];
        $result = $importer->import($file->getRealPath(), $file->getClientOriginalName(), $tenant->id);

        return redirect()->route('provider-payments.courier-movements.servicios', [
            'periodo' => $result['periods'][0], 'estado' => 'pendientes',
        ])->with('status', sprintf('%s servicios cargados; %s filas ya existían. %s requieren revisar cliente o proveedor.',
            number_format($result['imported'], 0, ',', '.'), number_format($result['existing'], 0, ',', '.'),
            number_format($result['pending'], 0, ',', '.')));
    }

    public function associatePage(Request $request): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:50'],
            'rows.*.client_id' => ['present', 'nullable', 'integer', Rule::exists('clients', 'id')->where('tenant_id', $tenant->id)],
            'rows.*.provider_id' => ['present', 'nullable', 'integer', Rule::exists('providers', 'id')->where('tenant_id', $tenant->id)],
            'rows.*.fecha_carga' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'rows.*.zona' => ['sometimes', 'required', 'string', 'max:20'],
            'rows.*.direccion' => ['sometimes', 'required', 'string', 'max:2000'],
            'rows.*.comuna_destino' => ['sometimes', 'required', 'string', 'max:150'],
            'rows.*.servicio' => ['sometimes', 'required', 'string', 'max:160'],
            'rows.*.peso' => ['sometimes', 'required', 'numeric', 'min:0.001'],
            'rows.*.valor_final' => ['sometimes', 'required', 'integer', 'min:0'],
            'rows.*.usuario' => ['sometimes', 'required', 'string', 'max:160'],
            'rows.*.empresa' => ['sometimes', 'required', 'string', 'max:20'],
            'return_periodo' => ['required', 'regex:/^\d{6}$/'],
            'return_estado' => ['nullable', 'in:todos,pendientes,completos'],
            'return_q' => ['nullable', 'string', 'max:100'],
            'return_cliente' => ['nullable', 'integer'],
            'return_proveedor' => ['nullable', 'integer'],
            'return_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $period = $validated['return_periodo'];
        $ids = array_keys($validated['rows']);
        $clients = Client::query()->where('tenant_id', $tenant->id)
            ->whereIn('id', collect($validated['rows'])->pluck('client_id')->filter()->unique())->get()->keyBy('id');
        $providers = Provider::query()->where('tenant_id', $tenant->id)
            ->whereIn('id', collect($validated['rows'])->pluck('provider_id')->filter()->unique())->get()->keyBy('id');
        DB::transaction(function () use ($validated, $ids, $tenant, $period, $clients, $providers): void {
            MonthlyPaymentClosingService::assertOpen($tenant->id, $period);
            $records = BaseServicio::query()->where('tenant_id', $tenant->id)->where('periodo', $period)
                ->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            abort_unless($records->count() === count($ids), 404);
            if (BaseServicio::query()->where('tenant_id', $tenant->id)->where('periodo', $period)
                ->whereNotNull('closed_at')->exists()) {
                throw ValidationException::withMessages(['rows' => 'El período está cerrado. Reábrelo con la clave maestra antes de modificar asociaciones.']);
            }
            foreach ($validated['rows'] as $id => $selection) {
                $record = $records->get($id);
                $client = $clients->get($selection['client_id'] ?? null);
                $provider = $providers->get($selection['provider_id'] ?? null);
                $record->update([
                    ...BaseServicioImporter::clientFields($client),
                    ...BaseServicioImporter::providerFields($provider),
                    ...array_intersect_key($selection, array_flip([
                        'fecha_carga', 'zona', 'direccion', 'comuna_destino', 'servicio', 'peso',
                        'valor_final', 'usuario', 'empresa',
                    ])),
                ]);
            }
        });

        return redirect()->route('provider-payments.courier-movements.servicios', array_filter([
            'periodo' => $period, 'estado' => $validated['return_estado'] ?? 'pendientes',
            'q' => $validated['return_q'] ?? null, 'cliente' => $validated['return_cliente'] ?? null,
            'proveedor' => $validated['return_proveedor'] ?? null, 'page' => $validated['return_page'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== ''))
            ->with('status', count($ids).' servicios guardados.');
    }

    public function close(Request $request, BaseServicioClosingService $closing): RedirectResponse
    {
        $period = $request->validate(['periodo' => ['required', 'date_format:Ym']])['periodo'];
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $count = $closing->close($tenant->id, $period);

        return redirect()->route('provider-payments.courier-movements.servicios', ['periodo' => $period, 'estado' => 'todos'])
            ->with('status', "Período {$period} cerrado. {$count} pagos de Servicios grabados y edición bloqueada.");
    }

    public function reopen(Request $request, ProcessDeletionAuthorizer $authorizer, BaseServicioClosingService $closing): RedirectResponse
    {
        $period = $request->validate(['periodo' => ['required', 'date_format:Ym']])['periodo'];
        $authorizer->authorize($request);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $count = $closing->reopen($tenant->id, $period);

        return redirect()->route('provider-payments.courier-movements.servicios', ['periodo' => $period, 'estado' => 'todos'])
            ->with('status', "Período {$period} reabierto. {$count} pagos de Servicios retirados; ya puedes corregir y volver a cerrar.");
    }
}
