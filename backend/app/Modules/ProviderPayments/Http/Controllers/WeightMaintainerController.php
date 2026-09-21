<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\Tenant;
use App\Models\WeightTransformation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WeightMaintainerController
{
    public function transformed(): View
    {
        $tenant = $this->tenant();

        return view('provider-payments::weights-transformed', [
            'groups' => WeightTransformation::query()->where('tenant_id', $tenant->id)
                ->selectRaw('transformed_weight, COUNT(*) as real_weight_count')
                ->groupBy('transformed_weight')->orderBy('transformed_weight')->get(),
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

        if (WeightTransformation::query()->where('tenant_id', $tenant->id)->where('source_weight', $sourceWeight)->exists()) {
            throw ValidationException::withMessages(['source_weight' => 'Este Peso Real ya existe en el maestro.']);
        }

        WeightTransformation::create([
            'tenant_id' => $tenant->id,
            'source_weight' => $sourceWeight,
            'comparison_key' => $this->weightKey($sourceWeight),
            'transformed_weight' => $validated['transformed_weight'],
            'is_active' => true,
        ]);

        return redirect()->route('provider-payments.maintainers.pesos.reales')
            ->with('status', "Peso Real {$sourceWeight} agregado correctamente.");
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
}
