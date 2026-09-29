<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\CourierMovement;
use App\Models\RealWeight;
use App\Models\Tenant;
use App\Models\WeightTransformation;
use App\Modules\ProviderPayments\Services\RealWeightImporter;
use App\Modules\ProviderPayments\Services\RealWeightSynchronizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WeightMaintainerController
{
    public function transformed(Request $request): View
    {
        $tenant = $this->tenant();
        $weights = WeightTransformation::query()->where('tenant_id', $tenant->id)
            ->orderByRaw('CAST(comparison_key AS DECIMAL(12, 4))')->orderBy('source_weight')->get();
        $knownKeys = $weights->pluck('comparison_key')->map(fn ($key): string => (string) $key)->flip();
        $newWeights = collect();
        if ($request->boolean('discover')) {
            $newWeights = CourierMovement::query()->where('tenant_id', $tenant->id)->whereNotNull('weight_kg')
                ->selectRaw('weight_kg, COUNT(*) AS movement_count')->groupBy('weight_kg')->orderBy('weight_kg')->get()
                ->map(function (CourierMovement $movement): array {
                    $sourceWeight = $this->sourceWeight((string) $movement->weight_kg);

                    return [
                        'source_weight' => $sourceWeight,
                        'comparison_key' => $this->weightKey($sourceWeight),
                        'suggested_weight' => max(1, WeightTransformation::integerPart($sourceWeight) ?? 1),
                        'movement_count' => (int) $movement->movement_count,
                    ];
                })->reject(fn (array $weight): bool => $knownKeys->has($weight['comparison_key']))->values();
        }

        return view('provider-payments::weights-transformed', [
            'weights' => $weights,
            'newWeights' => $newWeights,
            'discovering' => $request->boolean('discover'),
        ]);
    }

    public function real(Request $request): View
    {
        $tenant = $this->tenant();
        $period = trim((string) $request->query('period', ''));
        $merchant = trim((string) $request->query('merchant', ''));
        $service = trim((string) $request->query('service', ''));
        $search = trim((string) $request->query('q', ''));
        $base = RealWeight::query()->where('tenant_id', $tenant->id);
        $periods = (clone $base)->selectRaw("strftime('%Y-%m', fecha_proceso) AS period")
            ->distinct()->orderByDesc('period')->pluck('period');
        if ($period === '') {
            $period = (string) ($periods->first() ?? '');
        }
        $merchants = (clone $base)->where('comerciante', '<>', '')
            ->select('comerciante')->distinct()->orderBy('comerciante')->pluck('comerciante');
        $services = (clone $base)->where('servicio', '<>', '')
            ->select('servicio')->distinct()->orderBy('servicio')->pluck('servicio');
        $rows = (clone $base)
            ->when($period !== '', fn ($query) => $query->whereRaw("strftime('%Y-%m', fecha_proceso) = ?", [$period]))
            ->when($merchant !== '', fn ($query) => $query->where('comerciante', $merchant))
            ->when($service !== '', fn ($query) => $query->where('servicio', $service))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('seguimiento_paquete', 'like', '%'.$search.'%')
                    ->orWhere('codigo_seguimiento', 'like', '%'.$search.'%')
                    ->orWhere('comerciante', 'like', '%'.$search.'%')
                    ->orWhere('servicio', 'like', '%'.$search.'%')
                    ->orWhere('cliente_origen', 'like', '%'.$search.'%')
                    ->orWhere('talla', 'like', '%'.$search.'%')
                    ->orWhere('operario', 'like', '%'.$search.'%')
                    ->orWhere('guia_cliente', 'like', '%'.$search.'%');
            }))
            ->orderByDesc('fecha_proceso')->orderByDesc('id')->paginate(100)->withQueryString();
        $reportPath = "real-weight-imports/last-{$tenant->id}.json";
        $lastReport = Storage::disk('local')->exists($reportPath)
            ? (json_decode(Storage::disk('local')->get($reportPath), true) ?: []) : [];
        $importIssues = session('import_issues', $lastReport['issues'] ?? []);
        $issueOverflow = session('issue_overflow', $lastReport['issue_overflow'] ?? 0);
        $importedAt = $lastReport['imported_at'] ?? null;

        return view('provider-payments::weights-real', compact(
            'rows', 'periods', 'period', 'merchants', 'merchant', 'services', 'service', 'search',
            'importIssues', 'issueOverflow', 'importedAt',
        ));
    }

    public function importRealWeights(Request $request, RealWeightImporter $importer): RedirectResponse
    {
        $file = $request->validate(['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'extensions:xlsx,csv', 'max:102400']])['file'];
        $tenant = $this->tenant();
        set_time_limit(300);
        $result = $importer->import($file->getRealPath(), $tenant->id, strtolower($file->getClientOriginalExtension()));

        return redirect()->route('provider-payments.maintainers.pesos.reales', ['period' => $result['period']])
            ->with('status', sprintf('Peso Real: %s nuevos, %s actualizados, %s ya pagados y %s de períodos cerrados omitidos. %s filas con errores omitidas y %s seguimientos repetidos resueltos con la fecha más reciente. Revisa el detalle consolidado de incidencias. %s sin cruce con Movimientos Courier; su cliente y servicio se completarán al cargar ese movimiento.',
                number_format($result['created'], 0, ',', '.'), number_format($result['updated'], 0, ',', '.'),
                number_format($result['paid'], 0, ',', '.'), number_format($result['closed'], 0, ',', '.'),
                number_format($result['invalid'], 0, ',', '.'), number_format($result['duplicate'], 0, ',', '.'),
                number_format($result['unmatched'], 0, ',', '.')))
            ->with('import_issues', $result['issues'])
            ->with('issue_overflow', $result['issue_overflow']);
    }

    public function syncRealWeights(RealWeightSynchronizer $synchronizer): RedirectResponse
    {
        $tenant = $this->tenant();
        $result = $synchronizer->syncOpen($tenant->id);

        return redirect()->route('provider-payments.maintainers.pesos.reales')
            ->with('status', number_format($result['updated'], 0, ',', '.').' movimientos Courier actualizados con Peso Real y Peso Final. '.number_format($result['without_match'], 0, ',', '.').' sin coincidencia de Seguimiento paquete en Peso_Real; conservaron su Peso Real y se recalculó Peso Final.'
                .($result['protected'] > 0 ? ' '.number_format($result['protected'], 0, ',', '.').' registros pagados o de períodos cerrados quedaron intactos.' : ''));
    }

    public function storeReal(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'source_weight' => ['required', 'string', 'max:100'],
            'transformed_weight' => ['required', 'integer', 'min:1'],
        ]);
        $tenant = $this->tenant();
        $sourceWeight = trim($validated['source_weight']);

        $comparisonKey = $this->weightKey($sourceWeight);
        if (WeightTransformation::query()->where('tenant_id', $tenant->id)->where('comparison_key', $comparisonKey)->exists()) {
            throw ValidationException::withMessages(['source_weight' => 'Este Peso Fuente ya existe en el maestro.']);
        }

        WeightTransformation::create([
            'tenant_id' => $tenant->id,
            'source_weight' => $sourceWeight,
            'comparison_key' => $comparisonKey,
            'transformed_weight' => $validated['transformed_weight'],
            'is_active' => true,
        ]);

        return redirect()->route('provider-payments.maintainers.pesos.transformados', $request->input('return_to') === 'discovery' ? ['discover' => 1] : [])
            ->with('status', "Peso Fuente {$sourceWeight} agregado correctamente.");
    }

    public function storeReviewWeights(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'weights' => ['required', 'array', 'min:1', 'max:5000'],
            'weights.*.source_weight' => ['required', 'string', 'max:100', 'distinct'],
            'weights.*.transformed_weight' => ['required', 'integer', 'min:1'],
        ]);
        $snapshot = $request->session()->get('courier_review');
        if (! $snapshot) {
            return redirect()->route('provider-payments.courier-movements.upload')
                ->withErrors(['weights' => 'Primero debes validar el archivo Courier.']);
        }
        $allowedWeights = collect($snapshot['groups']['weights'] ?? [])->pluck('values.0')->all();
        foreach ($validated['weights'] as $weight) {
            if (! in_array($weight['source_weight'], $allowedWeights, true)
                || WeightTransformation::integerPart($weight['source_weight']) === null) {
                throw ValidationException::withMessages(['weights' => 'Hay un peso ajeno al archivo o con formato inválido. Revisa la tabla antes de guardar.']);
            }
        }

        $tenant = $this->tenant();
        DB::transaction(function () use ($tenant, $validated): void {
            foreach ($validated['weights'] as $weight) {
                $sourceWeight = trim($weight['source_weight']);
                WeightTransformation::query()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'comparison_key' => $this->weightKey($sourceWeight)],
                    ['source_weight' => $sourceWeight, 'transformed_weight' => $weight['transformed_weight'], 'is_active' => true],
                );
            }
        });

        return redirect()->route('provider-payments.courier-movements.review-parameters')
            ->with('status', count($validated['weights']).' pesos transformados y guardados. Revisa los pendientes restantes antes de cargar.');
    }

    public function update(Request $request, WeightTransformation $weight): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($weight->tenant_id === $tenant->id, 404);
        $weight->update($request->validate([
            'transformed_weight' => ['required', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
        ]));

        return redirect()->route('provider-payments.maintainers.pesos.transformados')
            ->with('status', "Peso Fuente {$weight->source_weight} actualizado correctamente.");
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->where('code', '4N')->firstOrFail();
    }

    private function weightKey(string $value): string
    {
        return preg_match('/-?\d+(?:[.,]\d+)?/', $value, $matches)
            ? rtrim(rtrim(number_format((float) str_replace(',', '.', $matches[0]), 6, '.', ''), '0'), '.')
            : mb_strtolower(trim($value));
    }

    private function sourceWeight(string $value): string
    {
        $number = rtrim(rtrim(number_format((float) str_replace(',', '.', $value), 3, '.', ''), '0'), '.');

        return $number.' kg';
    }
}
