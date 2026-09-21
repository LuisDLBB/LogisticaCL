<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\CourierImportError;
use App\Models\Coverage;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CoverageMaintainerController
{
    public function create(Request $request): View
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();

        return view('provider-payments::coverage-create', [
            'commune' => trim((string) $request->query('commune')),
            'templates' => Coverage::query()->where('tenant_id', $tenant->id)->where('is_active', true)
                ->orderBy('commune_name')->orderBy('provider_name_source')->get(),
            'coverages' => Coverage::query()->where('tenant_id', $tenant->id)->orderBy('commune_name')->orderBy('provider_name_source')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'commune_name' => ['required', 'string', 'max:150'],
            'template_id' => ['required', 'integer'],
        ]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $template = Coverage::query()->where('tenant_id', $tenant->id)->whereKey($validated['template_id'])->firstOrFail();
        $coverage = $template->replicate(['effective_from', 'effective_to']);
        $coverage->tenant_id = $tenant->id;
        $coverage->commune_name = trim($validated['commune_name']);
        $coverage->is_active = true;
        $coverage->save();

        $snapshot = $request->session()->get('courier_review');
        if ($snapshot) {
            CourierImportError::query()->where('batch_id', $snapshot['batch_id'])->where('category', 'coverages')
                ->where('source_key', $coverage->commune_name)->update(['status' => 'RESUELTO', 'exclude_from_import' => false]);
            $excluded = array_values(array_diff($request->session()->get('courier_review_exclusions.coverages', []), [$coverage->commune_name]));
            $request->session()->put('courier_review_exclusions.coverages', $excluded);
        }

        $route = $snapshot ? 'provider-payments.courier-movements.review-parameters' : 'provider-payments.maintainers.coberturas';

        return redirect()->route($route)->with('status', "Cobertura creada para {$coverage->commune_name} usando {$template->commune_name} como plantilla.");
    }

    public function update(Request $request, Coverage $coverage): RedirectResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        abort_unless($coverage->tenant_id === $tenant->id, 404);
        $coverage->update($request->validate([
            'matrix_commune_name' => ['nullable', 'string', 'max:150'], 'zone' => ['required', 'string', 'max:20'],
            'return_payment_applies' => ['required', 'boolean'], 'return_value' => ['nullable', 'numeric', 'min:0'],
            'delivery_frequency' => ['nullable', 'string', 'max:120'], 'delivery_type' => ['nullable', 'string', 'max:120'],
            'region_code' => ['nullable', 'integer', 'min:1', 'max:99'], 'route_code' => ['nullable', 'string', 'max:100'],
            'trunk_name' => ['nullable', 'string', 'max:160'], 'post_name' => ['nullable', 'string', 'max:160'],
            'effective_from' => ['nullable', 'date'], 'effective_to' => ['nullable', 'date'], 'is_active' => ['required', 'boolean'],
        ]));

        return back()->with('status', "Cobertura {$coverage->commune_name} actualizada; comuna y proveedor permanecieron protegidos.");
    }
}
