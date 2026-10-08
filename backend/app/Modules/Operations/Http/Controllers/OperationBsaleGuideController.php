<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\OperationAccess;
use App\Modules\Operations\Services\OperationBsaleGuideService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class OperationBsaleGuideController extends Controller
{
    public function overview(Request $request): View
    {
        $tenant = OperationAccess::tenant($request);
        $dates = DB::table('Ope_ProgramacionSalidas as departure')
            ->join('Ope_Lotes as lot', 'lot.id', '=', 'departure.lot_id')
            ->where('lot.tenant_id', $tenant)
            ->where('departure.status', '<>', 'cancelled')
            ->distinct()->orderByDesc('departure.departure_date')->pluck('departure.departure_date');
        $requestedDate = $request->query('fecha');
        $selectedDate = is_string($requestedDate) && $dates->contains($requestedDate) ? $requestedDate : $dates->first();
        $processes = $selectedDate === null ? collect() : DB::table('Ope_ProgramacionSalidas as departure')
            ->join('Ope_Lotes as lot', 'lot.id', '=', 'departure.lot_id')
            ->where('lot.tenant_id', $tenant)
            ->where('departure.departure_date', $selectedDate)
            ->where('departure.status', '<>', 'cancelled')
            ->groupBy('lot.id', 'lot.name', 'lot.operation_date')
            ->orderByDesc('lot.id')
            ->get(['lot.id', 'lot.name', 'lot.operation_date'])
            ->map(function ($lot) use ($selectedDate): object {
                $lot->departure_date = $selectedDate;

                return $lot;
            });

        return view('operations::guide-generation-overview', compact('dates', 'selectedDate', 'processes'));
    }

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

    public function store(Request $request, int $guide, OperationBsaleGuideService $bsale): JsonResponse|RedirectResponse
    {
        OperationAccess::requireSupervisor($request);
        DB::table('Ope_Guias')->whereIn('departure_id', OperationAccess::departures($request)->select('id'))->where('id', $guide)->firstOrFail();
        $emission = $bsale->emit(OperationAccess::tenant($request), $request->user()->id, $guide);

        if ($request->expectsJson()) {
            return response()->json([
                'estado' => $emission->estado,
                'guide_id' => $guide,
                'generated_count' => $emission->generated_count,
                'sheet_count' => $emission->sheet_count,
                'message' => $emission->estado === 'generada' ? 'Guía generada en Bsale.' : OperationBsaleGuideService::UNCERTAIN_MESSAGE,
            ]);
        }

        return redirect()->route('operations.guides.show', $guide)->with('status', $emission->estado === 'generada'
            ? ((int) $emission->sheet_count === 1
                ? 'Guía de Despacho Electrónica generada en Bsale.'
                : $emission->sheet_count.' guías de despacho generadas en Bsale para esta salida.')
            : OperationBsaleGuideService::UNCERTAIN_MESSAGE);
    }

    public function downloadPdf(Request $request, int $emission): Response
    {
        OperationAccess::requireSupervisor($request);
        $record = DB::table('Ope_GuiasBsale')->where([
            'id' => $emission,
            'tenant_id' => OperationAccess::tenant($request),
            'estado' => 'generada',
        ])->firstOrFail();
        abort_unless(OperationBsaleGuideService::downloadablePdfUrl($record->url_pdf), 404);

        $url = $record->url_pdf;
        $redirects = 0;
        while (true) {
            try {
                $pdf = Http::connectTimeout(10)->timeout(60)
                    ->withOptions([
                        'allow_redirects' => false,
                        'verify' => config('services.bsale.ca_bundle') ?: true,
                    ])
                    ->get($url);
            } catch (Throwable) {
                abort(502, 'No se pudo obtener el PDF de Bsale. Inténtalo nuevamente.');
            }
            if (! in_array($pdf->status(), [301, 302, 303, 307, 308], true)) {
                break;
            }

            $redirects++;
            $url = $pdf->header('Location');
            abort_unless($redirects <= 3 && OperationBsaleGuideService::trustedPdfRedirectUrl($url), 502,
                'Bsale redirigió el PDF a una dirección no admitida. Ábrelo desde el enlace de Bsale.');
        }
        abort_unless($pdf->successful() && str_starts_with($pdf->body(), '%PDF-'), 502,
            'Bsale no entregó un PDF descargable. Puedes abrirlo desde el enlace de Bsale.');

        $number = preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($record->numero ?: $record->id));
        $filename = "GDE-Bsale-{$number}-Hoja-{$record->sheet_number}-de-{$record->sheet_count}.pdf";

        return response($pdf->body(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
