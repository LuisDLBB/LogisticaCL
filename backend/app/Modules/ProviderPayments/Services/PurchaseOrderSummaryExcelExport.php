<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\Provider;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderSummaryExcelExport
{
    public function download(int $tenantId, string $period): StreamedResponse
    {
        abort_unless(DB::table('Cierres_Pagos')->where('tenant_id', $tenantId)
            ->where('periodo', $period)->exists(), 404);

        $orders = $this->orders($tenantId, $period);

        return response()->streamDownload(function () use ($orders, $period): void {
            $book = new Spreadsheet;

            try {
                $sheet = $book->getActiveSheet();
                $sheet->setTitle('Resumen de OC');
                $sheet->setShowGridLines(false);
                $sheet->mergeCells('A1:O1');
                $sheet->setCellValue('A1', 'Resumen de órdenes de compra · '.$period);
                $sheet->getStyle('A1:O1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1:O1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006F73');
                $sheet->getRowDimension(1)->setRowHeight(32);
                $sheet->mergeCells('A2:O2');
                $sheet->setCellValue('A2', 'Ordenado por condición de pago, OC y nombre de pila. La retención se descuenta del valor base.');

                $headers = [
                    'OC', 'Nombre de pila proveedor', 'Empresa mandante', 'Condición de pago',
                    'Razón social proveedor', 'RUT proveedor', 'Titular de la cuenta', 'RUT del titular',
                    'Banco', 'Tipo de cuenta', 'Número de cuenta', 'Tipo de documento',
                    'Valor base', 'Impuesto/Retención', 'Valor final',
                ];
                foreach ($headers as $index => $header) {
                    $sheet->setCellValueExplicit([$index + 1, 4], $header, DataType::TYPE_STRING);
                }
                $sheet->getStyle('A4:O4')->getFont()->setBold(true)->getColor()->setRGB('006F73');
                $sheet->getStyle('A4:O4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D8F3F2');
                $sheet->getStyle('A4:O4')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getRowDimension(4)->setRowHeight(34);

                $totals = ['base' => 0, 'tax' => 0, 'final' => 0];
                foreach ($orders as $index => $order) {
                    $row = $index + 5;
                    $texts = [
                        $order['oc'], $order['nombre_pila'], $order['empresa_mandante'],
                        $order['condicion_pago'], $order['razon_social'], $order['rut_proveedor'],
                        $order['titular'], $order['rut_titular'], $order['banco'],
                        $order['tipo_cuenta'], $order['numero_cuenta'], $order['tipo_documento'],
                    ];
                    foreach ($texts as $column => $value) {
                        $sheet->setCellValueExplicit([$column + 1, $row], $value, DataType::TYPE_STRING);
                    }
                    $sheet->setCellValue('M'.$row, $order['base']);
                    $sheet->setCellValue('N'.$row, $order['tax']);
                    $sheet->setCellValue('O'.$row, $order['final']);
                    $totals['base'] += $order['base'];
                    $totals['tax'] += $order['tax'];
                    $totals['final'] += $order['final'];
                    if ($index % 2 === 1) {
                        $sheet->getStyle('A'.$row.':O'.$row)->getFill()
                            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F4F9F9');
                    }
                }

                $lastDataRow = count($orders) + 4;
                $totalRow = $lastDataRow + 1;
                $sheet->mergeCells('A'.$totalRow.':L'.$totalRow);
                $sheet->setCellValue('A'.$totalRow, 'TOTAL PERÍODO');
                $sheet->setCellValue('M'.$totalRow, $totals['base']);
                $sheet->setCellValue('N'.$totalRow, $totals['tax']);
                $sheet->setCellValue('O'.$totalRow, $totals['final']);
                $sheet->getStyle('A'.$totalRow.':O'.$totalRow)->getFont()->setBold(true);
                $sheet->getStyle('A'.$totalRow.':O'.$totalRow)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D8F3F2');
                $sheet->getStyle('M5:O'.$totalRow)->getNumberFormat()
                    ->setFormatCode('"$" #,##0;[Red]"-$" #,##0');
                foreach ([
                    'A' => 16, 'B' => 32, 'C' => 20, 'D' => 21, 'E' => 42,
                    'F' => 19, 'G' => 38, 'H' => 19, 'I' => 25, 'J' => 23,
                    'K' => 25, 'L' => 31, 'M' => 20, 'N' => 23, 'O' => 21,
                ] as $column => $width) {
                    $sheet->getColumnDimension($column)->setWidth($width);
                }
                $sheet->freezePane('E5');
                $sheet->setAutoFilter('A4:O'.max(4, $lastDataRow));
                (new Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, 'Resumen_OC_'.$period.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array<int, array<string, int|string>> */
    private function orders(int $tenantId, string $period): array
    {
        $grouped = [];
        $payments = DB::table('Maestro_Pagos')->where('tenant_id', $tenantId)
            ->where('periodo', $period)
            ->select('oc', 'rut_proveedor', 'razon_social_proveedor', 'nombre_operacional',
                'empresa_mandante', 'tipo_documento', 'provider_id', 'valor', 'valor_final_total')
            ->orderBy('oc')->cursor();

        foreach ($payments as $payment) {
            $oc = (string) $payment->oc;
            if ($oc === '') {
                throw ValidationException::withMessages(['period' => 'Hay pagos del período sin OC. Revisa Maestro_Pagos antes de exportar.']);
            }
            $company = strtoupper(trim((string) $payment->empresa_mandante));
            $company = $company === 'PMBC' ? 'PMCB' : $company;
            if (! isset($grouped[$oc])) {
                $grouped[$oc] = [
                    'oc' => $oc,
                    'rut_proveedor' => (string) $payment->rut_proveedor,
                    'razon_social' => (string) $payment->razon_social_proveedor,
                    'nombre_pila' => (string) $payment->nombre_operacional,
                    'empresa_mandante' => $company,
                    'tipo_documento' => (string) $payment->tipo_documento,
                    'provider_id' => $payment->provider_id,
                    'base' => 0, 'final' => 0,
                ];
            }
            if ($grouped[$oc]['rut_proveedor'] !== (string) $payment->rut_proveedor
                || $grouped[$oc]['empresa_mandante'] !== $company
                || mb_strtoupper($grouped[$oc]['tipo_documento']) !== mb_strtoupper((string) $payment->tipo_documento)) {
                throw ValidationException::withMessages(['period' => "La OC {$oc} mezcla proveedores, empresas o tipos de documento. Revísala antes de exportar."]);
            }
            $grouped[$oc]['base'] += (int) $payment->valor;
            $grouped[$oc]['final'] += (int) $payment->valor_final_total;
        }

        $providerIds = array_values(array_filter(array_column($grouped, 'provider_id')));
        $ruts = array_values(array_unique(array_column($grouped, 'rut_proveedor')));
        $providers = Provider::query()->with(['bankAccounts' => fn ($query) => $query
            ->where('is_active', true)->orderByDesc('is_primary')->orderBy('id')])
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($providerIds, $ruts): void {
                $query->whereIn('id', $providerIds)->orWhereIn('tax_id', $ruts);
            })->get();
        $byId = $providers->keyBy('id');
        $byRut = $providers->keyBy('tax_id');

        $orders = [];
        foreach ($grouped as $order) {
            $provider = $byId->get($order['provider_id']) ?: $byRut->get($order['rut_proveedor']);
            $bank = $provider?->bankAccounts->first();
            $terms = $order['empresa_mandante'] === 'PMCB' && filled($provider?->payment_terms_pmcb)
                ? $provider->payment_terms_pmcb : $provider?->payment_terms;
            $order['nombre_pila'] = (string) ($provider?->operational_name ?: $order['nombre_pila']);
            $order['razon_social'] = PurchaseOrderProviderName::display($order['rut_proveedor'], $order['razon_social']);
            $order['condicion_pago'] = (string) ($terms ?? '');
            $order['titular'] = (string) ($bank?->account_holder_name ?: ($bank ? $provider?->legal_name : ''));
            $order['rut_titular'] = (string) ($bank?->account_holder_tax_id ?: ($bank ? $provider?->tax_id : ''));
            $order['banco'] = (string) ($bank?->bank_name ?? '');
            $order['tipo_cuenta'] = (string) ($bank?->account_type ?? '');
            $order['numero_cuenta'] = (string) ($bank?->account_number ?? '');
            $order['tax'] = $order['final'] - $order['base'];
            unset($order['provider_id']);
            $orders[] = $order;
        }

        usort($orders, static fn (array $left, array $right): int => strnatcasecmp($left['condicion_pago'], $right['condicion_pago'])
            ?: strcmp($left['oc'], $right['oc'])
            ?: strnatcasecmp($left['nombre_pila'], $right['nombre_pila']));

        return $orders;
    }
}
