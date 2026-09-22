<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\Coverage;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CourierMovementCompileController
{
    private const PROCESS_TYPES = ['Variable', 'Lanas', 'Retornos'];

    public function index(): View
    {
        return view('provider-payments::compile', ['title' => 'Compilar Movimientos Courier']);
    }

    public function work(Request $request): View
    {
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
        $rows = CourierPaymentMovement::query()->where('tenant_id', $tenant->id)
            ->when($period !== '', fn ($query) => $query->where('periodo', $period), fn ($query) => $query->whereRaw('1 = 0'))
            ->orderByDesc('id')->paginate(100)->withQueryString();

        return view('provider-payments::compile-work', compact('periods', 'period', 'processes', 'compiled', 'rows'));
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
            ->whereNotNull('provider_id')->with('provider')->get()
            ->filter(fn (Coverage $coverage): bool => $coverage->provider !== null)
            ->groupBy(fn (Coverage $coverage): string => $this->communeKey($coverage->commune_name));
        $count = 0;
        $pendingProviders = 0;
        CourierMovement::query()->where('tenant_id', $tenant->id)->whereIn('nombre_proceso', $names)
            ->with('client')->chunkById(500, function ($movements) use ($tenant, $coverages, &$count, &$pendingProviders): void {
                $now = now();
                $rows = [];
                foreach ($movements as $movement) {
                    $matches = $coverages->get($this->communeKey((string) $movement->destination_commune_name), collect());
                    $providers = $matches->pluck('provider_id')->unique();
                    $coverage = $providers->count() === 1 ? $matches->first() : null;
                    if ($coverage === null) {
                        $pendingProviders++;
                    }
                    $provider = $coverage?->provider;
                    $rows[] = [
                        'tenant_id' => $tenant->id,
                        'courier_movement_id' => $movement->id,
                        'zona' => $coverage?->zone,
                        'tipo_pago' => $movement->tipo_pago ?: substr((string) $movement->nombre_proceso, 7),
                        'nombre_proceso' => substr((string) $movement->nombre_proceso, 7),
                        'periodo' => substr((string) $movement->nombre_proceso, 0, 6),
                        'codigo_seguimiento' => $movement->tracking_code,
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
}
