<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\CourierMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CourierMovementImportController
{
    public function validateFile(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv', 'max:102400'],
        ]);

        $file = $validated['file'];
        $worksheet = IOFactory::load($file->getRealPath())->getActiveSheet();
        $headers = $worksheet->rangeToArray('A1:'.$worksheet->getHighestDataColumn().'1', null, true, false)[0];
        $normalizedHeaders = array_map(fn (mixed $header): string => $this->normalizeHeader($header), $headers);

        $trackingIndex = $this->headerIndex($normalizedHeaders, fn (string $header): bool => str_contains($header, 'seguimiento'));
        $merchantIndex = $this->headerIndex($normalizedHeaders, fn (string $header): bool => str_contains($header, 'comerciante'));
        $weightIndex = $this->headerIndex($normalizedHeaders, fn (string $header): bool => str_contains($header, 'peso'));
        $rows = $worksheet->rangeToArray('A2:'.$worksheet->getHighestDataColumn().$worksheet->getHighestDataRow(), null, true, false);
        $records = array_values(array_filter($rows, fn (array $row): bool => count(array_filter($row, fn (mixed $value): bool => $value !== null && $value !== '')) > 0));
        $missingTracking = 0;
        $invalidDate = 0;
        $missingWeight = 0;

        foreach ($records as $row) {
            $tracking = $trackingIndex === null ? '' : trim((string) ($row[$trackingIndex] ?? ''));

            if ($tracking === '') {
                $missingTracking++;
            } elseif (CourierMovement::fechaFromTrackingNumber($tracking) === null) {
                $invalidDate++;
            }

            if ($weightIndex === null || trim((string) ($row[$weightIndex] ?? '')) === '') {
                $missingWeight++;
            }
        }

        return back()->with('validation', [
            'file_name' => $file->getClientOriginalName(),
            'records' => count($records),
            'headers' => array_values(array_filter($headers, fn (mixed $header): bool => $header !== null && $header !== '')),
            'has_tracking' => $trackingIndex !== null,
            'has_merchant' => $merchantIndex !== null,
            'has_weight' => $weightIndex !== null,
            'missing_tracking' => $missingTracking,
            'invalid_date' => $invalidDate,
            'missing_weight' => $missingWeight,
        ]);
    }

    private function normalizeHeader(mixed $header): string
    {
        return Str::of((string) $header)->squish()->lower()->ascii()->toString();
    }

    /** @param array<int, string> $headers */
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
