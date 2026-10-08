<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\OperationAccess;
use App\Modules\Operations\Services\OperationGuideRoutePlanner;
use App\Modules\Operations\Services\OperationReservationService;
use App\Modules\Operations\Services\OperationWorkflow;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationDepartureController extends Controller
{
    public function overview(Request $request): View
    {
        $tenant = OperationAccess::tenant($request);
        $lots = DB::table('Ope_Lotes')->where('tenant_id', $tenant)->orderByDesc('operation_date')->orderByDesc('id')->paginate(20);
        $departureCounts = DB::table('Ope_ProgramacionSalidas')
            ->whereIn('lot_id', $lots->pluck('id'))
            ->select('lot_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved")
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")
            ->groupBy('lot_id')
            ->get()
            ->keyBy('lot_id');
        $reservationCount = DB::table('Ope_Reservas')->where(['tenant_id' => $tenant, 'status' => 'pending'])->count();

        return view('operations::departure-overview', compact('lots', 'departureCounts', 'reservationCount'));
    }

    public function index(Request $request, int $lot, OperationWorkflow $workflow, OperationReservationService $reservations): View
    {
        $record = OperationAccess::lot($request, $lot);
        DB::transaction(fn () => $workflow->prepareGuideRoutes(OperationAccess::tenant($request), $lot));
        $counts = DB::table('Ope_Bultos')->where(['lot_id' => $lot, 'excluded' => false])->select('coverage_id')->selectRaw('COUNT(*) as count, SUM(weight) as weight')->groupBy('coverage_id')->get()->keyBy('coverage_id');
        $configurations = DB::table('Ope_GuiaConfiguraciones as c')
            ->join('PPR_coverages as coverage', 'coverage.id', '=', 'c.coverage_id')
            ->leftJoin('Ope_Agencias as agency', function ($join): void {
                $join->on('agency.agency_code', '=', 'coverage.ID_ComunaMatrizAgencia')
                    ->on('agency.tenant_id', '=', 'coverage.tenant_id');
            })
            ->leftJoin('Ope_Troncales as trunk', 'trunk.id', '=', 'agency.trunk_id')
            ->leftJoin('Ope_Postas as first_post', 'first_post.id', '=', 'agency.post_id')
            ->leftJoin('Ope_Postas as second_post', 'second_post.id', '=', 'agency.second_post_id')
            ->leftJoin('Ope_Troncales as leg_trunk', function ($join): void {
                $join->on('leg_trunk.id', '=', 'c.transport_id')->where('c.transport_kind', 'trunk');
            })
            ->leftJoin('Ope_Postas as leg_post', function ($join): void {
                $join->on('leg_post.id', '=', 'c.transport_id')->where('c.transport_kind', 'post');
            })
            ->leftJoin('Ope_Choferes as leg_trunk_driver', 'leg_trunk_driver.id', '=', 'leg_trunk.driver_id')
            ->leftJoin('Ope_Choferes as leg_post_driver', 'leg_post_driver.id', '=', 'leg_post.driver_id')
            ->join('Ope_Ubicaciones as origin', 'origin.id', '=', 'c.origin_id')
            ->join('Ope_Ubicaciones as destination', 'destination.id', '=', 'c.destination_id')
            ->where('c.tenant_id', OperationAccess::tenant($request))
            ->where('c.is_active', true)
            ->whereIn('c.coverage_id', $counts->keys())
            ->orderBy('c.sequence')
            ->orderBy('c.stop_order')
            ->orderBy('agency.agency_code')
            ->get(['c.*', 'agency.id as agency_id', 'agency.name as agency_name',
                'trunk.name as trunk_name', 'first_post.name as first_post_name', 'second_post.name as second_post_name',
                'leg_trunk.name as leg_trunk_name', 'leg_post.name as leg_post_name',
                'leg_trunk.plate as leg_trunk_plate', 'leg_post.plate as leg_post_plate',
                'leg_trunk_driver.rut as leg_trunk_driver_rut', 'leg_post_driver.rut as leg_post_driver_rut',
                'leg_trunk_driver.name as leg_trunk_driver_name', 'leg_post_driver.name as leg_post_driver_name',
                'origin.name as origin_name', 'destination.name as destination_name']);
        $members = DB::table('Ope_BultoTramos')->whereIn('departure_id', DB::table('Ope_ProgramacionSalidas')->where('lot_id', $lot)->select('id'))->pluck('configuration_id')->unique();
        $coverageRoutes = DB::table('PPR_coverages as coverage')
            ->leftJoin('Ope_Agencias as agency', function ($join): void {
                $join->on('agency.agency_code', '=', 'coverage.ID_ComunaMatrizAgencia')
                    ->on('agency.tenant_id', '=', 'coverage.tenant_id');
            })
            ->leftJoin('Ope_Troncales as trunk', 'trunk.id', '=', 'agency.trunk_id')
            ->where('coverage.tenant_id', OperationAccess::tenant($request))
            ->whereIn('coverage.id', $counts->keys())
            ->get(['coverage.id', 'coverage.commune_name', 'agency.id as agency_id', 'agency.agency_code', 'agency.name as agency_name',
                'agency.address', 'agency.second_post_id', 'trunk.trunk_code']);
        $routeCounts = $configurations->countBy('coverage_id');
        $pendingCoverageIds = DB::table('Ope_BultoTramos as leg')
            ->join('Ope_Bultos as package', 'package.id', '=', 'leg.package_id')
            ->join('Ope_ProgramacionSalidas as departure', 'departure.id', '=', 'leg.departure_id')
            ->where('package.lot_id', $lot)
            ->where('departure.status', 'draft')
            ->distinct()->pluck('package.coverage_id');
        $planner = app(OperationGuideRoutePlanner::class);
        $missing = $coverageRoutes->reject(fn ($coverage): bool => $pendingCoverageIds->contains($coverage->id))
            ->filter(fn ($coverage): bool => $routeCounts->get($coverage->id, 0) < $planner->expectedLegCount(
                (int) $coverage->trunk_code, (int) $coverage->agency_code, $coverage->second_post_id !== null,
            )
            || ($coverage->agency_id && OperationAccess::key($coverage->address ?? '') === 'pendiente'));
        $missingAddresses = $missing->filter(fn ($coverage): bool => $coverage->agency_id && (blank($coverage->address) || OperationAccess::key($coverage->address) === 'pendiente'))
            ->groupBy('agency_id');
        $missingRoutes = $missing->reject(fn ($coverage): bool => $coverage->agency_id && (blank($coverage->address) || OperationAccess::key($coverage->address) === 'pendiente'));

        $legacyDrafts = DB::table('Ope_ProgramacionSalidas as departure')
            ->join('Ope_SalidaAgencias as assigned', 'assigned.departure_id', '=', 'departure.id')
            ->join('Ope_GuiaConfiguraciones as configuration', 'configuration.id', '=', 'assigned.configuration_id')
            ->where('departure.lot_id', $lot)->where('departure.status', 'draft')
            ->whereNull('configuration.group_code')->distinct()->count('departure.id');
        $departures = DB::table('Ope_ProgramacionSalidas')->where('lot_id', $lot)->orderBy('departure_date')->orderBy('id')->get();

        return view('operations::departures', ['lot' => $record, 'configurations' => $configurations, 'counts' => $counts, 'scheduled' => $members, 'missingAddresses' => $missingAddresses, 'missingRoutes' => $missingRoutes, 'legacyDrafts' => $legacyDrafts, 'departures' => $departures, 'pendingDepartureCount' => $departures->where('status', 'draft')->count(), 'canApproveAll' => OperationAccess::supervisor($request), 'spreadsheetRows' => $workflow->departureSpreadsheetRows(OperationAccess::tenant($request), $lot), 'blocked' => DB::table('Ope_Incidencias')->where('lot_id', $lot)->whereNull('resolved_at')->count(), 'pendingReservations' => $reservations->pendingBatches(OperationAccess::tenant($request)), 'includedReservations' => $reservations->includedBatches(OperationAccess::tenant($request), $lot), 'canIncludeReservations' => ! DB::table('Ope_ProgramacionSalidas')->where('lot_id', $lot)->where('status', '<>', 'cancelled')->exists()]);
    }

    public function spreadsheet(Request $request, int $lot, OperationWorkflow $workflow): StreamedResponse
    {
        OperationAccess::lot($request, $lot);
        $rows = $workflow->departureSpreadsheetRows(OperationAccess::tenant($request), $lot);

        return response()->streamDownload(function () use ($rows): void {
            $spreadsheet = new Spreadsheet;

            try {
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('Salidas de agencias');
                $headers = ['Fecha declarada', 'Transporte', 'N.º', 'Dirección origen', 'Comuna origen', 'Patente', 'RUT chofer', 'Nombre chofer', 'Dirección destino', 'Comuna destino', 'Agencia', 'Glosa', 'Bultos', 'Suma de peso'];
                foreach ($headers as $index => $header) {
                    $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
                }
                $sheet->getStyle('A1:N1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:N1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('007F82');
                $sheet->getRowDimension(1)->setRowHeight(25);

                foreach ($rows as $index => $row) {
                    $rowNumber = $index + 2;
                    $declaredDate = DateTimeImmutable::createFromFormat('!Y-m-d', $row['declared_date']);
                    $values = [$declaredDate ? Date::PHPToExcel($declaredDate) : $row['declared_date'], $row['transport'], $row['number'], $row['origin_address'], $row['origin_commune'], $row['plate'], $row['driver_rut'], $row['driver_name'], $row['destination_address'], $row['destination_commune'], $row['agency'], $row['description'], $row['count'], $row['weight']];
                    foreach ($values as $column => $value) {
                        if (in_array($column, [2, 12, 13], true) || ($column === 0 && $declaredDate)) {
                            $sheet->setCellValue([$column + 1, $rowNumber], $value);
                        } else {
                            $sheet->setCellValueExplicit([$column + 1, $rowNumber], (string) $value, DataType::TYPE_STRING);
                        }
                    }
                }

                foreach (['A' => 19, 'B' => 34, 'C' => 9, 'D' => 38, 'E' => 20, 'F' => 15, 'G' => 18, 'H' => 30, 'I' => 38, 'J' => 20, 'K' => 26, 'L' => 55, 'M' => 12, 'N' => 18] as $column => $width) {
                    $sheet->getColumnDimension($column)->setWidth($width);
                }
                if ($rows !== []) {
                    $sheet->getStyle('A2:A'.(count($rows) + 1))->getNumberFormat()->setFormatCode('dd-mm-yyyy');
                    $sheet->getStyle('N2:N'.(count($rows) + 1))->getNumberFormat()->setFormatCode('0');
                }
                $sheet->freezePane('A2');
                $sheet->setAutoFilter('A1:N'.max(1, count($rows) + 1));

                (new Xlsx($spreadsheet))->save('php://output');
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }, 'salidas_agencias_proceso_'.$lot.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function store(Request $request, int $lot, OperationWorkflow $workflow): RedirectResponse
    {
        OperationAccess::lot($request, $lot);
        $input = $request->validate(['name' => ['required', 'string', 'max:160'], 'departure_date' => ['required', 'date_format:Y-m-d'], 'configuration_ids' => ['required', 'array', 'min:1', 'max:500'], 'configuration_ids.*' => ['required', 'integer', 'distinct']]);
        if (count($input['configuration_ids']) > 1) {
            $ids = $workflow->createDepartures(OperationAccess::tenant($request), $request->user()->id, $lot, $input);

            return redirect()->route('operations.departures.index', $lot)->with('status', count($ids).' salidas programadas para revisar. Ninguna guía ha sido aprobada todavía.');
        }
        $id = $workflow->createDeparture(OperationAccess::tenant($request), $request->user()->id, $lot, $input);

        return redirect()->route('operations.departures.show', $id)->with('status', 'Salida programada. El supervisor debe confirmar los datos de transporte.');
    }

    public function reserve(Request $request, int $lot, OperationReservationService $reservations): RedirectResponse
    {
        OperationAccess::lot($request, $lot);
        $input = $request->validate(['configuration_ids' => ['required', 'array', 'min:1', 'max:500'], 'configuration_ids.*' => ['required', 'integer', 'distinct'], 'warehouse_returned' => ['sometimes', 'accepted']]);
        $count = $reservations->reserve(OperationAccess::tenant($request), $request->user()->id, $lot, $input['configuration_ids'], $request->boolean('warehouse_returned'));

        return redirect()->route('operations.departures.index', $lot)->with('status', $count.' bultos guardados como reserva para otro proceso.');
    }

    public function includeReservations(Request $request, int $lot, OperationReservationService $reservations): RedirectResponse
    {
        OperationAccess::lot($request, $lot);
        $input = $request->validate(['batch_ids' => ['required', 'array', 'min:1', 'max:500'], 'batch_ids.*' => ['required', 'uuid', 'distinct']]);
        $count = $reservations->include(OperationAccess::tenant($request), $request->user()->id, $lot, $input['batch_ids']);

        return redirect()->route('operations.departures.index', $lot)->with('status', $count.' bultos de reserva incorporados al proceso. Sus rutas se actualizaron con la configuración actual.');
    }

    public function cancelReservation(Request $request, int $lot, string $batch, OperationReservationService $reservations): RedirectResponse
    {
        OperationAccess::lot($request, $lot);
        $count = $reservations->cancelPending(OperationAccess::tenant($request), $request->user()->id, $lot, $batch);

        return redirect()->route('operations.departures.index', $lot)->with('status', $count.' bultos devueltos al proceso de origen.');
    }

    public function returnReservation(Request $request, int $lot, string $batch, OperationReservationService $reservations): RedirectResponse
    {
        OperationAccess::lot($request, $lot);
        $count = $reservations->returnToPending(OperationAccess::tenant($request), $request->user()->id, $lot, $batch);

        return redirect()->route('operations.departures.index', $lot)->with('status', $count.' bultos devueltos a reservas guardadas.');
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

    public function approveAll(Request $request, int $lot, OperationWorkflow $workflow): RedirectResponse
    {
        OperationAccess::lot($request, $lot);
        OperationAccess::requireSupervisor($request);
        $request->validate(['confirmed' => ['accepted']]);
        $count = $workflow->approveAll(OperationAccess::tenant($request), $request->user()->id, $lot);

        return redirect()->route('operations.departures.index', $lot)
            ->with('status', $count.' '.($count === 1 ? 'guía aprobada' : 'guías aprobadas').' en orden de recorrido.');
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
