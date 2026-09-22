<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\Client;
use App\Models\CourierImportError;
use App\Models\CourierMovement;
use App\Models\Coverage;
use App\Models\Tenant;
use App\Models\WeightTransformation;
use App\Modules\ProviderPayments\ParameterReview;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CourierMovementImportController
{
    public function validateFile(Request $request): View
    {
        $validated = $request->validate(
            [
                'file' => ['required', 'file', 'extensions:xlsx,csv', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip', 'max:102400'],
                'process_type' => ['nullable', Rule::in(['variables', 'lanas', 'retornos'])],
            ],
            [
                'file.required' => 'Archivo con problema: debes seleccionar un archivo. Formato recomendado: CSV UTF-8 con extensión .csv. También se admite Excel .xlsx.',
                'file.file' => 'Archivo con problema: no se pudo reconocer como archivo válido. Revisa su formato. Recomendamos CSV UTF-8 con extensión .csv.',
                'file.mimes' => 'Archivo con problema: extensión no permitida. Usa preferentemente CSV UTF-8 (.csv) o Excel (.xlsx).',
                'file.max' => 'Archivo con problema: supera 100 MB. Recomendamos exportarlo como CSV UTF-8 (.csv) y reducir su tamaño.',
            ],
        );
        $file = $validated['file'];
        $processType = $validated['process_type'] ?? 'variables';
        $batchId = (string) Str::uuid();
        $extension = strtolower($file->getClientOriginalExtension());
        $storedPath = $file->storeAs('courier-imports', $batchId.'.'.$extension, 'local');
        $reader = strtolower($file->getClientOriginalExtension()) === 'csv'
            ? new CsvReader(new CsvOptions(FIELD_DELIMITER: $this->detectCsvDelimiter($file->getRealPath())))
            : new XlsxReader;
        try {
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
            $periodCounts = [];
            $serviceIndex = null;
            $communeIndex = null;
            $addressIndex = null;
            $recipientNameIndex = null;
            $destinationPreview = [];

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
                        $addressIndex = $this->headerIndex($normalizedHeaders, fn (string $header): bool => in_array($header, ['direccion', 'direccion destinatario', 'recipient_address'], true));
                        $recipientNameIndex = $this->headerIndex($normalizedHeaders, fn (string $header): bool => in_array($header, ['nombre del destinatario', 'recipient_name'], true));

                        continue;
                    }

                    if (count(array_filter($values, fn (mixed $value): bool => $value !== null && $value !== '')) === 0) {
                        continue;
                    }

                    $records++;
                    $rawMerchant = (string) ($merchantIndex === null ? '' : ($values[$merchantIndex] ?? ''));
                    $rawService = (string) ($serviceIndex === null ? '' : ($values[$serviceIndex] ?? ''));
                    $rawCommune = (string) ($communeIndex === null ? '' : ($values[$communeIndex] ?? ''));
                    $rawAddress = (string) ($addressIndex === null ? '' : ($values[$addressIndex] ?? ''));
                    $rawRecipientName = (string) ($recipientNameIndex === null ? '' : ($values[$recipientNameIndex] ?? ''));
                    [$reviewAddress, $reviewCommune] = $this->destinationValues($processType, $rawAddress, $rawCommune, $rawRecipientName);
                    $rawWeight = (string) ($weightIndex === null ? '' : ($values[$weightIndex] ?? ''));
                    foreach (['clients' => [$rawMerchant], 'services' => [$rawMerchant, $rawService], 'coverages' => [$reviewCommune, $reviewAddress], 'weights' => [$rawWeight]] as $category => $parts) {
                        $key = serialize($parts);
                        if (! isset($parameters[$category][$key])) {
                            $parameters[$category][$key] = ['values' => $parts, 'count' => 0];
                        }
                        $parameters[$category][$key]['count']++;
                    }
                    if ($processType === 'retornos') {
                        $destinationKey = serialize([$reviewAddress, $reviewCommune]);
                        $destinationPreview[$destinationKey] ??= ['address' => $reviewAddress, 'commune' => $reviewCommune, 'count' => 0];
                        $destinationPreview[$destinationKey]['count']++;
                    }
                    $merchant = trim((string) ($merchantIndex === null ? '' : $values[$merchantIndex] ?? '')) ?: 'Sin comerciante';
                    $status = trim((string) ($statusIndex === null ? '' : $values[$statusIndex] ?? '')) ?: 'Sin estado';
                    $merchantCounts[$merchant] = ($merchantCounts[$merchant] ?? 0) + 1;
                    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
                    $tracking = $trackingIndex === null ? '' : trim((string) ($values[$trackingIndex] ?? ''));

                    if ($tracking === '') {
                        $missingTracking++;
                    } else {
                        $trackingDate = CourierMovement::fechaFromTrackingNumber($tracking);
                        if ($trackingDate === null) {
                            $invalidDate++;
                        } else {
                            $period = $trackingDate->format('Ym');
                            $periodCounts[$period] = ($periodCounts[$period] ?? 0) + 1;
                        }
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
            arsort($periodCounts);
            $suggestedPeriod = (string) (array_key_first($periodCounts) ?? now()->format('Ym'));
            $request->session()->put('courier_review', [
                'batch_id' => $batchId,
                'file' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'extension' => $extension,
                'records' => $records,
                'groups' => $parameters,
                'missing_columns' => array_keys(array_filter([
                    'Comerciante' => $merchantIndex === null,
                    'Servicio' => $serviceIndex === null,
                    'Comuna destino' => $communeIndex === null,
                    'Dirección' => $processType === 'retornos' && $addressIndex === null,
                    'Nombre del destinatario' => $processType === 'retornos' && $recipientNameIndex === null,
                    'Peso' => $weightIndex === null,
                ])),
                'suggested_year' => (int) substr($suggestedPeriod, 0, 4),
                'suggested_month' => (int) substr($suggestedPeriod, 4, 2),
                'process_type' => $processType,
                'process_suffix' => $this->processSuffix($processType),
            ]);
            $request->session()->forget('courier_review_exclusions');

            return view('provider-payments::courier-movements-summary', ['processType' => $processType, 'validation' => ['file_name' => $file->getClientOriginalName(), 'records' => $records, 'has_tracking' => $trackingIndex !== null, 'has_merchant' => $merchantIndex !== null, 'has_weight' => $weightIndex !== null, 'missing_tracking' => $missingTracking, 'invalid_date' => $invalidDate, 'missing_weight' => $missingWeight, 'merchant_counts' => $merchantCounts, 'status_counts' => $statusCounts, 'destination_preview' => array_values($destinationPreview)]]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(['file' => 'Archivo con problema: no se pudo leer o está dañado. Revisa el formato y vuelve a exportarlo. Recomendamos CSV UTF-8 con extensión .csv; también se admite Excel .xlsx de hasta 100 MB.']);
        }
    }

    public function reviewParameters(Request $request, ParameterReview $reviewer): View
    {
        $snapshot = $request->session()->get('courier_review');
        if ($snapshot && empty($snapshot['batch_id'])) {
            $snapshot['batch_id'] = (string) Str::uuid();
            $request->session()->put('courier_review', $snapshot);
        }
        $tenant = Tenant::query()->where('code', '4N')->where('is_active', true)->first();
        $tenants = $tenant ? collect([$tenant]) : collect();

        $groups = $snapshot ? $reviewer->compare($snapshot['groups'], $tenant?->id) : [];
        $excludedServices = $request->session()->get('courier_review_exclusions.services', []);
        if ($snapshot && $tenant) {
            $this->syncErrors($snapshot, $groups, $tenant->id, $request->session()->get('courier_review_exclusions.coverages', []), $excludedServices);
        }
        $resolvedCoverageKeys = array_merge(
            $request->session()->get('courier_review_exclusions.coverages', []),
            array_keys($request->session()->get('courier_review_corrections.coverages', [])),
        );
        $groups = $this->hideExcludedCoverages($groups, $resolvedCoverageKeys);
        $groups = $this->hideExcludedGroupItems($groups, 'services', $excludedServices);

        return view('provider-payments::courier-movements-parameters', [
            'snapshot' => $snapshot, 'tenants' => $tenants, 'tenant' => $tenant,
            'groups' => $groups,
            'excludedCoverages' => $request->session()->get('courier_review_exclusions.coverages', []),
            'excludedServices' => $excludedServices,
            'serviceComments' => $request->session()->get('courier_review_comments.services', []),
            'coverageComments' => $request->session()->get('courier_review_comments.coverages', []),
            'coverageCorrections' => $request->session()->get('courier_review_corrections.coverages', []),
            'coverageOptions' => $tenant ? Coverage::query()->where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('commune_name')->pluck('commune_name')->unique()->values() : collect(),
            'readyToImport' => $snapshot && $snapshot['missing_columns'] === [] && collect($groups)->sum(fn (array $group): int => count($group['items'])) === 0,
        ]);
    }

    public function storeMovements(Request $request, ParameterReview $reviewer): View|RedirectResponse
    {
        $validated = $request->validate([
            'replace_duplicates' => ['nullable', 'boolean'],
            'process_year' => ['required', 'integer', 'between:2000,2100'],
            'process_month' => ['required', 'integer', 'between:1,12'],
            'process_name' => ['nullable', 'string', 'max:100'],
        ]);
        $snapshot = $request->session()->get('courier_review');
        $processType = $snapshot['process_type'] ?? 'variables';
        $processSuffix = $this->processSuffix($processType);
        $processName = in_array($processType, ['lanas', 'retornos'], true) ? '' : trim((string) ($validated['process_name'] ?? ''));
        if ($processName === '') {
            $processName = sprintf('%04d%02d-%s', $validated['process_year'], $validated['process_month'], $processSuffix);
        }
        $tenant = Tenant::query()->where('code', '4N')->where('is_active', true)->firstOrFail();
        if (! $snapshot || empty($snapshot['stored_path']) || ! Storage::disk('local')->exists($snapshot['stored_path'])) {
            return redirect()->route($this->uploadRoute($processType))
                ->withErrors(['file' => 'Debes seleccionar y validar nuevamente el archivo para completar la carga.']);
        }
        $groups = $this->hideExcludedCoverages(
            $reviewer->compare($snapshot['groups'], $tenant->id),
            array_merge(
                $request->session()->get('courier_review_exclusions.coverages', []),
                array_keys($request->session()->get('courier_review_corrections.coverages', [])),
            ),
        );
        $groups = $this->hideExcludedGroupItems($groups, 'services', $request->session()->get('courier_review_exclusions.services', []));
        if ($snapshot['missing_columns'] !== [] || collect($groups)->contains(fn (array $group): bool => $group['items'] !== [])) {
            return redirect()->route('provider-payments.courier-movements.review-parameters')
                ->withErrors(['import' => 'Todavía existen parámetros pendientes. Corrígelos antes de cargar movimientos.']);
        }

        $result = $this->importStoredFile(
            Storage::disk('local')->path($snapshot['stored_path']),
            $snapshot['extension'],
            $tenant->id,
            (bool) ($validated['replace_duplicates'] ?? false),
            $request->session()->get('courier_review_exclusions.coverages', []),
            $request->session()->get('courier_review_corrections.coverages', []),
            $processName,
            $processType === 'variables' ? 'Variables' : $processSuffix,
            $processType,
            $request->session()->get('courier_review_exclusions.services', []),
        );
        Storage::disk('local')->delete($snapshot['stored_path']);
        $request->session()->forget(['courier_review', 'courier_review_exclusions', 'courier_review_comments', 'courier_review_corrections']);

        return view('provider-payments::courier-movements-import-result', ['result' => $result, 'fileName' => $snapshot['file'], 'processName' => $processName]);
    }

    public function excludeCoverages(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'coverage_errors' => ['nullable', 'array'],
            'coverage_errors.*.source_key' => ['required', 'string', 'max:500'],
            'coverage_errors.*.exclude' => ['nullable', 'boolean'],
            'coverage_errors.*.comment' => ['nullable', 'string', 'max:2000'],
            'coverage_errors.*.corrected_commune' => ['nullable', 'string', 'max:150'],
        ]);
        $rows = $validated['coverage_errors'] ?? [];
        $excluded = collect($rows)->filter(fn (array $row): bool => (bool) ($row['exclude'] ?? false))->pluck('source_key')->unique()->values()->all();
        $comments = collect($rows)->mapWithKeys(fn (array $row): array => [$row['source_key'] => trim((string) ($row['comment'] ?? ''))])->all();
        $corrections = collect($rows)->filter(fn (array $row): bool => ! ($row['exclude'] ?? false) && filled($row['corrected_commune'] ?? null))
            ->mapWithKeys(fn (array $row): array => [$row['source_key'] => trim($row['corrected_commune'])])->all();
        if ($corrections !== []) {
            $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
            $knownCommunes = Coverage::query()->where('tenant_id', $tenant->id)->where('is_active', true)->pluck('commune_name')
                ->map(fn (string $commune): string => $this->comparisonKey($commune))->flip();
            foreach ($corrections as $commune) {
                if (! $knownCommunes->has($this->comparisonKey($commune))) {
                    return back()->withErrors(['coverage_errors' => "La comuna corregida {$commune} no existe en el maestro de coberturas."]);
                }
            }
        }
        $request->session()->put('courier_review_exclusions.coverages', $excluded);
        $request->session()->put('courier_review_comments.coverages', $comments);
        $request->session()->put('courier_review_corrections.coverages', $corrections);
        $snapshot = $request->session()->get('courier_review');
        if ($snapshot) {
            CourierImportError::query()->where('batch_id', $snapshot['batch_id'])->where('category', 'coverages')->update(['exclude_from_import' => false, 'status' => 'PENDIENTE']);
            CourierImportError::query()->where('batch_id', $snapshot['batch_id'])->where('category', 'coverages')
                ->whereIn('source_key', $excluded)->update(['exclude_from_import' => true, 'status' => 'NO_CARGAR']);
            CourierImportError::query()->where('batch_id', $snapshot['batch_id'])->where('category', 'coverages')
                ->whereIn('source_key', array_keys($corrections))->update(['exclude_from_import' => false, 'status' => 'RESUELTO']);
            foreach ($comments as $sourceKey => $comment) {
                CourierImportError::query()->where('batch_id', $snapshot['batch_id'])->where('category', 'coverages')->where('source_key', $sourceKey)->update(['comment' => $comment ?: null]);
            }
        }

        return redirect()->route('provider-payments.courier-movements.review-parameters')->with('status', 'La selección de registros que no se cargarán quedó guardada.');
    }

    public function excludeServices(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'service_errors' => ['nullable', 'array'],
            'service_errors.*.source_key' => ['required', 'string', 'max:500'],
            'service_errors.*.exclude' => ['nullable', 'boolean'],
            'service_errors.*.comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $rows = $validated['service_errors'] ?? [];
        $excluded = collect($rows)->filter(fn (array $row): bool => (bool) ($row['exclude'] ?? false))->pluck('source_key')->unique()->values()->all();
        $comments = collect($rows)->mapWithKeys(fn (array $row): array => [$row['source_key'] => trim((string) ($row['comment'] ?? ''))])->all();
        $request->session()->put('courier_review_exclusions.services', $excluded);
        $request->session()->put('courier_review_comments.services', $comments);

        $snapshot = $request->session()->get('courier_review');
        if ($snapshot) {
            CourierImportError::query()->where('batch_id', $snapshot['batch_id'])->where('category', 'services')->update(['exclude_from_import' => false, 'status' => 'PENDIENTE']);
            CourierImportError::query()->where('batch_id', $snapshot['batch_id'])->where('category', 'services')->whereIn('source_key', $excluded)->update(['exclude_from_import' => true, 'status' => 'NO_CARGAR']);
            foreach ($comments as $sourceKey => $comment) {
                CourierImportError::query()->where('batch_id', $snapshot['batch_id'])->where('category', 'services')->where('source_key', $sourceKey)->update(['comment' => $comment ?: null]);
            }
        }

        return redirect()->route('provider-payments.courier-movements.review-parameters')->with('status', 'Los servicios que no se cargarán quedaron guardados.');
    }

    public function downloadErrors(Request $request): StreamedResponse
    {
        $snapshot = $request->session()->get('courier_review');
        abort_unless($snapshot, 404);
        $errors = CourierImportError::query()->where('batch_id', $snapshot['batch_id'])->orderBy('category')->orderByDesc('affected_records')->get();

        return response()->streamDownload(function () use ($errors): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Archivo', 'Categoría', 'Dato del archivo', 'Registros afectados', 'Estado', 'No cargar', 'Comentario de respaldo', 'Acción requerida'], ';');
            foreach ($errors as $error) {
                fputcsv($output, [$error->file_name, $error->category, implode(' → ', $error->source_values), $error->affected_records, $error->status, $error->exclude_from_import ? 'SI' : 'NO', $error->comment, $error->action], ';');
            }
            fclose($output);
        }, 'errores_'.$snapshot['batch_id'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function syncErrors(array $snapshot, array $groups, int $tenantId, array $excludedCoverages, array $excludedServices): void
    {
        $batchId = $snapshot['batch_id'] ?? (string) Str::uuid();
        $comments = session('courier_review_comments.coverages', []);
        $serviceComments = session('courier_review_comments.services', []);
        CourierImportError::query()->where('batch_id', $batchId)->update(['status' => 'RESUELTO']);
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $sourceKey = implode(' → ', $item['values']);
                $excluded = ($group['key'] === 'coverages' && in_array($sourceKey, $excludedCoverages, true))
                    || ($group['key'] === 'services' && in_array($sourceKey, $excludedServices, true));
                CourierImportError::query()->updateOrCreate(
                    ['batch_id' => $batchId, 'category' => $group['key'], 'source_key' => $sourceKey],
                    ['tenant_id' => $tenantId, 'file_name' => $snapshot['file'], 'source_values' => $item['values'], 'affected_records' => $item['count'], 'action' => $item['action'], 'status' => $excluded ? 'NO_CARGAR' : 'PENDIENTE', 'exclude_from_import' => $excluded, 'comment' => match ($group['key']) {
                        'coverages' => $comments[$sourceKey] ?? null,
                        'services' => $serviceComments[$sourceKey] ?? null,
                        default => null,
                    }],
                );
            }
        }
    }

    private function hideExcludedCoverages(array $groups, array $excludedCoverages): array
    {
        foreach ($groups as &$group) {
            if ($group['key'] !== 'coverages') {
                continue;
            }
            $group['items'] = array_values(array_filter(
                $group['items'],
                fn (array $item): bool => ! in_array(implode(' → ', $item['values']), $excludedCoverages, true),
            ));
            $group['affected'] = array_sum(array_column($group['items'], 'count'));
        }
        unset($group);

        return $groups;
    }

    private function hideExcludedGroupItems(array $groups, string $groupKey, array $excludedKeys): array
    {
        foreach ($groups as &$group) {
            if ($group['key'] !== $groupKey) {
                continue;
            }
            $group['items'] = array_values(array_filter($group['items'], fn (array $item): bool => ! in_array(implode(' → ', $item['values']), $excludedKeys, true)));
            $group['affected'] = array_sum(array_column($group['items'], 'count'));
        }
        unset($group);

        return $groups;
    }

    private function normalizeHeader(mixed $header): string
    {
        return Str::of((string) $header)->squish()->lower()->ascii()->toString();
    }

    private function detectCsvDelimiter(string $path): string
    {
        $line = (string) file_get_contents($path, false, null, 0, 65536);
        $counts = [];
        foreach (["\t", ';', ',', '|'] as $delimiter) {
            $counts[$delimiter] = substr_count(strtok($line, "\r\n") ?: '', $delimiter);
        }
        arsort($counts);

        return (string) array_key_first($counts);
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

    /** @return array{created:int,replaced:int,duplicates:int,excluded:int,invalid:int,total:int} */
    private function importStoredFile(string $path, string $extension, int $tenantId, bool $replaceDuplicates, array $excludedCommunes, array $correctedCommunes, string $processName, string $paymentType, string $processType, array $excludedServices): array
    {
        $reader = $extension === 'csv' ? new CsvReader(new CsvOptions(FIELD_DELIMITER: $this->detectCsvDelimiter($path))) : new XlsxReader;
        $clients = Client::query()->where('tenant_id', $tenantId)->where('is_active', true)->get()
            ->mapWithKeys(fn (Client $client): array => [$this->comparisonKey((string) $client->source_merchant_name) => $client]);
        $weights = WeightTransformation::query()->where('tenant_id', $tenantId)->where('is_active', true)->get()->keyBy('comparison_key');
        $excluded = collect($excludedCommunes)->map(fn (string $value): string => $this->comparisonKey($value))->flip();
        $corrections = collect($correctedCommunes)->mapWithKeys(fn (string $commune, string $source): array => [$this->comparisonKey($source) => $commune]);
        $excludedServiceKeys = collect($excludedServices)->map(fn (string $value): string => $this->comparisonKey($value))->flip();
        $result = ['created' => 0, 'replaced' => 0, 'duplicates' => 0, 'excluded' => 0, 'invalid' => 0, 'total' => 0];
        $seenTrackings = [];
        $reader->open($path);
        foreach ($reader->getSheetIterator() as $sheet) {
            $headers = [];
            $indexes = [];
            $chunk = [];
            foreach ($sheet->getRowIterator() as $row) {
                $values = array_map(fn (mixed $value): string => $this->utf8((string) ($value ?? '')), $row->toArray());
                if ($headers === []) {
                    $headers = array_map(fn (string $header): string => $this->normalizeHeader($header), $values);
                    foreach ($headers as $index => $header) {
                        $indexes[$header] = $index;
                    }

                    continue;
                }
                if (count(array_filter($values, fn (string $value): bool => trim($value) !== '')) === 0) {
                    continue;
                }
                $result['total']++;
                $tracking = trim($this->rowValue($values, $indexes, ['seguimiento paquete', 'seguimiento']));
                if ($tracking === '') {
                    $result['invalid']++;

                    continue;
                }
                if (isset($seenTrackings[$tracking])) {
                    $result['duplicates']++;

                    continue;
                }
                $seenTrackings[$tracking] = true;
                $commune = trim($this->rowValue($values, $indexes, ['comuna de destino', 'comuna destino', 'comuna']));
                $address = trim($this->rowValue($values, $indexes, ['direccion']));
                $recipientName = trim($this->rowValue($values, $indexes, ['nombre del destinatario']));
                [$address, $commune] = $this->destinationValues($processType, $address, $commune, $recipientName);
                $coverageKey = $this->comparisonKey($this->coverageSourceKey($commune, $address));
                if ($excluded->has($coverageKey) || $excluded->has($this->comparisonKey($commune))) {
                    $result['excluded']++;

                    continue;
                }
                $commune = $corrections->get($coverageKey, $commune);
                $merchant = trim($this->rowValue($values, $indexes, ['comerciante']));
                $service = trim($this->rowValue($values, $indexes, ['servicio']));
                if ($excludedServiceKeys->has($this->comparisonKey($merchant.' → '.$service))) {
                    $result['excluded']++;

                    continue;
                }
                $client = $clients->get($this->comparisonKey($merchant));
                $weightSource = trim($this->rowValue($values, $indexes, ['peso']));
                $weightNumber = $this->number($weightSource) ?? 1;
                $transformedWeight = (int) ($weights->get($this->weightKey($weightSource))?->transformed_weight ?? 1);
                $now = now();
                $chunk[] = [
                    'tenant_id' => $tenantId, 'client_id' => $client?->id, 'source_system' => 'Geolize',
                    'fecha' => CourierMovement::fechaFromTrackingNumber($tracking)?->toDateString(), 'tracking_number' => $tracking,
                    'tracking_code' => $this->nullable($this->rowValue($values, $indexes, ['codigo de seguimiento'])),
                    'external_code' => $this->nullable($this->rowValue($values, $indexes, ['codigo externo'])),
                    'cost_center' => $this->nullable($this->rowValue($values, $indexes, ['centro de costo'])),
                    'purchase_order' => $this->nullable($this->rowValue($values, $indexes, ['orden de compra'])),
                    'dispatch_guide' => $this->nullable($this->rowValue($values, $indexes, ['guia de despacho'])),
                    'weight_kg' => $weightNumber, 'peso_real' => null, 'peso_transformado' => $transformedWeight, 'peso_final' => null, 'tipo_pago' => $paymentType, 'nombre_proceso' => $processName,
                    'length_cm' => $this->number($this->rowValue($values, $indexes, ['largo'])), 'width_cm' => $this->number($this->rowValue($values, $indexes, ['ancho'])), 'height_cm' => $this->number($this->rowValue($values, $indexes, ['alto'])),
                    'status' => $this->nullable($this->rowValue($values, $indexes, ['estado de entrega', 'estado'])), 'delivery_attempts' => (int) ($this->number($this->rowValue($values, $indexes, ['intentos de entrega'])) ?? 0),
                    'merchant_name' => $this->nullable($merchant), 'service_name' => $this->nullable($service), 'campaign_name' => $this->nullable($this->rowValue($values, $indexes, ['nombre de campana'])),
                    'recipient_name' => $this->encrypted($recipientName), 'recipient_company_name' => $this->encrypted($this->rowValue($values, $indexes, ['empresa del destinatario'])), 'recipient_address' => $this->encrypted($address),
                    'destination_commune_name' => $this->nullable($commune), 'recipient_phone' => $this->encrypted($this->rowValue($values, $indexes, ['telefono del destinatario'])), 'recipient_email' => $this->encrypted($this->rowValue($values, $indexes, ['email del destinatario'])),
                    'declared_value' => $this->number($this->rowValue($values, $indexes, ['valor'])) ?? 0, 'received_at' => $this->dateTime($this->rowValue($values, $indexes, ['fecha de recepcion'])), 'estimated_delivery_date' => $this->dateTime($this->rowValue($values, $indexes, ['entrega estimada']), true), 'delivered_at' => $this->dateTime($this->rowValue($values, $indexes, ['fecha de entrega'])),
                    'merchant_pickup' => in_array($this->comparisonKey($this->rowValue($values, $indexes, ['retiro en comerciante'])), ['si', 'yes', '1'], true), 'pickup_warehouse_name' => $this->nullable($this->rowValue($values, $indexes, ['bodega de retiro'])), 'delivery_route_code' => $this->nullable($this->rowValue($values, $indexes, ['ruta de entrega'])),
                    'courier_name' => $this->nullable($this->rowValue($values, $indexes, ['nombre del repartidor'])), 'courier_phone' => $this->encrypted($this->rowValue($values, $indexes, ['telefono del repartidor'])), 'delivery_user_name' => $this->nullable($this->rowValue($values, $indexes, ['usuario que realizo la entrega'])),
                    'created_at' => $now, 'updated_at' => $now,
                ];
                if (count($chunk) >= 300) {
                    $this->persistMovementChunk($chunk, $tenantId, $replaceDuplicates, $result);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                $this->persistMovementChunk($chunk, $tenantId, $replaceDuplicates, $result);
            }
            break;
        }
        $reader->close();

        return $result;
    }

    private function persistMovementChunk(array $rows, int $tenantId, bool $replace, array &$result): void
    {
        $trackings = array_column($rows, 'tracking_number');
        $existing = DB::table('movimientos_courier')->where('tenant_id', $tenantId)->whereIn('tracking_number', $trackings)->pluck('tracking_number')->flip();
        $newRows = array_values(array_filter($rows, fn (array $row): bool => ! $existing->has($row['tracking_number'])));
        $duplicateRows = array_values(array_filter($rows, fn (array $row): bool => $existing->has($row['tracking_number'])));
        if ($newRows !== []) {
            DB::table('movimientos_courier')->insert($newRows);
            $result['created'] += count($newRows);
        }
        $result['duplicates'] += count($duplicateRows);
        if ($replace && $duplicateRows !== []) {
            DB::table('movimientos_courier')->upsert($duplicateRows, ['tenant_id', 'tracking_number'], array_values(array_diff(array_keys($duplicateRows[0]), ['tenant_id', 'tracking_number', 'created_at'])));
            $result['replaced'] += count($duplicateRows);
        }
    }

    private function rowValue(array $values, array $indexes, array $aliases): string
    {
        foreach ($aliases as $alias) {
            if (array_key_exists($alias, $indexes)) {
                return (string) ($values[$indexes[$alias]] ?? '');
            }
        }

        return '';
    }

    private function comparisonKey(string $value): string
    {
        return Str::of($value)->squish()->lower()->ascii()->toString();
    }

    private function weightKey(string $value): string
    {
        return preg_match('/-?\d+(?:[.,]\d+)?/', $value, $matches)
            ? rtrim(rtrim(number_format((float) str_replace(',', '.', $matches[0]), 6, '.', ''), '0'), '.')
            : mb_strtolower(trim($value));
    }

    private function number(string $value): ?float
    {
        $clean = preg_replace('/[^0-9,.-]/', '', $value);
        if ($clean === null || $clean === '') {
            return null;
        }
        $clean = str_contains($clean, ',') && str_contains($clean, '.') ? str_replace(',', '', $clean) : str_replace(',', '.', $clean);

        return is_numeric($clean) ? (float) $clean : null;
    }

    private function dateTime(string $value, bool $dateOnly = false): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        foreach (['d/m/Y H:i', 'd/m/Y H:i:s', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value);
                if ($date !== false) {
                    return $dateOnly ? $date->toDateString() : $date->format('Y-m-d H:i:s');
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    private function encrypted(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : Crypt::encryptString($value);
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    private function coverageSourceKey(string $commune, string $address): string
    {
        return $address === '' ? $commune : $commune.' → '.$address;
    }

    /** @return array{0:string,1:string} */
    private function destinationValues(string $processType, string $address, string $commune, string $recipientName): array
    {
        $address = trim($address);
        $commune = trim($commune);
        if ($processType !== 'retornos') {
            return [$address, $commune];
        }

        $combinedAddress = implode(' - ', array_filter([$address, $commune], fn (string $value): bool => $value !== ''));
        $recipientDestination = trim($recipientName);
        if (str_contains($recipientDestination, ',')) {
            $recipientDestination = Str::after($recipientDestination, ',');
        } else {
            $recipientDestination = (string) preg_replace('/^\s*Desde[\s-]*/iu', '', $recipientDestination);
        }
        $destinationCommune = trim($recipientDestination);

        return [$combinedAddress, $destinationCommune];
    }

    private function processSuffix(string $processType): string
    {
        return match ($processType) {
            'lanas' => 'Lanas',
            'retornos' => 'Retornos',
            default => 'Variable',
        };
    }

    private function uploadRoute(string $processType): string
    {
        return match ($processType) {
            'lanas' => 'provider-payments.courier-movements.lanas',
            'retornos' => 'provider-payments.courier-movements.retornos',
            default => 'provider-payments.courier-movements.upload',
        };
    }
}
