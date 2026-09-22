<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\CourierMovement;
use App\Models\Tenant;
use App\Models\WeightTransformation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function real(): View
    {
        $tenant = $this->tenant();

        return view('provider-payments::weights-real', [
            'weights' => WeightTransformation::query()->where('tenant_id', $tenant->id)
                ->orderByRaw('CAST(comparison_key AS DECIMAL(12, 4))')->orderBy('source_weight')->get(),
        ]);
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

        $route = $request->input('return_to') === 'transformed'
            ? 'provider-payments.maintainers.pesos.transformados'
            : 'provider-payments.maintainers.pesos.reales';

        return redirect()->route($route, $route === 'provider-payments.maintainers.pesos.transformados' ? ['discover' => 1] : [])
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
