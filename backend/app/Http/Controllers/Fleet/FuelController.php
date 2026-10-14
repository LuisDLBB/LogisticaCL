<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\FleetAccess;
use App\Fleet\FuelImport;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FuelController extends Controller
{
    public function index(Request $request, FleetAccess $access): View
    {
        $tenants = $access->availableTenants($request->user())->filter(fn ($tenant) => $access->allows($request->user(), $tenant, 'operations.fuel'));
        $data = $request->validate(['empresa' => ['nullable', 'string'], 'desde' => ['nullable', 'date_format:Y-m-d'], 'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'], 'patente' => ['nullable', 'string', 'max:20']]);
        $company = $data['empresa'] ?? $access->tenant($request)->code;
        abort_unless($company === 'all' || $tenants->contains('code', $company), 403);
        $query = DB::table('VEH_fuel_transactions as fuel')->join('tenants', 'tenants.id', '=', 'fuel.tenant_id')->whereIn('fuel.tenant_id', $tenants->pluck('id'));
        if ($company !== 'all') {
            $query->where('tenants.code', $company);
        }
        foreach (['desde' => '>=', 'hasta' => '<='] as $field => $operator) {
            if (! empty($data[$field])) {
                $query->where('fuel.transaction_date', $operator, $data[$field]);
            }
        }
        if (! empty($data['patente'])) {
            $query->where('fuel.plate', mb_strtoupper(trim($data['patente'])));
        }
        $totals = (clone $query)->selectRaw('COUNT(*) as count, SUM(volume) as volume, SUM(amount) as amount')->first();
        $transactions = $query->select('fuel.*', 'tenants.code as company')->orderByDesc('transaction_date')->orderByDesc('transaction_time')->paginate(50)->withQueryString();
        $canImport = $tenants->contains(fn ($tenant) => $access->allows($request->user(), $tenant, 'operations.fuel', 2));

        return view('fleet::fuel.index', compact('tenants', 'transactions', 'totals', 'company', 'canImport'));
    }

    public function store(Request $request, FleetAccess $access, FuelImport $importer): RedirectResponse
    {
        $data = $request->validate(['file' => ['nullable', 'file', 'extensions:xlsx,csv,txt', 'max:10240', 'required_without:pasted'], 'pasted' => ['nullable', 'string', 'max:15000000', 'required_without:file']]);
        $tenants = $access->availableTenants($request->user())->filter(fn ($tenant) => $access->allows($request->user(), $tenant, 'operations.fuel', 2));
        abort_if($tenants->isEmpty(), 403);
        $temp = null;
        try {
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $path = $file->getRealPath();
                $extension = strtolower($file->getClientOriginalExtension());
                $filename = $file->getClientOriginalName();
            } else {
                $temp = tempnam(sys_get_temp_dir(), 'fuel-paste-');
                file_put_contents($temp, $data['pasted']);
                $path = $temp;
                $extension = 'txt';
                $filename = 'Datos pegados desde Excel';
            }
            $rows = $importer->read($path, $extension);
            $result = $importer->import($rows, $request->user()->id, $tenants->pluck('id')->all(), $filename, app()->environment(['local', 'testing']) && config('fleet.auto_test_mode'));
        } finally {
            if ($temp !== null) {
                unlink($temp);
            }
        }

        return redirect()->route('fleet.page.operations-fuel', ['empresa' => 'all'])->with('status', $result['inserted'].' consumos ingresados; '.$result['duplicates'].' duplicados omitidos.');
    }
}
