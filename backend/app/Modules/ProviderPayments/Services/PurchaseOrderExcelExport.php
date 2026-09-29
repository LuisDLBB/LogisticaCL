<?php

namespace App\Modules\ProviderPayments\Services;

use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderExcelExport
{
    public function __construct(
        private readonly PurchaseOrderDocument $documents,
        private readonly PurchaseOrderFilename $filenames,
    ) {}

    public function download(array $document): StreamedResponse
    {
        return response()->streamDownload(function () use ($document): void {
            $book = new Spreadsheet;
            try {
                $this->summarySheet($book, $document);
                $this->paymentsSheet($book, $document);
                $book->setActiveSheetIndex(0);
                (new Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, $this->filenames->forDocument($document, 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function render(array $document): string
    {
        $book = new Spreadsheet;
        $stream = fopen('php://temp', 'w+b');
        try {
            $this->summarySheet($book, $document);
            $this->paymentsSheet($book, $document);
            $book->setActiveSheetIndex(0);
            (new Xlsx($book))->save($stream);
            rewind($stream);

            return stream_get_contents($stream);
        } finally {
            fclose($stream);
            $book->disconnectWorksheets();
        }
    }

    private function summarySheet(Spreadsheet $book, array $document): void
    {
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Resumen por servicio');
        $sheet->mergeCells('A1:E1');
        $sheet->setCellValue('A1', 'Orden de compra '.$document['oc']);
        $sheet->mergeCells('A2:E2');
        $sheet->setCellValue('A2', 'Razón social proveedor: '.$document['proveedor']);
        $sheet->mergeCells('A3:E3');
        $sheet->setCellValueExplicit('A3', 'RUT proveedor: '.$document['rut_proveedor'], DataType::TYPE_STRING);
        $sheet->mergeCells('A4:E4');
        $sheet->setCellValue('A4', 'Nombre de pila proveedor: '.$document['nombre_operacional']);
        $sheet->mergeCells('A5:E5');
        $sheet->setCellValue('A5', 'Condición de pago: '.$document['payment_terms']);
        $sheet->mergeCells('A6:E6');
        $sheet->setCellValue('A6', 'Período '.$document['periodo'].' · '.$document['billing']['name'].' · '.$document['tipo_documento']);
        $this->title($sheet, 'A1:E1');
        $headers = ['Proceso', 'Servicio', 'Registros', 'Valor unitario ($)', 'Total base ($)'];
        $this->headers($sheet, $headers, 8);
        $row = 9;
        foreach ($document['groups'] as $group) {
            $sheet->setCellValueExplicit('A'.$row, $group['proceso'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B'.$row, $group['servicio'], DataType::TYPE_STRING);
            $sheet->setCellValue('C'.$row, $group['cantidad']);
            if ($group['valor_unitario'] !== null) {
                $sheet->setCellValue('D'.$row, $group['valor_unitario']);
            }
            $sheet->setCellValue('E'.$row, $group['total']);
            $row++;
        }
        $sheet->setCellValue('D'.$row, 'TOTAL BASE');
        $sheet->setCellValue('E'.$row, $document['base']);
        $this->totalStyle($sheet, 'D'.$row.':E'.$row);
        foreach ($document['tax_lines'] as $tax) {
            $row++;
            $sheet->setCellValue('D'.$row, $tax['impuesto'].' '.number_format($tax['porcentaje'], 2, ',', '').'%');
            $sheet->setCellValue('E'.$row, $tax['impuesto'] === 'Retencion' ? -$tax['valor'] : $tax['valor']);
        }
        $row++;
        $sheet->setCellValue('D'.$row, 'TOTAL A PAGAR');
        $sheet->setCellValue('E'.$row, $document['valor_final_total']);
        $this->totalStyle($sheet, 'D'.$row.':E'.$row);
        $sheet->getStyle('D9:E'.$row)->getNumberFormat()->setFormatCode('"$" #,##0;[Red]"-$" #,##0');
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(65);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(22);
        $sheet->getColumnDimension('E')->setWidth(22);
        $sheet->freezePane('A9');
        $sheet->setAutoFilter('A8:E'.max(8, $row - 2 - $document['tax_lines']->count()));
    }

    private function paymentsSheet(Spreadsheet $book, array $document): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle('Base de pagos');
        $columns = [
            'OC', 'Período', 'Seguimiento paquete', 'Fecha', 'Tipo de pago',
            'Zona', 'Dirección', 'Comuna', 'Cliente', 'Proveedor', 'Nombre de pila proveedor',
            'RUT proveedor', 'Nombre repartidor', 'Usuario entrega', 'Peso final',
            'Estado envío', 'Valor base ($)', 'Tipo documento', 'Empresa mandante',
        ];
        $sheet->mergeCells('A1:S1');
        $sheet->setCellValue('A1', 'Base de pagos · OC '.$document['oc']);
        $this->title($sheet, 'A1:S1');
        $this->headers($sheet, $columns, 3);
        $rowNumber = 4;
        foreach ($document['rows'] as $payment) {
            $values = [
                $payment->oc, $payment->periodo, $payment->seguimiento_paquete, $payment->fecha,
                $payment->tipo_pago, $payment->zona,
                $this->documents->address($payment->direccion), $payment->comuna_destino,
                $payment->comerciante_pila, $document['proveedor'],
                $payment->nombre_operacional, $payment->rut_proveedor,
                $payment->nombre_repartidor, $payment->usuario_entrega,
                $payment->peso_final, $payment->estado_envio, $payment->valor,
                $payment->tipo_documento, $payment->empresa_mandante,
            ];
            foreach ($values as $index => $value) {
                $column = $index + 1;
                if ($column === 4 && filled($value)) {
                    $sheet->setCellValue([$column, $rowNumber], ExcelDate::PHPToExcel(new DateTimeImmutable((string) $value)));
                } elseif (in_array($column, [15, 17], true) && $value !== null) {
                    $sheet->setCellValue([$column, $rowNumber], is_numeric($value) ? $value + 0 : $value);
                } else {
                    $sheet->setCellValueExplicit([$column, $rowNumber], (string) ($value ?? ''), DataType::TYPE_STRING);
                }
            }
            $rowNumber++;
        }
        $last = $rowNumber - 1;
        $sheet->getStyle('D4:D'.$last)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $sheet->getStyle('Q4:Q'.$last)->getNumberFormat()->setFormatCode('"$" #,##0');
        foreach (range('A', 'S') as $letter) {
            $sheet->getColumnDimension($letter)->setWidth(21);
        }
        foreach (['G', 'I', 'J', 'K'] as $letter) {
            $sheet->getColumnDimension($letter)->setWidth(36);
        }
        $sheet->freezePane('D4');
        $sheet->setAutoFilter('A3:S'.$last);
    }

    /** @param array<int, string> $headers */
    private function headers(Worksheet $sheet, array $headers, int $row): void
    {
        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, $row], $header, DataType::TYPE_STRING);
        }
        $last = $sheet->getHighestColumn();
        $sheet->getStyle('A'.$row.':'.$last.$row)->getFont()->setBold(true)->getColor()->setRGB('006F73');
        $sheet->getStyle('A'.$row.':'.$last.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D8F3F2');
        $sheet->getStyle('A'.$row.':'.$last.$row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension($row)->setRowHeight(32);
    }

    private function title(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006F73');
        $sheet->getRowDimension(1)->setRowHeight(28);
    }

    private function totalStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D8F3F2');
    }
}
