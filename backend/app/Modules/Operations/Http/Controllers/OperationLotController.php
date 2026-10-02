<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\OperationAccess;
use App\Modules\Operations\Services\OperationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OperationLotController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = OperationAccess::tenant($request);

        $loads = DB::table('Ope_Cargas')->where('tenant_id', $tenant)->where('status', 'completed')->orderByDesc('id')->get(['id', 'filename', 'source_type', 'created_at', 'row_count']);
        $selectedLoad = $loads->firstWhere('id', $request->integer('load'));

        return view('operations::lots', [
            'lots' => DB::table('Ope_Lotes')->where('tenant_id', $tenant)->orderByDesc('id')->paginate(20),
            'loads' => $loads,
            'selectedMasterLoadId' => $selectedLoad?->source_type === 'master' ? $selectedLoad->id : null,
            'selectedReceptionLoadIds' => $selectedLoad?->source_type === 'reception' ? [$selectedLoad->id] : [],
        ]);
    }

    public function store(Request $request, OperationWorkflow $workflow): RedirectResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:160'], 'operation_date' => ['required', 'date_format:Y-m-d'], 'master_load_id' => ['required', 'integer'], 'reception_load_ids' => ['required', 'array', 'min:1'], 'reception_load_ids.*' => ['required', 'integer', 'distinct']]);
        $id = $workflow->createLot(OperationAccess::tenant($request), $request->user()->id, $input);

        return redirect()->route('operations.lots.show', $id)->with('status', 'Proceso preparado desde Recepción. Revisa las incidencias y las agencias.');
    }

    public function show(Request $request, int $lot): View
    {
        $record = OperationAccess::lot($request, $lot);
        $packages = DB::table('Ope_Bultos')->where('lot_id', $lot);
        if ($request->filled('search')) {
            $packages->where('tracking', 'like', '%'.$request->string('search')->toString().'%');
        }

        return view('operations::lot-show', [
            'lot' => $record, 'packages' => $packages->orderBy('tracking')->paginate(40)->withQueryString(),
            'count' => DB::table('Ope_Bultos')->where(['lot_id' => $lot, 'excluded' => false])->count(),
            'weight' => DB::table('Ope_Bultos')->where(['lot_id' => $lot, 'excluded' => false])->sum('weight'),
            'issues' => DB::table('Ope_Incidencias')->where('lot_id', $lot)->orderByRaw('resolved_at IS NOT NULL')->orderBy('id')->get(),
            'coverages' => DB::table('PPR_coverages')->where(['tenant_id' => OperationAccess::tenant($request), 'is_active' => true])->orderBy('commune_name')->get(),
            'audit' => DB::table('Ope_Auditoria')->where(['tenant_id' => OperationAccess::tenant($request), 'entity' => 'lote', 'entity_id' => $lot])->orderByDesc('id')->get(),
        ]);
    }

    public function resolve(Request $request, int $lot, int $issue, OperationWorkflow $workflow): RedirectResponse
    {
        OperationAccess::lot($request, $lot);
        OperationAccess::requireSupervisor($request);
        $input = $request->validate(['action' => ['required', Rule::in(['reading', 'coverage', 'exclude'])], 'reason' => ['required', 'string', 'min:10', 'max:1000'], 'reading_id' => ['required_if:action,reading', 'nullable', 'integer'], 'coverage_id' => ['required_if:action,coverage', 'nullable', 'integer']]);
        $workflow->resolve(OperationAccess::tenant($request), $request->user()->id, $lot, $issue, $input);

        return back()->with('status', 'Resolución registrada con trazabilidad.');
    }
}
