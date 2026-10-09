<?php

namespace App\Modules\Operations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class OperationDashboardData
{
    public function build(int $tenant, string $period, string $source, string $status, string $grouping): array
    {
        $today = CarbonImmutable::now('America/Santiago')->startOfDay();
        $lastDate = $this->lastActivityDate($tenant, $today->toDateString());
        $weekStart = $today->startOfWeek();
        $weekEnd = $weekStart->addDays(6);
        [$start, $end] = match ($period) {
            'today' => [$today->toDateString(), $today->toDateString()],
            'week' => [$weekStart->toDateString(), $weekEnd->toDateString()],
            default => [$lastDate, $lastDate],
        };

        $routeTree = [];
        $clientTree = [];
        $overall = $this->emptyNode('Total');

        if ($status !== 'reserve') {
            foreach ($this->movementRows($tenant, $start, $end, $source, $status) as $row) {
                $route = $this->name($row->trunk_name ?: $row->transport_trunk_name, 'Sin troncal asignada');
                $segment = $row->role === 'troncal' ? 'Troncal' : $this->roleLabel($row->role).' · '.$this->name($row->post_name ?: $row->configuration_name, 'Sin nombre');
                $agency = $this->name($row->agency_name ?: $row->configuration_name, 'Agencia sin nombre');
                $client = $this->name($row->merchant, 'Cliente sin identificar en Geolize');
                $this->add($overall, $routeTree, $clientTree, $row->tracking, (float) $row->weight, $row->status, $route, $segment, $agency, $client);
            }
        }

        if (in_array($status, ['all', 'reserve'], true)) {
            foreach ($this->reservationRows($tenant, $start, $end, $source) as $row) {
                $route = $this->name($row->trunk_name, 'Sin troncal asignada');
                $segment = 'Reserva · '.$this->name($row->route_label, 'Ruta sin nombre');
                $agency = $this->name($row->agency_name ?: $row->commune, 'Agencia sin nombre');
                $client = $this->name($row->merchant, 'Cliente sin identificar en Geolize');
                $this->add($overall, $routeTree, $clientTree, $row->tracking, (float) $row->weight, 'reserve', $route, $segment, $agency, $client);
            }
        }

        $rows = [];
        $this->flatten($grouping === 'client' ? $clientTree : $routeTree, $rows, 'dashboard');
        $topWeights = array_column(array_filter($rows, fn (array $row): bool => $row['level'] === 0), 'weight');

        return [
            'period' => $period,
            'source' => $source,
            'status' => $status,
            'grouping' => $grouping,
            'periodStart' => $start,
            'periodEnd' => $end,
            'lastDate' => $lastDate,
            'today' => $today->toDateString(),
            'receipts' => $this->receipts($tenant, $today, $source),
            'totals' => $this->metrics($overall),
            'rows' => $rows,
            'maxWeight' => max([1, ...$topWeights]),
            'week' => $this->week($tenant, $weekStart->toDateString(), $weekEnd->toDateString(), $source, $status),
        ];
    }

    private function lastActivityDate(int $tenant, string $today): string
    {
        $approved = DB::table('Ope_ProgramacionSalidas as departure')
            ->join('Ope_Lotes as lot', 'lot.id', '=', 'departure.lot_id')
            ->where('lot.tenant_id', $tenant)->where('departure.status', 'approved')
            ->where('departure.departure_date', '<=', $today)->max('departure.departure_date');
        $reserved = DB::table('Ope_Reservas as reservation')
            ->join('Ope_Lotes as lot', 'lot.id', '=', 'reservation.source_lot_id')
            ->where('reservation.tenant_id', $tenant)->where('reservation.status', 'pending')
            ->where('lot.operation_date', '<=', $today)->max('lot.operation_date');
        $draft = DB::table('Ope_ProgramacionSalidas as departure')
            ->join('Ope_Lotes as lot', 'lot.id', '=', 'departure.lot_id')
            ->where('lot.tenant_id', $tenant)->where('departure.status', 'draft')
            ->where('departure.departure_date', '<=', $today)->max('departure.departure_date');

        $dates = array_values(array_filter([$approved, $reserved, $draft]));

        return $dates === [] ? $today : max($dates);
    }

    private function movementRows(int $tenant, string $start, string $end, string $source, string $status): iterable
    {
        $query = DB::table('Ope_BultoTramos as leg')
            ->join('Ope_ProgramacionSalidas as departure', 'departure.id', '=', 'leg.departure_id')
            ->join('Ope_Lotes as lot', 'lot.id', '=', 'departure.lot_id')
            ->join('Ope_Bultos as package', 'package.id', '=', 'leg.package_id')
            ->join('Ope_GuiaConfiguraciones as configuration', 'configuration.id', '=', 'leg.configuration_id')
            ->leftJoin('PPR_coverages as coverage', 'coverage.id', '=', 'package.coverage_id')
            ->leftJoin('Ope_Agencias as agency', function ($join): void {
                $join->on('agency.agency_code', '=', 'coverage.ID_ComunaMatrizAgencia')->on('agency.tenant_id', '=', 'lot.tenant_id');
            })
            ->leftJoin('Ope_Troncales as trunk', 'trunk.id', '=', 'agency.trunk_id')
            ->leftJoin('Ope_Troncales as transport_trunk', function ($join): void {
                $join->on('transport_trunk.id', '=', 'configuration.transport_id')->where('configuration.transport_kind', 'trunk');
            })
            ->leftJoin('Ope_Postas as post', function ($join): void {
                $join->on('post.id', '=', 'configuration.transport_id')->where('configuration.transport_kind', 'post');
            });
        $this->joinReceptionSource($query);
        $this->filterSource($query, $source);

        return $query->where('lot.tenant_id', $tenant)
            ->whereBetween('departure.departure_date', [$start, $end])
            ->where('departure.status', '<>', 'cancelled')
            ->when($status === 'approved', fn (Builder $query) => $query->where('departure.status', 'approved'))
            ->when($status === 'draft', fn (Builder $query) => $query->where('departure.status', 'draft'))
            ->select('package.tracking', 'package.weight', 'package.merchant', 'departure.status', 'configuration.role',
                'configuration.name as configuration_name', 'agency.name as agency_name', 'trunk.name as trunk_name',
                'transport_trunk.name as transport_trunk_name', 'post.name as post_name')
            ->distinct()->cursor();
    }

    private function reservationRows(int $tenant, string $start, string $end, string $source): iterable
    {
        $query = DB::table('Ope_Reservas as reservation')
            ->join('Ope_Lotes as lot', 'lot.id', '=', 'reservation.source_lot_id')
            ->join('Ope_Bultos as package', 'package.id', '=', 'reservation.source_package_id')
            ->leftJoin('PPR_coverages as coverage', 'coverage.id', '=', 'package.coverage_id')
            ->leftJoin('Ope_Agencias as agency', function ($join): void {
                $join->on('agency.agency_code', '=', 'coverage.ID_ComunaMatrizAgencia')->on('agency.tenant_id', '=', 'lot.tenant_id');
            })
            ->leftJoin('Ope_Troncales as trunk', 'trunk.id', '=', 'agency.trunk_id');
        $this->joinReceptionSource($query);
        $this->filterSource($query, $source);

        return $query->where('reservation.tenant_id', $tenant)->where('reservation.status', 'pending')
            ->whereBetween('lot.operation_date', [$start, $end])
            ->select('package.tracking', 'package.weight', 'package.merchant', 'package.commune',
                'reservation.route_label', 'agency.name as agency_name', 'trunk.name as trunk_name')
            ->cursor();
    }

    private function joinReceptionSource(Builder $query): void
    {
        $query->leftJoin('Ope_FilasFuente as reading', 'reading.id', '=', 'package.reading_id')
            ->leftJoin('Ope_Cargas as source_load', 'source_load.id', '=', 'reading.load_id')
            ->leftJoin('Ope_RecepcionesSistema as system_reception', 'system_reception.load_id', '=', 'source_load.id');
    }

    private function filterSource(Builder $query, string $source): void
    {
        if ($source === 'system') {
            $query->whereNotNull('system_reception.id');
        } elseif ($source === 'excel') {
            $query->whereNotNull('source_load.id')->whereNull('system_reception.id');
        }
    }

    private function receipts(int $tenant, CarbonImmutable $today, string $source): array
    {
        $query = DB::table('Ope_Cargas as load')
            ->leftJoin('Ope_RecepcionesSistema as system_reception', 'system_reception.load_id', '=', 'load.id')
            ->where('load.tenant_id', $tenant)->where('load.source_type', 'reception')->where('load.status', 'completed')
            ->where('load.created_at', '>=', $today->utc()->toDateTimeString())
            ->where('load.created_at', '<', $today->addDay()->utc()->toDateTimeString());
        if ($source === 'system') {
            $query->whereNotNull('system_reception.id');
        } elseif ($source === 'excel') {
            $query->whereNull('system_reception.id');
        }
        $loads = $query->orderByDesc('load.created_at')
            ->get(['load.id', 'load.filename', 'load.created_at', 'load.row_count', 'system_reception.id as system_reception_id']);
        $processed = $loads->isEmpty() ? collect() : DB::table('Ope_LoteFuentes')->whereIn('load_id', $loads->pluck('id'))->pluck('load_id')->flip();

        return [
            'count' => $loads->count(),
            'packages' => $loads->sum('row_count'),
            'items' => $loads->take(8)->map(fn (object $load): array => [
                'id' => $load->id,
                'filename' => $load->filename,
                'time' => CarbonImmutable::parse($load->created_at, 'UTC')->timezone('America/Santiago')->format('H:i'),
                'packages' => $load->row_count,
                'source' => $load->system_reception_id ? 'Sistema' : 'Excel',
                'url' => $load->system_reception_id ? route('operations.system-receptions.show', $load->system_reception_id) : route('operations.loads.show', $load->id),
                'processed' => $processed->has($load->id),
            ])->all(),
        ];
    }

    private function week(int $tenant, string $start, string $end, string $source, string $status): array
    {
        $days = [];
        for ($date = CarbonImmutable::parse($start); $date->toDateString() <= $end; $date = $date->addDay()) {
            $days[$date->toDateString()] = [];
        }
        if ($status !== 'reserve') {
            $query = DB::table('Ope_BultoTramos as leg')
                ->join('Ope_ProgramacionSalidas as departure', 'departure.id', '=', 'leg.departure_id')
                ->join('Ope_Lotes as lot', 'lot.id', '=', 'departure.lot_id')
                ->join('Ope_Bultos as package', 'package.id', '=', 'leg.package_id');
            $this->joinReceptionSource($query);
            $this->filterSource($query, $source);
            foreach ($query->where('lot.tenant_id', $tenant)->whereBetween('departure.departure_date', [$start, $end])
                ->where('departure.status', '<>', 'cancelled')
                ->when($status === 'approved', fn (Builder $query) => $query->where('departure.status', 'approved'))
                ->when($status === 'draft', fn (Builder $query) => $query->where('departure.status', 'draft'))
                ->select('departure.departure_date as date', 'package.tracking', 'package.weight')->distinct()->cursor() as $row) {
                $days[$row->date][$row->tracking] = (int) (float) $row->weight;
            }
        }
        if (in_array($status, ['all', 'reserve'], true)) {
            $query = DB::table('Ope_Reservas as reservation')
                ->join('Ope_Lotes as lot', 'lot.id', '=', 'reservation.source_lot_id')
                ->join('Ope_Bultos as package', 'package.id', '=', 'reservation.source_package_id');
            $this->joinReceptionSource($query);
            $this->filterSource($query, $source);
            foreach ($query->where('reservation.tenant_id', $tenant)->where('reservation.status', 'pending')
                ->whereBetween('lot.operation_date', [$start, $end])
                ->select('lot.operation_date as date', 'package.tracking', 'package.weight')->cursor() as $row) {
                $days[$row->date][$row->tracking] = (int) (float) $row->weight;
            }
        }

        $trend = [];
        foreach ($days as $date => $packages) {
            $trend[] = ['date' => $date, 'label' => CarbonImmutable::parse($date)->locale('es')->isoFormat('ddd D'),
                'count' => count($packages), 'weight' => array_sum($packages)];
        }

        return $trend;
    }

    private function add(array &$overall, array &$routeTree, array &$clientTree, string $tracking, float $weight, string $status, string $route, string $segment, string $agency, string $client): void
    {
        $this->record($overall, $tracking, $weight, $status);
        $this->addPath($routeTree, [$route, $segment, $agency], $tracking, $weight, $status);
        $this->addPath($clientTree, [$client, $route, $segment], $tracking, $weight, $status);
    }

    private function addPath(array &$nodes, array $path, string $tracking, float $weight, string $status): void
    {
        if ($path === []) {
            return;
        }
        $label = array_shift($path);
        $nodes[$label] ??= $this->emptyNode($label);
        $this->record($nodes[$label], $tracking, $weight, $status);
        $this->addPath($nodes[$label]['children'], $path, $tracking, $weight, $status);
    }

    private function record(array &$node, string $tracking, float $weight, string $status): void
    {
        $node['packages'][$tracking] = (int) $weight;
        $node['statuses'][$status][$tracking] = true;
    }

    private function emptyNode(string $label): array
    {
        return ['label' => $label, 'packages' => [], 'statuses' => [], 'children' => []];
    }

    private function metrics(array $node): array
    {
        return ['count' => count($node['packages']), 'weight' => array_sum($node['packages']),
            'reserved' => count($node['statuses']['reserve'] ?? []), 'approved' => count($node['statuses']['approved'] ?? []),
            'draft' => count($node['statuses']['draft'] ?? [])];
    }

    private function flatten(array $nodes, array &$rows, string $prefix, int $level = 0, ?string $parent = null): void
    {
        ksort($nodes, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($nodes as $node) {
            $id = $prefix.'-'.count($rows);
            $rows[] = ['id' => $id, 'parent' => $parent, 'level' => $level, 'label' => $node['label'],
                'hasChildren' => $node['children'] !== [], ...$this->metrics($node)];
            $this->flatten($node['children'], $rows, $prefix, $level + 1, $id);
        }
    }

    private function name(?string $value, string $fallback): string
    {
        return trim((string) $value) ?: $fallback;
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'posta1' => 'Posta 1', 'posta2' => 'Posta 2', 'posta3' => 'Posta 3',
            default => 'Posta',
        };
    }
}
