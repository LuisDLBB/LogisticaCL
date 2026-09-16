<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\CourierMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

class CourierMovementImportController
{
    public function validateFile(Request $request): View
    {
        $validated = $request->validate(['file' => ['required', 'file', 'mimes:xlsx,csv', 'max:102400']]);
        $file = $validated['file'];
        $reader = strtolower($file->getClientOriginalExtension()) === 'csv' ? new CsvReader : new XlsxReader;
        $reader->open($file->getRealPath());

        $headers = [];
        $records = 0;
        $missingTracking = 0;
        $invalidDate = 0;
        $missingWeight = 0;
        $normalizedHeaders = [];
        $trackingIndex = null;
        $weightIndex = null;
        $merchantIndex = null;
        $statusIndex = null;
        $merchantCounts = [];
        $statusCounts = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values = $row->toArray();

                if ($headers === []) {
                    $headers = $values;
                    $normalizedHeaders = array_map(fn (mixed $header): string => $this->normalizeHeader($header), $headers);
                    $trackingIndex = $this->headerIndex($normalizedHeaders, fn (string $header): bool => str_contains($header, 'seguimiento'));
                    $weightIndex = $this->headerIndex($normalizedHeaders, fn (string $header): bool => str_contains($header, 'peso'));
                    $merchantIndex = $this->headerIndex($normalizedHeaders, fn (string $header): bool => str_contains($header, 'comerciante'));
                    $statusIndex = $this->headerIndex($normalizedHeaders, fn (string $header): bool => str_contains($header, 'estado'));

                    continue;
                }

                if (count(array_filter($values, fn (mixed $value): bool => $value !== null && $value !== '')) === 0) {
                    continue;
                }

                $records++;
                $merchant = trim((string) ($merchantIndex === null ? '' : $values[$merchantIndex] ?? '')) ?: 'Sin comerciante';
                $status = trim((string) ($statusIndex === null ? '' : $values[$statusIndex] ?? '')) ?: 'Sin estado';
                $merchantCounts[$merchant] = ($merchantCounts[$merchant] ?? 0) + 1;
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
                $tracking = $trackingIndex === null ? '' : trim((string) ($values[$trackingIndex] ?? ''));

                if ($tracking === '') {
                    $missingTracking++;
                } elseif (CourierMovement::fechaFromTrackingNumber($tracking) === null) {
                    $invalidDate++;
                }
                if ($weightIndex === null || trim((string) ($values[$weightIndex] ?? '')) === '') {
                    $missingWeight++;
                }
            }

            break;
        }

        $reader->close();
        $normalizedHeaders = array_map(fn (mixed $header): string => $this->normalizeHeader($header), $headers);
        $trackingIndex = $this->headerIndex($normalizedHeaders, fn (string $header): bool => str_contains($header, 'seguimiento'));
        arsort($merchantCounts);
        arsort($statusCounts);

        return view('provider-payments::courier-movements-summary', ['validation' => ['file_name' => $file->getClientOriginalName(), 'records' => $records, 'has_tracking' => $trackingIndex !== null, 'has_merchant' => $merchantIndex !== null, 'has_weight' => $weightIndex !== null, 'missing_tracking' => $missingTracking, 'invalid_date' => $invalidDate, 'missing_weight' => $missingWeight, 'merchant_counts' => $merchantCounts, 'status_counts' => $statusCounts]]);
    }

    private function normalizeHeader(mixed $header): string
    {
        return Str::of((string) $header)->squish()->lower()->ascii()->toString();
    }

    private function headerIndex(array $headers, callable $matches): ?int
    {
        foreach ($headers as $index => $header) {
            if ($matches($header)) {
                return $index;
            }
        }

        return null;
    }
}
