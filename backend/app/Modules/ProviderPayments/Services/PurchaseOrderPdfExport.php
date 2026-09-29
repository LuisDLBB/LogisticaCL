<?php

namespace App\Modules\ProviderPayments\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderPdfExport
{
    public function __construct(private readonly PurchaseOrderFilename $filenames) {}

    public function download(array $document): StreamedResponse
    {
        $bytes = $this->render($document);

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, $this->filenames->forDocument($document, 'pdf'), ['Content-Type' => 'application/pdf']);
    }

    public function render(array $document): string
    {
        $pdf = new SimplePdf;
        $pdf->addPage();
        $this->header($pdf, $document);
        $y = 295;
        $this->tableHeader($pdf, $y);
        $y += 27;

        foreach ($document['groups'] as $group) {
            if ($y + 19 + $this->remainingHeight($document) > 805) {
                $pdf->addPage();
                $this->continuationHeader($pdf, $document);
                $y = 97;
                $this->tableHeader($pdf, $y);
                $y += 27;
            }
            $pdf->line(42, $y + 18, 553, $y + 18);
            $pdf->text(50, $y + 13, $this->fit($group['servicio'], 38), 8.5, true);
            $pdf->text(278, $y + 13, $this->fit($group['proceso'], 15), 8, false, '61747A');
            $this->right($pdf, 416, $y + 13, $group['valor_unitario'] === null ? '—' : $this->money($group['valor_unitario']), 8);
            $this->right($pdf, 470, $y + 13, (string) $group['cantidad'], 8);
            $this->right($pdf, 545, $y + 13, $this->money($group['total']), 8, true);
            $y += 19;
        }

        if ($y + $this->remainingHeight($document) > 805) {
            $pdf->addPage();
            $this->continuationHeader($pdf, $document);
            $y = 111;
        }
        $this->totalsAndBank($pdf, $document, $y + 15);

        return $pdf->output();
    }

    private function header(SimplePdf $pdf, array $document): void
    {
        $boleta = $document['impuesto'] === 'Retencion';
        $pdf->text(42, 40, 'CONDICION DE PAGO', 8, true);
        if ($document['payment_terms'] !== '') {
            $pdf->text(42, 57, $this->fit(mb_strtoupper($document['payment_terms']), 21), 10, true);
        }
        $this->center($pdf, 47, 'PRE FACTURA DE SERVICIOS', 15, true);
        $pdf->line(197, 53, 399, 53, '202020');
        $this->center($pdf, 70, mb_strtoupper($document['period_end']->locale('es')->translatedFormat('F Y')), 10, true);
        if ($document['company_code'] === '4N') {
            $pdf->jpeg(public_path('images/logo4n.jpg'), 485, 28, 68, 68);
        } else {
            $this->pmcbLogo($pdf);
        }

        $pdf->text(42, 100, 'PROVEEDOR', 8, true);
        $pdf->text(144, 100, $this->fit($document['proveedor'], 64), 9);
        $pdf->text(42, 119, 'NOMBRE DE PILA', 8, true);
        $pdf->text(144, 119, $this->fit($document['nombre_operacional'], 64), 9);
        $pdf->text(42, 138, 'RUT', 8, true);
        $pdf->text(144, 138, $document['rut_proveedor'], 9);
        $pdf->text(42, 157, 'ZONA', 8, true);
        $pdf->text(144, 157, $document['zona'], 9);
        $pdf->text(365, 157, 'CENTRO COSTO', 8, true);
        $pdf->text(466, 157, 'COURIER', 9);

        $pdf->rect(42, 174, 511, 27, '181A1C');
        $pdf->text(50, 192, 'ORDEN DE COMPRA  '.$document['oc'], 11, true, 'FFFFFF');
        $pdf->rect(42, 209, 511, 77, 'F4F6F6');
        $pdf->text(51, 224, $document['impuesto'] === 'Mixto' ? 'DATOS DE FACTURACION - DOCUMENTOS MIXTOS' : ($boleta ? 'DATOS PARA GENERAR LA BOLETA' : 'DATOS PARA GENERAR LA FACTURA'), 9, true);
        $billing = $document['billing'];
        $pdf->text(51, 239, 'RAZON SOCIAL', 8, true);
        $pdf->text(147, 239, $billing['name'], 8);
        $pdf->text(51, 253, 'RUT', 8, true);
        $pdf->text(147, 253, $billing['tax_id'], 8);
        $pdf->text(51, 267, 'DIRECCION', 8, true);
        $pdf->text(147, 267, $this->fit($billing['address'], 75), 8);
        $pdf->text(51, 281, 'CORREO', 8, true);
        $pdf->text(147, 281, $billing['email'], 8);
        $this->right($pdf, 550, 224, $document['period_end']->format('d/m/Y'), 8);
    }

    private function continuationHeader(SimplePdf $pdf, array $document): void
    {
        $pdf->text(42, 43, 'DETALLE DE LA ORDEN DE COMPRA', 13, true);
        $this->right($pdf, 553, 43, $document['oc'], 11, true);
        $pdf->text(42, 65, $this->fit($document['nombre_operacional'] ?: $document['proveedor'], 68), 9);
        $pdf->line(42, 79, 553, 79);
    }

    private function tableHeader(SimplePdf $pdf, float $y): void
    {
        $pdf->rect(42, $y, 511, 24, 'E4F4F4');
        $pdf->text(50, $y + 16, 'DETALLE DEL SERVICIO', 8, true, '006F73');
        $pdf->text(278, $y + 16, 'PROCESO', 8, true, '006F73');
        $this->right($pdf, 416, $y + 16, 'VALOR', 8, true, '006F73');
        $this->right($pdf, 470, $y + 16, 'CANT.', 8, true, '006F73');
        $this->right($pdf, 545, $y + 16, 'TOTAL', 8, true, '006F73');
    }

    private function totalsAndBank(SimplePdf $pdf, array $document, float $y): void
    {
        $pdf->line(324, $y - 13, 553, $y - 13, '202020');
        $pdf->text(324, $y, $document['impuesto'] === 'Retencion' ? 'TOTAL BRUTO' : 'TOTAL NETO', 9);
        $this->right($pdf, 550, $y, $this->money($document['base']), 10);
        $index = 1;
        foreach ($document['tax_lines'] as $tax) {
            $label = match ($tax['impuesto']) {
                'Retencion' => 'RETENCION', 'Exento' => 'EXENTO', default => 'IVA',
            };
            $label .= ' '.number_format($tax['porcentaje'], $tax['porcentaje'] === floor($tax['porcentaje']) ? 0 : 2, ',', '').'%';
            $pdf->text(324, $y + $index * 20, $label, 9);
            $this->right($pdf, 550, $y + $index * 20, ($tax['impuesto'] === 'Retencion' ? '- ' : '+ ').$this->money($tax['valor']), 10);
            $index++;
        }
        $finalY = $y + $index * 20;
        $pdf->text(324, $finalY, $document['impuesto'] === 'Retencion' ? 'LIQUIDO A PAGAR' : 'TOTAL A PAGAR', 9, true);
        $this->right($pdf, 550, $finalY, $this->money($document['valor_final_total']), 10, true);
        $pdf->line(324, $finalY + 6, 553, $finalY + 6, '202020');

        $bankY = max($finalY + 28, 696);
        $pdf->rect(42, $bankY, 511, 69, 'F4F6F6');
        $pdf->text(51, $bankY + 16, 'DATOS PARA PAGO PROVEEDOR', 9, true);
        if ($document['bank'] === null) {
            $pdf->text(51, $bankY + 37, 'Sin cuenta bancaria activa registrada para este proveedor.', 9);
        } else {
            $bank = $document['bank'];
            $pdf->text(51, $bankY + 33, $this->fit($bank['titular'], 55).'  |  '.$bank['rut'], 9);
            $pdf->text(51, $bankY + 50, $this->fit($bank['banco'], 30).'  |  '.$this->fit($bank['tipo'], 25).'  |  '.$this->fit($bank['numero'], 35), 9);
        }
    }

    private function pmcbLogo(SimplePdf $pdf): void
    {
        $pdf->circle(508, 40, 18, '00A7E4');
        $pdf->circle(488, 61, 18, 'E00087');
        $pdf->circle(528, 61, 18, 'E00087');
        $pdf->circle(508, 82, 18, 'FFE500');
        $pdf->text(487, 67, 'PMCB', 12, true, '008B9D');
    }

    private function remainingHeight(array $document): float
    {
        return 15 + ($document['tax_lines']->count() + 1) * 20 + 28 + 69;
    }

    private function center(SimplePdf $pdf, float $y, string $value, float $size, bool $bold = false): void
    {
        $width = mb_strlen($value) * $size * 0.60;
        $pdf->text((595.28 - $width) / 2, $y, $value, $size, $bold);
    }

    private function right(SimplePdf $pdf, float $right, float $y, string $value, float $size, bool $bold = false, string $color = '222222'): void
    {
        $pdf->text($right - mb_strlen($value) * $size * 0.51, $y, $value, $size, $bold, $color);
    }

    private function money(int $amount): string
    {
        return '$ '.number_format($amount, 0, ',', '.');
    }

    private function fit(string $text, int $characters): string
    {
        return mb_strimwidth($text, 0, $characters, '...');
    }
}
