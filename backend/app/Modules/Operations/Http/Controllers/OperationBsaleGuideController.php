<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\OperationAccess;
use App\Modules\Operations\Services\OperationBsaleGuideService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OperationBsaleGuideController extends Controller
{
    public function index(Request $request): View
    {
        OperationAccess::requireSupervisor($request);
        $emissions = DB::table('Ope_GuiasBsale as emission')
            ->leftJoin('Ope_Guias as guide', 'guide.id', '=', 'emission.guide_id')
            ->where('emission.tenant_id', OperationAccess::tenant($request))
            ->orderByDesc('emission.id')
            ->select('emission.*', 'guide.id as internal_guide_id')
            ->paginate(30);

        return view('operations::bsale-guides', [
            'emissions' => $emissions,
            'linesByEmission' => DB::table('Ope_GuiasBsaleLineas')
                ->whereIn('emission_id', $emissions->pluck('id'))
                ->orderBy('line_number')->get()->groupBy('emission_id'),
        ]);
    }

    public function store(Request $request, int $guide, OperationBsaleGuideService $bsale): RedirectResponse
    {
        OperationAccess::requireSupervisor($request);
        DB::table('Ope_Guias')->whereIn('departure_id', OperationAccess::departures($request)->select('id'))->where('id', $guide)->firstOrFail();
        $emission = $bsale->emit(OperationAccess::tenant($request), $request->user()->id, $guide);

        return redirect()->route('operations.guides.show', $guide)->with('status', $emission->estado === 'generada'
            ? ((int) $emission->sheet_count === 1
                ? 'Guía de Despacho Electrónica generada en Bsale.'
                : $emission->sheet_count.' guías de despacho generadas en Bsale para esta salida.')
            : OperationBsaleGuideService::UNCERTAIN_MESSAGE);
    }

    public function reconcile(Request $request, int $emission, OperationBsaleGuideService $bsale): RedirectResponse
    {
        OperationAccess::requireSupervisor($request);
        $input = $request->validate([
            'outcome' => ['required', Rule::in(['created', 'not_created'])],
        ]);
        $data = $input['outcome'] === 'created'
            ? $request->validate([
                'shipping_id' => ['required', 'integer', 'min:1'],
                'document_id' => ['required', 'integer', 'min:1'],
                'numero' => ['required', 'string', 'max:100'],
                'url_pdf' => ['required', 'url', 'starts_with:https://,http://'],
                'url_publica' => ['required', 'url', 'starts_with:https://,http://'],
            ])
            : $request->validate(['confirmed_absent' => ['accepted']]);
        $bsale->reconcile(OperationAccess::tenant($request), $request->user()->id, $emission, $input['outcome'], $data);

        return redirect()->route('operations.guides.bsale.index')->with('status', $input['outcome'] === 'created'
            ? 'Emisión Bsale conciliada como generada.'
            : 'Se confirmó que Bsale no creó la guía. Si la versión sigue aprobada, puede enviarse nuevamente.');
    }
}
