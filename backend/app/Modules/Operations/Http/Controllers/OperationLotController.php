<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\OperationAccess;
use App\Modules\Operations\Services\OperationWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OperationLotController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = OperationAccess::tenant($request);

        $loads = DB::table('Ope_Cargas')->where('tenant_id', $tenant)->where('status', 'completed')->orderByDesc('id')->get(['id', 'filename', 'source_type', 'created_at', 'row_count', 'mapping']);
        $selectedLoad = $loads->firstWhere('id', $request->integer('load'));
        $lots = DB::table('Ope_Lotes')->where('tenant_id', $tenant)->orderByDesc('id')->paginate(20);
        $lotIds = $lots->getCollection()->pluck('id');

        return view('operations::lots', [
            'lots' => $lots,
            'loads' => $loads,
            'receptionSummaries' => $this->receptionSummaries($loads),
            'lotSources' => $lotIds->isEmpty() ? collect() : DB::table('Ope_LoteFuentes as source')
                ->join('Ope_Cargas as load', 'load.id', '=', 'source.load_id')
                ->whereIn('source.lot_id', $lotIds)
                ->orderBy('load.id')
                ->get(['source.lot_id', 'load.filename', 'load.mapping'])
                ->groupBy('lot_id'),
            'lotPackageCounts' => $lotIds->isEmpty() ? collect() : DB::table('Ope_Bultos')
                ->whereIn('lot_id', $lotIds)
                ->where('excluded', false)
                ->select('lot_id')
                ->selectRaw('COUNT(*) as total')
                ->groupBy('lot_id')
                ->pluck('total', 'lot_id'),
            'selectedMasterLoadId' => $selectedLoad?->source_type === 'master' ? $selectedLoad->id : null,
            'selectedReceptionLoadIds' => $selectedLoad?->source_type === 'reception' ? [$selectedLoad->id] : [],
        ]);
    }

    private function receptionSummaries(Collection $loads): array
    {
        $receptions = $loads->where('source_type', 'reception')->keyBy('id');
        if ($receptions->isEmpty()) {
            return [];
        }

        $profiles = $receptions->mapWithKeys(fn ($load): array => [$load->id => json_decode($load->mapping, true)['profile'] ?? '']);
        $counts = [];
        foreach (DB::table('Ope_FilasFuente')->whereIn('load_id', $receptions->keys())->select('load_id', 'data', 'raw')->cursor() as $row) {
            $data = json_decode($row->data, true) ?: [];
            $client = trim((string) ($data['client_name'] ?? ''));
            if ($client === '' && $profiles->get($row->load_id) === 'legacy') {
                $raw = json_decode($row->raw, true) ?: [];
                $client = trim((string) ($raw[3] ?? ''));
            }
            $client = $client !== '' ? $client : 'Cliente no informado en el archivo';
            $operator = trim((string) ($data['operator'] ?? ''));
            $operator = $operator !== '' ? $operator : 'Operario no informado';
            $counts[$row->load_id][$client][$operator] = ($counts[$row->load_id][$client][$operator] ?? 0) + 1;
        }

        $summaries = [];
        foreach ($counts as $loadId => $clients) {
            ksort($clients, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($clients as $client => $operators) {
                ksort($operators, SORT_NATURAL | SORT_FLAG_CASE);
                foreach ($operators as $operator => $count) {
                    $summaries[$loadId][] = ['client' => $client, 'operator' => $operator, 'count' => $count];
                }
            }
        }

        return $summaries;
    }

    public function store(Request $request, OperationWorkflow $workflow): RedirectResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:160'], 'operation_date' => ['required', 'date_format:Y-m-d'], 'master_load_id' => ['required', 'integer'], 'reception_load_ids' => ['required', 'array', 'min:1'], 'reception_load_ids.*' => ['required', 'integer', 'distinct']]);
        $id = $workflow->createLot(OperationAccess::tenant($request), $request->user()->id, $input);

        return redirect()->route('operations.lots.show', $id)->with('status', 'Proceso preparado desde Recepción. Revisa las incidencias y las agencias.');
    }

    public function show(Request $request, int $lot): View
    {
        $record = OperationAccess::lot($request, $lot);
        $packages = DB::table('Ope_Bultos')->where('lot_id', $lot);
        if ($request->filled('search')) {
            $packages->where('tracking', 'like', '%'.$request->string('search')->toString().'%');
        }
        $issues = DB::table('Ope_Incidencias')->where('lot_id', $lot)->orderByRaw('resolved_at IS NOT NULL')->orderBy('id')->get();
        $coverageIssues = $issues->where('code', 'coverage_conflict');
        $issuePackages = DB::table('Ope_Incidencias as issue')
            ->join('Ope_Bultos as package', 'package.id', '=', 'issue.package_id')
            ->where('issue.lot_id', $lot)
            ->whereIn('issue.id', $coverageIssues->pluck('id'))
            ->get(['issue.id as issue_id', 'package.tracking'])
            ->keyBy('issue_id');
        $masterRows = collect();
        foreach ($issuePackages->pluck('tracking')->unique()->chunk(500) as $tracking) {
            $masterRows = $masterRows->concat(DB::table('Ope_FilasFuente')
                ->where('load_id', $record->master_load_id)
                ->whereIn('tracking', $tracking)
                ->orderBy('line')
                ->get(['line', 'tracking', 'data', 'raw', 'errors']));
        }
        $masterRows = $masterRows->groupBy('tracking');
        $masterRowsByIssue = $coverageIssues->mapWithKeys(fn ($issue): array => [
            $issue->id => $masterRows->get($issuePackages->get($issue->id)?->tracking, collect()),
        ]);
        $coverages = DB::table('PPR_coverages')->where(['tenant_id' => OperationAccess::tenant($request), 'is_active' => true])
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $record->operation_date))
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $record->operation_date))
            ->orderBy('commune_name')->get();
        $suggestedCoverageByIssue = $coverageIssues->mapWithKeys(function ($issue) use ($coverages): array {
            $commune = (string) (json_decode($issue->context, true)['commune'] ?? '');
            $matches = $commune === '' ? collect() : $coverages->filter(fn ($coverage): bool => $coverage->commune_name === $commune);

            return [$issue->id => $matches->count() === 1 ? $matches->first() : null];
        });
        $preferredReadingByIssue = $issues->where('code', 'reading_conflict')->mapWithKeys(function ($issue): array {
            $context = json_decode($issue->context, true);
            $preferredId = null;
            $highestWeight = null;
            foreach ($context['readings'] ?? [] as $index => $reading) {
                $weight = $reading['weight'] ?? null;
                if (! is_numeric($weight) || ! isset($context['row_ids'][$index])) {
                    continue;
                }
                if ($highestWeight === null || (float) $weight > $highestWeight) {
                    $highestWeight = (float) $weight;
                    $preferredId = $context['row_ids'][$index];
                }
            }

            return [$issue->id => $preferredId];
        });

        return view('operations::lot-show', [
            'lot' => $record, 'packages' => $packages->orderBy('tracking')->paginate(40)->withQueryString(),
            'count' => DB::table('Ope_Bultos')->where(['lot_id' => $lot, 'excluded' => false])->count(),
            'weight' => DB::table('Ope_Bultos')->where(['lot_id' => $lot, 'excluded' => false])->sum('weight'),
            'issues' => $issues,
            'masterRowsByIssue' => $masterRowsByIssue,
            'coverages' => $coverages,
            'suggestedCoverageByIssue' => $suggestedCoverageByIssue,
            'preferredReadingByIssue' => $preferredReadingByIssue,
            'audit' => DB::table('Ope_Auditoria')->where(['tenant_id' => OperationAccess::tenant($request), 'entity' => 'lote', 'entity_id' => $lot])->orderByDesc('id')->get(),
        ]);
    }

    public function resolve(Request $request, int $lot, int $issue, OperationWorkflow $workflow): RedirectResponse
    {
        OperationAccess::lot($request, $lot);
        OperationAccess::requireSupervisor($request);
        $input = $request->validate(['action' => ['required', Rule::in(['reading', 'coverage', 'exclude'])], 'reason' => ['nullable', 'string', 'max:1000'], 'reading_id' => ['required_if:action,reading', 'nullable', 'integer'], 'coverage_id' => ['required_if:action,coverage', 'nullable', 'integer']]);
        $workflow->resolve(OperationAccess::tenant($request), $request->user()->id, $lot, $issue, $input);

        return back()->with('status', 'Resolución registrada con trazabilidad.');
    }

    public function resolveMany(Request $request, int $lot, OperationWorkflow $workflow): JsonResponse
    {
        OperationAccess::lot($request, $lot);
        OperationAccess::requireSupervisor($request);
        $input = $request->validate([
            'resolutions' => ['required', 'array', 'min:1', 'max:1000'],
            'resolutions.*.issue_id' => ['required', 'integer', 'distinct'],
            'resolutions.*.action' => ['required', Rule::in(['reading', 'coverage', 'exclude'])],
            'resolutions.*.reason' => ['nullable', 'string', 'max:1000'],
            'resolutions.*.reading_id' => ['nullable', 'integer'],
            'resolutions.*.coverage_id' => ['nullable', 'integer'],
        ]);
        foreach ($input['resolutions'] as $resolution) {
            if ($resolution['action'] === 'reading' && empty($resolution['reading_id'])) {
                throw ValidationException::withMessages(['resolutions' => 'Selecciona la lectura correcta en cada incidencia de Recepción.']);
            }
            if ($resolution['action'] === 'coverage' && empty($resolution['coverage_id'])) {
                throw ValidationException::withMessages(['resolutions' => 'Selecciona la cobertura correcta en cada incidencia de cobertura.']);
            }
        }
        $workflow->resolveMany(OperationAccess::tenant($request), $request->user()->id, $lot, $input['resolutions']);
        $request->session()->flash('status', count($input['resolutions']).' resoluciones guardadas con trazabilidad.');

        return response()->json(['saved' => count($input['resolutions'])]);
    }
}
