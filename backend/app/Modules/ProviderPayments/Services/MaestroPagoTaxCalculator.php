<?php

namespace App\Modules\ProviderPayments\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MaestroPagoTaxCalculator
{
    /**
     * @param  array<string, int>  $bases
     * @param  array<string, int>  $allocatedTaxes
     * @return array{impuesto: string, porcentaje_impuesto: string, valor_impuesto: int, valor_final_total: int}
     */
    public function allocateForOrder(?string $documentType, int $amount, int $paymentId, string $oc, array &$bases, array &$allocatedTaxes): array
    {
        $tax = $this->calculate($documentType, $amount, $paymentId);
        $key = $oc.'|'.$tax['impuesto'].'|'.$tax['porcentaje_impuesto'];
        $bases[$key] = ($bases[$key] ?? 0) + $amount;
        $cumulative = $this->calculate($documentType, $bases[$key], $paymentId);
        $tax['valor_impuesto'] = $cumulative['valor_impuesto'] - ($allocatedTaxes[$key] ?? 0);
        $tax['valor_final_total'] = $tax['impuesto'] === 'Retencion'
            ? $amount - $tax['valor_impuesto']
            : $amount + $tax['valor_impuesto'];
        $allocatedTaxes[$key] = $cumulative['valor_impuesto'];

        return $tax;
    }

    /** @return array{impuesto: string, porcentaje_impuesto: string, valor_impuesto: int, valor_final_total: int} */
    public function calculate(?string $documentType, int $amount, int $paymentId): array
    {
        $document = preg_replace('/\s+/', ' ', strtoupper(Str::ascii(trim((string) $documentType))));

        [$tax, $percentage, $numerator, $denominator, $subtract] = match ($document) {
            'FACTURA' => ['IVA', '19.00', 19, 100, false],
            'BOLETA DE HONORARIOS', 'BOLETA DE HONORARIOS TERCERO' => ['Retencion', '15.25', 61, 400, true],
            'FACTURA EXENTA' => ['Exento', '0.00', 0, 1, false],
            default => throw ValidationException::withMessages([
                'period' => "El pago {$paymentId} tiene un tipo de documento sin regla de impuesto: ".($documentType ?: 'vacío').'.',
            ]),
        };

        $taxAmount = intdiv($amount * $numerator + intdiv($denominator, 2), $denominator);

        return [
            'impuesto' => $tax,
            'porcentaje_impuesto' => $percentage,
            'valor_impuesto' => $taxAmount,
            'valor_final_total' => $subtract ? $amount - $taxAmount : $amount + $taxAmount,
        ];
    }
}
