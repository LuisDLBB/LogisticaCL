<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\CostCenter;
use App\Models\CostCenterWeightRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return view('provider-payments::cost-center-weight-rates', [
            'centers' => $centers,
            'selectedCenterCode' => $selectedCenterCode,
            'nextCode' => ((int) $centers->max('cost_center_code')) + 1,
            'rates' => CostCenterWeightRate::query()->with('costCenter')
                ->when($selectedCenterCode !== '', fn ($query) => $query->where('cost_center_code', $selectedCenterCode))
                ->orderBy('cost_center_code')->orderBy('final_weight')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'cost_center_code' => ['required', 'integer', Rule::exists('cost_centers', 'cost_center_code')->where('is_active', true)],
            'final_weight' => ['required', 'integer', 'between:1,255', Rule::unique('cost_center_weight_rates', 'final_weight')->where('cost_center_code', $request->input('cost_center_code'))],
            'value' => ['required', 'integer', 'between:0,4294967295'],
        ]);
        CostCenterWeightRate::create([...$validated, 'is_active' => true]);

        return redirect()->route('provider-payments.maintainers.tarifas-cc', ['center' => $validated['cost_center_code']])
            ->with('status', 'Tarifa por kilo creada correctamente.');
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
