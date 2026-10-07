<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\OperationAccess;
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
    public function index(Request $request, int $lot, OperationWorkflow $workflow): View
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
            ->join('Ope_Ubicaciones as origin', 'origin.id', '=', 'c.origin_id')
            ->join('Ope_Ubicaciones as destination', 'destination.id', '=', 'c.destination_id')
            ->where('c.tenant_id', OperationAccess::tenant($request))
            ->where('c.is_active', true)
            ->whereIn('c.coverage_id', $counts->keys())
            ->orderBy('c.sequence')
            ->orderBy('agency.name')
            ->get(['c.*', 'agency.id as agency_id', 'agency.name as agency_name',
                'trunk.name as trunk_name', 'first_post.name as first_post_name', 'second_post.name as second_post_name',
                'origin.name as origin_name', 'destination.name as destination_name']);
        $members = DB::table('Ope_BultoTramos')->whereIn('departure_id', DB::table('Ope_ProgramacionSalidas')->where('lot_id', $lot)->select('id'))->pluck('configuration_id')->unique();
        $coverageRoutes = DB::table('PPR_coverages as coverage')
            ->leftJoin('Ope_Agencias as agency', function ($join): void {
                $join->on('agency.agency_code', '=', 'coverage.ID_ComunaMatrizAgencia')
                    ->on('agency.tenant_id', '=', 'coverage.tenant_id');
            })
            ->where('coverage.tenant_id', OperationAccess::tenant($request))
            ->whereIn('coverage.id', $counts->keys())
            ->get(['coverage.id', 'coverage.commune_name', 'agency.id as agency_id', 'agency.name as agency_name', 'agency.address', 'agency.second_post_id']);
        $routeCounts = $configurations->countBy('coverage_id');
        $missing = $coverageRoutes->filter(fn ($coverage): bool => $routeCounts->get($coverage->id, 0) < ($coverage->second_post_id ? 3 : 2)
            || ($coverage->agency_id && OperationAccess::key($coverage->address ?? '') === 'pendiente'));
        $missingAddresses = $missing->filter(fn ($coverage): bool => $coverage->agency_id && (blank($coverage->address) || OperationAccess::key($coverage->address) === 'pendiente'))
            ->groupBy('agency_id');
        $missingRoutes = $missing->reject(fn ($coverage): bool => $coverage->agency_id && (blank($coverage->address) || OperationAccess::key($coverage->address) === 'pendiente'));

        return view('operations::departures', ['lot' => $record, 'configurations' => $configurations, 'counts' => $counts, 'scheduled' => $members, 'missingAddresses' => $missingAddresses, 'missingRoutes' => $missingRoutes, 'departures' => DB::table('Ope_ProgramacionSalidas')->where('lot_id', $lot)->orderBy('departure_date')->orderBy('id')->get(), 'spreadsheetRows' => $workflow->departureSpreadsheetRows(OperationAccess::tenant($request), $lot), 'blocked' => DB::table('Ope_Incidencias')->where('lot_id', $lot)->whereNull('resolved_at')->count()]);
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
