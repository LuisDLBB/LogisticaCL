<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\CourierMovement;
use App\Models\Tenant;
use App\Modules\ProviderPayments\ParameterReview;
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
        $parameters = ['clients' => [], 'services' => [], 'coverages' => [], 'weights' => []];
        $serviceIndex = null;
        $communeIndex = null;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values = array_map(static function (mixed $value): mixed {
                    if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                        return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
                    }

                    return $value;
                }, $row->toArray());

                if ($headers === []) {
                    $headers = $values;
                    $normalizedHeaders = array_map(fn (mixed $header): string => $this->normalizeHeader($header), $headers);
                    $trackingIndex = $this->headerIndex($normalizedHeaders, fn (string $header): bool => str_contains($header, 'seguimiento'));
                    $weightIndex = $this->headerIndex($normalizedHeaders, fn (string $header): bool => str_contains($header, 'peso'));
                    $merchantIndex = $this->headerIndex($normalizedHeaders, fn (string $header): bool => str_contains($header, 'comerciante'));
                    $statusIndex = $this->headerIndex($normalizedHeaders, fn (string $header): bool => str_contains($header, 'estado'));
                    $serviceIndex = $this->headerIndex($normalizedHeaders, fn (string $header): bool => in_array($header, ['servicio', 'service', 'service_name'], true));
                    $communeIndex = $this->headerIndex($normalizedHeaders, fn (string $header): bool => in_array($header, ['comuna', 'comuna destino', 'comuna de destino', 'destination_commune_name'], true));

                    continue;
                }

                if (count(array_filter($values, fn (mixed $value): bool => $value !== null && $value !== '')) === 0) {
                    continue;
                }

                $records++;
                $rawMerchant = (string) ($merchantIndex === null ? '' : ($values[$merchantIndex] ?? ''));
                $rawService = (string) ($serviceIndex === null ? '' : ($values[$serviceIndex] ?? ''));
                $rawCommune = (string) ($communeIndex === null ? '' : ($values[$communeIndex] ?? ''));
                $rawWeight = (string) ($weightIndex === null ? '' : ($values[$weightIndex] ?? ''));
                foreach (['clients' => [$rawMerchant], 'services' => [$rawMerchant, $rawService], 'coverages' => [$rawCommune], 'weights' => [$rawWeight]] as $category => $parts) {
                    $key = serialize($parts);
                    if (! isset($parameters[$category][$key])) {
                        $parameters[$category][$key] = ['values' => $parts, 'count' => 0];
                    }
                    $parameters[$category][$key]['count']++;
                }
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
        $request->session()->put('courier_review', [
            'file' => $file->getClientOriginalName(),
            'records' => $records,
            'groups' => $parameters,
            'missing_columns' => array_keys(array_filter([
                'Comerciante' => $merchantIndex === null,
                'Servicio' => $serviceIndex === null,
                'Comuna destino' => $communeIndex === null,
                'Peso' => $weightIndex === null,
            ])),
        ]);

        return view('provider-payments::courier-movements-summary', ['validation' => ['file_name' => $file->getClientOriginalName(), 'records' => $records, 'has_tracking' => $trackingIndex !== null, 'has_merchant' => $merchantIndex !== null, 'has_weight' => $weightIndex !== null, 'missing_tracking' => $missingTracking, 'invalid_date' => $invalidDate, 'missing_weight' => $missingWeight, 'merchant_counts' => $merchantCounts, 'status_counts' => $statusCounts]]);
    }

    public function reviewParameters(Request $request, ParameterReview $reviewer): View
    {
        $snapshot = $request->session()->get('courier_review');
        $tenants = $request->user()
            ? $request->user()->tenants()->wherePivot('is_active', true)->get()
            : (app()->environment('local') ? Tenant::where('is_active', true)->get() : collect());
        $tenant = $request->filled('tenant')
            ? $tenants->firstWhere('id', (int) $request->input('tenant'))
            : ($tenants->count() === 1 ? $tenants->first() : null);
        abort_if($request->filled('tenant') && ! $tenant, 403);

        return view('provider-payments::courier-movements-parameters', [
            'snapshot' => $snapshot, 'tenants' => $tenants, 'tenant' => $tenant,
            'groups' => $snapshot ? $reviewer->compare($snapshot['groups'], $tenant?->id) : [],
        ]);
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
