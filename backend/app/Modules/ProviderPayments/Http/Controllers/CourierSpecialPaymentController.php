<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\CourierSpecialPayment;
use App\Models\Tenant;
use App\Modules\ProviderPayments\Services\CourierSpecialPaymentImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CourierSpecialPaymentController
{
    public function index(Request $request): View
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $periods = CourierSpecialPayment::query()->where('tenant_id', $tenant->id)
            ->selectRaw('periodo, COUNT(*) as total, SUM(monto) as monto_total')
            ->groupBy('periodo')->orderByDesc('periodo')->get();
        $selectedPeriod = (string) $request->query('periodo', $periods->first()?->periodo ?? '');
        if ($selectedPeriod !== '' && ! $periods->contains('periodo', $selectedPeriod)) {
            $selectedPeriod = $periods->first()?->periodo ?? '';
        }
        $payments = CourierSpecialPayment::query()->where('tenant_id', $tenant->id)
            ->when($selectedPeriod !== '', fn ($query) => $query->where('periodo', $selectedPeriod))
            ->orderByDesc('fecha')->orderByDesc('id')->paginate(100)->withQueryString();

        return view('provider-payments::courier-special-payments', compact('periods', 'selectedPeriod', 'payments'));
    }

    public function store(Request $request, CourierSpecialPaymentImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx', 'max:102400'],
        ]);
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $file = $validated['file'];
        $result = $importer->import($file->getRealPath(), $file->getClientOriginalName(), $tenant->id);

        return redirect()->route('provider-payments.courier-movements.especiales')
            ->with('status', "Se cargaron {$result['imported']} pagos especiales; {$result['existing']} filas ya estaban registradas.");
    }
}
