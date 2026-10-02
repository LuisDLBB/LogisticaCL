<?php

namespace App\Modules\ProviderPayments\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaidExternalShipmentReport
{
    /** @param list<array<string, mixed>> $rows */
    public function store(array $rows, string $fileName): ?string
    {
        if ($rows === []) {
            return null;
        }

        $token = (string) Str::uuid();
        Storage::disk('local')->put($this->path($token), json_encode([
            'file' => basename($fileName),
            'rows' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $token;
    }

    public function download(string $token): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($this->path($token)), 404);
        $report = json_decode(Storage::disk('local')->get($this->path($token)), true, 512, JSON_THROW_ON_ERROR);

        return $this->stream($report, 'Envios_Externos_Ya_Pagados_'.$token.'.xlsx');
    }

    public function countExisting(int $tenantId): int
    {
        return DB::table('PPR_envios_externos as e')
            ->join('PPR_Maestro_Pagos as m', function ($join): void {
                $join->on('m.seguimiento_paquete', '=', 'e.tracking_number')
                    ->on('m.tenant_id', '=', 'e.tenant_id');
            })
            ->where('e.tenant_id', $tenantId)->count();
    }

    public function downloadExisting(int $tenantId): StreamedResponse
    {
        $rows = DB::table('PPR_envios_externos as e')
            ->join('PPR_Maestro_Pagos as m', function ($join): void {
                $join->on('m.seguimiento_paquete', '=', 'e.tracking_number')
                    ->on('m.tenant_id', '=', 'e.tenant_id');
            })
            ->where('e.tenant_id', $tenantId)
            ->orderBy('m.periodo')->orderBy('e.tracking_number')
            ->get(['e.fecha as source_date', 'e.tracking_number as tracking',
                'e.external_order_number as os_blue', 'e.client_name_source as client',
                'e.destination_locality_name as locality', 'm.periodo as paid_period',
                'm.nombre_proceso as paid_process', 'm.razon_social_proveedor as provider',
                'm.rut_proveedor as provider_tax_id', 'm.valor as amount', 'm.oc',
                'm.empresa_mandante as company', 'm.zona as zone'])
            ->map(fn (object $row): array => array_merge((array) $row, ['source_row' => null]))->all();

        abort_if($rows === [], 404);

        return $this->stream(['file' => 'Envíos Externos registrados', 'rows' => $rows],
            'Envios_Externos_Ya_Pagados_Historico.xlsx');
    }

    /** @param array{file: string, rows: list<array<string, mixed>>} $report */
    private function stream(array $report, string $fileName): StreamedResponse
    {
        return response()->streamDownload(function () use ($report): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            try {
                $writer->addRow(Row::fromValues([
                    'Archivo de carga', 'Fila Excel', 'Fecha del envío', 'ID / Seguimiento',
                    'OS Blue', 'Cliente de planilla', 'Localidad destino', 'Período pagado',
                    'Proceso pagado', 'Proveedor pagado', 'RUT proveedor', 'Valor pagado ($)',
                    'OC', 'Empresa mandante', 'Zona', 'Situación',
                ]));
                foreach ($report['rows'] as $row) {
                    $writer->addRow(new Row([
                        new StringCell($report['file']), $row['source_row'] === null
                            ? new StringCell('') : new NumericCell((int) $row['source_row']),
                        new StringCell($row['source_date']), new StringCell($row['tracking']),
                        new StringCell($row['os_blue'] ?? ''), new StringCell($row['client'] ?? ''),
                        new StringCell($row['locality'] ?? ''), new StringCell($row['paid_period']),
                        new StringCell($row['paid_process']), new StringCell($row['provider'] ?? ''),
                        new StringCell($row['provider_tax_id'] ?? ''), new NumericCell((int) ($row['amount'] ?? 0)),
                        new StringCell($row['oc'] ?? ''), new StringCell($row['company'] ?? ''),
                        new StringCell($row['zone'] ?? ''), new StringCell('PAGADO - REVISAR'),
                    ]));
                }
            } finally {
                $writer->close();
            }
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function path(string $token): string
    {
        return 'paid-external-shipment-reports/'.$token.'.json';
    }
}
