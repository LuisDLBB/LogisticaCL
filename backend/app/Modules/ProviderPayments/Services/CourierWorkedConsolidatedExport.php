<?php

namespace App\Modules\ProviderPayments\Services;

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourierWorkedConsolidatedExport
{
    public function download(int $tenantId, string $period): StreamedResponse
    {
        $columns = [
            'razon_social_proveedor',
            'nombre_operacional',
            'rut_proveedor',
            'zona',
            'nombre_proceso',
            'periodo',
            'tipo_documento',
            'empresa_mandante',
        ];
        $rows = DB::table('PPR_Pago_Movimientos_Courier')
            ->where('tenant_id', $tenantId)
            ->where('periodo', $period)
            ->where('condicion_pago', 'SI')
            ->select($columns)
            ->selectRaw('SUM(COALESCE(valor, 0)) AS valor_sumado')
            ->groupBy($columns)
            ->orderBy('razon_social_proveedor')
            ->orderBy('nombre_operacional')
            ->orderBy('rut_proveedor')
            ->orderBy('nombre_proceso')
            ->orderBy('zona')
            ->orderBy('empresa_mandante')
            ->get();

        return response()->streamDownload(function () use ($rows, $period): void {
            $spreadsheet = new Spreadsheet;

            try {
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('Consolidado');
                $sheet->mergeCells('A1:I1');
                $sheet->setCellValue('A1', 'Consolidado de procesos trabajados');
                $sheet->mergeCells('A2:I2');
                $sheet->setCellValue('A2', 'Período '.$period.' · Solo condición de pago SI');
                $sheet->getStyle('A1:I1')->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:I1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006F73');
                $sheet->getRowDimension(1)->setRowHeight(28);
                $sheet->getRowDimension(2)->setRowHeight(22);

                $headers = [
                    'Razón social proveedor',
                    'Nombre de pila proveedor',
                    'RUT proveedor',
                    'Zona',
                    'Proceso',
                    'Período',
                    'Valor sumado ($)',
                    'Tipo de documento proveedor',
                    'Empresa mandante',
                ];
                foreach ($headers as $index => $header) {
                    $sheet->setCellValueExplicit([$index + 1, 4], $header, DataType::TYPE_STRING);
                }
                $sheet->getStyle('A4:I4')->getFont()->setBold(true)->getColor()->setRGB('006F73');
                $sheet->getStyle('A4:I4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D8F3F2');
                $sheet->getStyle('A4:I4')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
                $sheet->getRowDimension(4)->setRowHeight(34);

                $rowNumber = 5;
                $grandTotal = 0;
                foreach ($rows as $row) {
                    $values = [
                        $row->razon_social_proveedor,
                        $row->nombre_operacional,
                        $row->rut_proveedor,
                        $row->zona,
                        $row->nombre_proceso,
                        $row->periodo,
                        $row->tipo_documento,
                        $row->empresa_mandante,
                    ];
                    foreach ([1, 2, 3, 4, 5, 6, 8, 9] as $index => $columnNumber) {
                        $sheet->setCellValueExplicit([$columnNumber, $rowNumber], (string) ($values[$index] ?? ''), DataType::TYPE_STRING);
                    }
                    $amount = (int) $row->valor_sumado;
                    $sheet->setCellValue('G'.$rowNumber, $amount);
                    $grandTotal += $amount;
                    $rowNumber++;
                }

                if ($rows->isEmpty()) {
                    $sheet->mergeCells('A5:I5');
                    $sheet->setCellValue('A5', 'No hay registros con condición de pago SI en este período.');
                    $rowNumber++;
                }

                $totalRow = $rowNumber;
                $sheet->mergeCells('A'.$totalRow.':F'.$totalRow);
                $sheet->setCellValue('A'.$totalRow, 'TOTAL');
                $sheet->setCellValue('G'.$totalRow, $grandTotal);
                $sheet->getStyle('A'.$totalRow.':I'.$totalRow)->getFont()->setBold(true);
                $sheet->getStyle('A'.$totalRow.':I'.$totalRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D8F3F2');
                $sheet->getStyle('G5:G'.$totalRow)->getNumberFormat()->setFormatCode('"$" #,##0');
                $sheet->getStyle('A5:I'.$totalRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getStyle('A5:B'.$totalRow)->getAlignment()->setWrapText(true);
                $sheet->getColumnDimension('A')->setWidth(40);
                $sheet->getColumnDimension('B')->setWidth(34);
                $sheet->getColumnDimension('C')->setWidth(18);
                $sheet->getColumnDimension('D')->setWidth(16);
                $sheet->getColumnDimension('E')->setWidth(27);
                $sheet->getColumnDimension('F')->setWidth(14);
                $sheet->getColumnDimension('G')->setWidth(20);
                $sheet->getColumnDimension('H')->setWidth(32);
                $sheet->getColumnDimension('I')->setWidth(23);
                $sheet->freezePane('A5');
                $sheet->setAutoFilter('A4:I'.max(4, $totalRow - 1));

                (new Xlsx($spreadsheet))->save('php://output');
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }, 'consolidado_procesos_trabajados_'.$period.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
