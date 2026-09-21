<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\CostCenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CostCenterMaintainerController
{
    public function index(): View
    {
        return view('provider-payments::cost-centers-index', [
            'costCenters' => CostCenter::query()->orderBy('cost_center_code')->get(),
            'nextCode' => ((int) CostCenter::query()->max('cost_center_code')) + 1,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'dispatch_guide_detail' => ['required', 'string', 'max:255'],
            'additional_kilo_value' => ['required', 'integer', 'min:0'],
        ]);
        $detail = trim($validated['dispatch_guide_detail']);

        if (CostCenter::query()->whereRaw('LOWER(dispatch_guide_detail) = ?', [mb_strtolower($detail)])->exists()) {
            throw ValidationException::withMessages(['dispatch_guide_detail' => 'Este centro de costo ya existe.']);
        }

        $costCenter = DB::transaction(function () use ($detail, $validated): CostCenter {
            return CostCenter::create([
                'cost_center_code' => ((int) CostCenter::query()->max('cost_center_code')) + 1,
                'dispatch_guide_detail' => $detail,
                'additional_kilo_value' => $validated['additional_kilo_value'],
                'is_active' => true,
            ]);
        });

        return redirect()->route('provider-payments.maintainers.centro-de-costos')
            ->with('status', "Centro de costo {$costCenter->dispatch_guide_detail} creado con ID {$costCenter->cost_center_code}.");
    }
}
