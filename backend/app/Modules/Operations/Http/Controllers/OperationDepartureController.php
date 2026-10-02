<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\OperationAccess;
use App\Modules\Operations\Services\OperationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationDepartureController extends Controller
{
    public function index(Request $request, int $lot): View
    {
        $record = OperationAccess::lot($request, $lot);
        $counts = DB::table('Ope_Bultos')->where(['lot_id' => $lot, 'excluded' => false])->select('coverage_id')->selectRaw('COUNT(*) as count, SUM(weight) as weight')->groupBy('coverage_id')->get()->keyBy('coverage_id');
        $configurations = DB::table('Ope_GuiaConfiguraciones as c')->join('PPR_coverages as v', 'v.id', '=', 'c.coverage_id')->join('Ope_Ubicaciones as o', 'o.id', '=', 'c.origin_id')->join('Ope_Ubicaciones as d', 'd.id', '=', 'c.destination_id')->where('c.tenant_id', OperationAccess::tenant($request))->where('c.is_active', true)->whereIn('c.coverage_id', $counts->keys())->orderBy('c.sequence')->orderBy('v.commune_name')->select('c.*', 'v.commune_name', 'v.trunk_name', 'v.post_name', 'o.name as origin_name', 'd.name as destination_name')->get();
        $members = DB::table('Ope_BultoTramos')->whereIn('departure_id', DB::table('Ope_ProgramacionSalidas')->where('lot_id', $lot)->select('id'))->pluck('configuration_id')->unique();
        $covered = $configurations->pluck('coverage_id')->unique();

        return view('operations::departures', ['lot' => $record, 'configurations' => $configurations, 'counts' => $counts, 'scheduled' => $members, 'missing' => DB::table('PPR_coverages')->whereIn('id', $counts->keys()->diff($covered))->orderBy('commune_name')->get(), 'departures' => DB::table('Ope_ProgramacionSalidas')->where('lot_id', $lot)->orderBy('departure_date')->orderBy('id')->get(), 'blocked' => DB::table('Ope_Incidencias')->where('lot_id', $lot)->whereNull('resolved_at')->count()]);
    }

    public function store(Request $request, int $lot, OperationWorkflow $workflow): RedirectResponse
    {
        OperationAccess::lot($request, $lot);
        $input = $request->validate(['name' => ['required', 'string', 'max:160'], 'departure_date' => ['required', 'date_format:Y-m-d'], 'configuration_ids' => ['required', 'array', 'min:1'], 'configuration_ids.*' => ['required', 'integer', 'distinct']]);
        $id = $workflow->createDeparture(OperationAccess::tenant($request), $request->user()->id, $lot, $input);

        return redirect()->route('operations.departures.show', $id)->with('status', 'Salida programada. El supervisor debe confirmar los datos de transporte.');
    }

    public function show(Request $request, int $departure, OperationWorkflow $workflow): View
    {
        $record = OperationAccess::departures($request)->where('id', $departure)->firstOrFail();
        $tenant = OperationAccess::tenant($request);

        return view('operations::departure-show', ['departure' => $record, 'preview' => $workflow->preview($departure), 'guides' => DB::table('Ope_Guias')->where('departure_id', $departure)->orderByDesc('version')->get(), 'vehicles' => DB::table('MBA_vehicles')->where(['tenant_id' => $tenant, 'is_active' => true])->orderBy('plate')->get(['plate']), 'drivers' => DB::table('MBA_users')->whereIn('id', DB::table('MBA_tenant_users')->where(['tenant_id' => $tenant, 'is_active' => true])->select('user_id'))->whereNotNull('tax_id')->orderBy('name')->get(['name', 'tax_id']), 'audit' => DB::table('Ope_Auditoria')->where(['tenant_id' => $tenant, 'entity' => 'salida', 'entity_id' => $departure])->orderByDesc('id')->get()]);
    }

    public function assignment(Request $request, int $departure, OperationWorkflow $workflow): RedirectResponse
    {
        OperationAccess::departures($request)->where('id', $departure)->firstOrFail();
        OperationAccess::requireSupervisor($request);
        $input = $request->validate(['plate' => ['required', 'string', 'max:10'], 'driver_name' => ['required', 'string', 'max:160'], 'driver_rut' => ['required', 'string', 'max:15']]);
        $workflow->saveAssignment(OperationAccess::tenant($request), $request->user()->id, $departure, $input);

        return back()->with('status', 'Transporte guardado. Revisa la guía antes de aprobar.');
    }

    public function approve(Request $request, int $departure, OperationWorkflow $workflow): RedirectResponse
    {
        OperationAccess::departures($request)->where('id', $departure)->firstOrFail();
        OperationAccess::requireSupervisor($request);
        $request->validate(['confirmed' => ['accepted']]);
        $id = $workflow->approve(OperationAccess::tenant($request), $request->user()->id, $departure);

        return redirect()->route('operations.guides.show', $id)->with('status', 'Guía interna aprobada y versionada.');
    }

    public function reopen(Request $request, int $departure, OperationWorkflow $workflow): RedirectResponse
    {
        OperationAccess::departures($request)->where('id', $departure)->firstOrFail();
        OperationAccess::requireSupervisor($request);
        $input = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $workflow->reopen(OperationAccess::tenant($request), $request->user()->id, $departure, $input['reason']);

        return back()->with('status', 'Salida reabierta. La guía anterior permanece en el historial.');
    }

    public function guide(Request $request, int $guide): View
    {
        $record = $this->guideRecord($request, $guide);
        $state = OperationAccess::departures($request)->where('id', $record->departure_id)->firstOrFail();

        return view('operations::guide', ['guide' => $record, 'preview' => json_decode($record->snapshot, true), 'current' => $state->status === 'approved' && $state->version === $record->version]);
    }

    public function cancel(Request $request, int $departure, OperationWorkflow $workflow): RedirectResponse
    {
        OperationAccess::departures($request)->where('id', $departure)->firstOrFail();
        OperationAccess::requireSupervisor($request);
        $input = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $workflow->cancel(OperationAccess::tenant($request), $request->user()->id, $departure, $input['reason']);

        return back()->with('status', 'Salida cancelada. Sus bultos quedan disponibles para programar nuevamente.');
    }

    public function export(Request $request, int $guide): StreamedResponse
    {
        $record = $this->guideRecord($request, $guide);
        $snapshot = json_decode($record->snapshot, true);

        return response()->streamDownload(function () use ($snapshot, $record): void {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Guia interna', 'Version', 'Fecha', 'Tramo', 'Origen', 'Comuna origen', 'Destino', 'Comuna destino', 'Patente', 'Chofer', 'RUT chofer', 'Cliente', 'Servicio', 'Guia cliente', 'Referencia', 'Bultos', 'Peso volumetrico kg', 'Glosa'], ';', '"', '');
            foreach ($snapshot['lines'] as $line) {
                $row = [$record->id, $record->version, $snapshot['departure']['departure_date'], $snapshot['departure']['role'], $snapshot['origin']['address'], $snapshot['origin']['commune'], $snapshot['destination']['address'], $snapshot['destination']['commune'], $snapshot['departure']['plate'], $snapshot['departure']['driver_name'], $snapshot['departure']['driver_rut'], $line['merchant'], $line['service'], $line['customer_guide'], $line['reference'], $line['count'], $line['weight'], $line['description']];
                $row = array_map(fn ($value) => is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value, $row);
                fputcsv($file, $row, ';', '"', '');
            }
            fclose($file);
        }, 'Guia_interna_'.$record->id.'_v'.$record->version.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function guideRecord(Request $request, int $id): object
    {
        return DB::table('Ope_Guias')->whereIn('departure_id', OperationAccess::departures($request)->select('id'))->where('id', $id)->firstOrFail();
    }
}
