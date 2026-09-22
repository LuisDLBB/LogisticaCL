<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\CourierMovement;
use App\Models\RealWeight;
use App\Models\Tenant;
use App\Models\WeightTransformation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WeightMaintainerController
{
    public function transformed(Request $request): View
    {
        $tenant = $this->tenant();
        $weights = WeightTransformation::query()->where('tenant_id', $tenant->id)
            ->orderByRaw('CAST(comparison_key AS DECIMAL(12, 4))')->orderBy('source_weight')->get();
        $knownKeys = $weights->pluck('comparison_key')->map(fn ($key): string => (string) $key)->flip();
        $newWeights = collect();
        if ($request->boolean('discover')) {
            $newWeights = CourierMovement::query()->where('tenant_id', $tenant->id)->whereNotNull('weight_kg')
                ->selectRaw('weight_kg, COUNT(*) AS movement_count')->groupBy('weight_kg')->orderBy('weight_kg')->get()
                ->map(function (CourierMovement $movement): array {
                    $sourceWeight = $this->sourceWeight((string) $movement->weight_kg);

                    return [
                        'source_weight' => $sourceWeight,
                        'comparison_key' => $this->weightKey($sourceWeight),
                        'suggested_weight' => max(1, (int) ceil((float) $movement->weight_kg)),
                        'movement_count' => (int) $movement->movement_count,
                    ];
                })->reject(fn (array $weight): bool => $knownKeys->has($weight['comparison_key']))->values();
        }

        return view('provider-payments::weights-transformed', [
            'weights' => $weights,
            'newWeights' => $newWeights,
            'discovering' => $request->boolean('discover'),
        ]);
    }

    public function real(Request $request): View
    {
        $tenant = $this->tenant();
        $period = trim((string) $request->query('period', ''));
        $merchant = trim((string) $request->query('merchant', ''));
        $service = trim((string) $request->query('service', ''));
        $search = trim((string) $request->query('q', ''));
        $base = RealWeight::query()->where('tenant_id', $tenant->id);
        $periods = (clone $base)->selectRaw("strftime('%Y-%m', fecha_proceso) AS period")
            ->distinct()->orderByDesc('period')->pluck('period');
        if ($period === '') {
            $period = (string) ($periods->first() ?? '');
        }
        $merchants = (clone $base)->select('comerciante')->distinct()->orderBy('comerciante')->pluck('comerciante');
        $services = (clone $base)->select('servicio')->distinct()->orderBy('servicio')->pluck('servicio');
        $rows = (clone $base)
            ->when($period !== '', fn ($query) => $query->whereRaw("strftime('%Y-%m', fecha_proceso) = ?", [$period]))
            ->when($merchant !== '', fn ($query) => $query->where('comerciante', $merchant))
            ->when($service !== '', fn ($query) => $query->where('servicio', $service))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('seguimiento_paquete', 'like', '%'.$search.'%')
                    ->orWhere('codigo_seguimiento', 'like', '%'.$search.'%')
                    ->orWhere('comerciante', 'like', '%'.$search.'%')
                    ->orWhere('servicio', 'like', '%'.$search.'%');
            }))
            ->orderByDesc('fecha_proceso')->orderByDesc('id')->paginate(100)->withQueryString();

        return view('provider-payments::weights-real', compact(
            'rows', 'periods', 'period', 'merchants', 'merchant', 'services', 'service', 'search',
        ));
    }

    public function syncRealWeights(): RedirectResponse
    {
        $tenant = $this->tenant();
        $updated = 0;
        $withoutMatch = 0;
        CourierMovement::query()->where('tenant_id', $tenant->id)->select(['id', 'tenant_id', 'tracking_number', 'weight_kg', 'peso_transformado'])
            ->chunkById(1000, function ($movements) use ($tenant, &$updated, &$withoutMatch): void {
                $realWeights = RealWeight::query()->where('tenant_id', $tenant->id)
                    ->whereIn('seguimiento_paquete', $movements->pluck('tracking_number'))
                    ->pluck('peso_real', 'seguimiento_paquete');
                $updates = [];
                foreach ($movements as $movement) {
                    $realWeight = $realWeights->get($movement->tracking_number);
                    if ($realWeight === null) {
                        $withoutMatch++;
                        continue;
                    }
                    $updates[] = [
                        'id' => $movement->id,
                        'tenant_id' => $movement->tenant_id,
                        'tracking_number' => $movement->tracking_number,
                        'weight_kg' => $movement->weight_kg,
                        'peso_real' => (int) $realWeight,
                        'updated_at' => now(),
                    ];
                }
                if ($updates !== []) {
                    DB::table('movimientos_courier')->upsert($updates, ['id'], ['peso_real', 'updated_at']);
                    $updated += count($updates);
                }
            });

        return redirect()->route('provider-payments.maintainers.pesos.reales')
            ->with('status', number_format($updated, 0, ',', '.').' movimientos Courier actualizados con Peso Real. '.number_format($withoutMatch, 0, ',', '.').' sin coincidencia de Seguimiento paquete en Peso_Real; esos registros conservan su valor anterior.');
    }

    public function storeReal(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'source_weight' => ['required', 'string', 'max:100'],
            'transformed_weight' => ['required', 'integer', 'min:1'],
        ]);
        $tenant = $this->tenant();
        $sourceWeight = trim($validated['source_weight']);

        $comparisonKey = $this->weightKey($sourceWeight);
        if (WeightTransformation::query()->where('tenant_id', $tenant->id)->where('comparison_key', $comparisonKey)->exists()) {
            throw ValidationException::withMessages(['source_weight' => 'Este Peso Fuente ya existe en el maestro.']);
        }

        WeightTransformation::create([
            'tenant_id' => $tenant->id,
            'source_weight' => $sourceWeight,
            'comparison_key' => $comparisonKey,
            'transformed_weight' => $validated['transformed_weight'],
            'is_active' => true,
        ]);

        return redirect()->route('provider-payments.maintainers.pesos.transformados', $request->input('return_to') === 'discovery' ? ['discover' => 1] : [])
            ->with('status', "Peso Fuente {$sourceWeight} agregado correctamente.");
    }

    public function update(Request $request, WeightTransformation $weight): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($weight->tenant_id === $tenant->id, 404);
        $weight->update($request->validate([
            'transformed_weight' => ['required', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
        ]));

        return redirect()->route('provider-payments.maintainers.pesos.transformados')
            ->with('status', "Peso Fuente {$weight->source_weight} actualizado correctamente.");
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->where('code', '4N')->firstOrFail();
    }

    private function weightKey(string $value): string
    {
        return preg_match('/-?\d+(?:[.,]\d+)?/', $value, $matches)
            ? rtrim(rtrim(number_format((float) str_replace(',', '.', $matches[0]), 6, '.', ''), '0'), '.')
            : mb_strtolower(trim($value));
    }

    private function sourceWeight(string $value): string
    {
        $number = rtrim(rtrim(number_format((float) str_replace(',', '.', $value), 3, '.', ''), '0'), '.');

        return $number.' kg';
    }
}
