<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\CostCenter;
use App\Models\CostCenterWeightRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CostCenterWeightRateMaintainerController
{
    public function index(Request $request): View
    {
        $centers = CostCenter::query()->orderBy('cost_center_code')->get();
        $selectedCenterCode = (string) $request->query('center', '');
        if ($selectedCenterCode !== '' && ! $centers->contains(fn (CostCenter $center): bool => (string) $center->cost_center_code === $selectedCenterCode)) {
            $selectedCenterCode = '';
        }

        $rates = CostCenterWeightRate::query()->with('costCenter')
            ->when($selectedCenterCode !== '', fn ($query) => $query->where('cost_center_code', $selectedCenterCode))
            ->orderBy('cost_center_code')->orderBy('final_weight')->get();

        return view('provider-payments::cost-center-weight-rates', [
            'centers' => $centers,
            'selectedCenterCode' => $selectedCenterCode,
            'nextCode' => ((int) $centers->max('cost_center_code')) + 1,
            'rates' => $rates,
            'rateValues' => $selectedCenterCode === '' ? collect() : $rates->pluck('value', 'final_weight'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rules = [
            'cost_center_code' => ['required', 'integer', Rule::exists('PPR_cost_centers', 'cost_center_code')->where('is_active', true)],
            'values' => ['required', 'array', 'size:20'],
        ];
        foreach (range(1, 20) as $weight) {
            $rules["values.{$weight}"] = ['required', 'integer', 'between:0,4294967295'];
        }
        $validated = $request->validate($rules);
        DB::transaction(function () use ($validated): void {
            foreach (range(1, 20) as $weight) {
                CostCenterWeightRate::updateOrCreate(
                    ['cost_center_code' => $validated['cost_center_code'], 'final_weight' => $weight],
                    ['value' => $validated['values'][$weight]],
                );
            }
        });

        return redirect()->route('provider-payments.maintainers.tarifas-cc', ['center' => $validated['cost_center_code']])
            ->with('status', 'Tarifas de 1 a 20 kg guardadas correctamente.');
    }

    public function update(Request $request, CostCenterWeightRate $rate): RedirectResponse
    {
        $validated = $request->validate([
            'value' => ['required', 'integer', 'between:0,4294967295'],
            'is_active' => ['required', 'boolean'],
        ]);
        $rate->update($validated);

        return redirect()->route('provider-payments.maintainers.tarifas-cc', ['center' => $rate->cost_center_code])
            ->with('status', 'Tarifa actualizada correctamente.');
    }
}
