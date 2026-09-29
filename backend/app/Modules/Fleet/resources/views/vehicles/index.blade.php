@extends('fleet::layout')
@section('title', 'Flota')
@push('styles')
<style>
    .fleet-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:24px 0}.fleet-summary .card{margin:0}.fleet-summary strong{display:block;font-size:28px}.fleet-filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;align-items:end}.fleet-filters label{margin:0}.fleet-filters input,.fleet-filters select{display:block;width:100%;margin-top:5px}.fleet-filters .check{display:flex;align-items:center;gap:8px}.fleet-filters .check input{width:auto;margin:0}.fleet-filters button{width:100%}.fleet-sub{font-size:13px;color:#60757a}.fleet-pill{display:inline-block;border-radius:99px;padding:5px 9px;font-size:12px;font-weight:700;background:#e0f6ed;color:#145940}.fleet-pill.off{background:#fce9e4;color:#883a2d}.fleet-pill.warn{background:#fff2d7;color:#765312}.fleet-table td{vertical-align:middle}.fleet-table small{display:block;color:#60757a}
</style>
@endpush
@section('content')
<a href="{{ route('fleet.home') }}">← Control de Flota</a>
<h1>Flota</h1>
<p class="muted">Empresa del servicio: <strong>{{ $serviceTenant->name }}</strong>. La empresa propietaria se muestra por vehículo y no limita su uso operacional.</p>
@if($sharedPool)
    <p class="notice">Pool operacional compartido 4N + PMCB habilitado para tu perfil. Programación y asignaciones estarán disponibles en una etapa posterior.</p>
@endif

<div class="fleet-summary">
    <div class="card"><span class="fleet-sub">Vehículos visibles</span><strong>{{ $totalCount }}</strong></div>
    <div class="card"><span class="fleet-sub">Candidatos operacionales</span><strong>{{ $operationalCount }}</strong></div>
    <div class="card"><span class="fleet-sub">Fuera del pool</span><strong>{{ $totalCount - $operationalCount }}</strong></div>
</div>

<section class="card" aria-label="Filtros de flota">
    <form class="fleet-filters" method="get" action="{{ route('fleet.page.operations-fleet') }}">
        <label>Empresa propietaria
            <select name="empresa">
                @if($sharedPool)<option value="all" @selected($company === 'all')>4N + PMCB</option>@endif
                @foreach($companyCodes as $code)<option value="{{ $code }}" @selected($company === $code)>{{ $code }}</option>@endforeach
            </select>
        </label>
        <label>Estado
            <select name="estado"><option value="">Todos</option>@foreach($statuses as $status)<option value="{{ $status }}" @selected(($filters['estado'] ?? '') === $status)>{{ $status }}</option>@endforeach</select>
        </label>
        <label>Tipo
            <select name="tipo"><option value="">Todos</option>@foreach($types as $type)<option value="{{ $type }}" @selected(($filters['tipo'] ?? '') === $type)>{{ $type }}</option>@endforeach</select>
        </label>
        <label>Patente o código
            <input name="patente" value="{{ $filters['patente'] ?? '' }}" placeholder="Buscar vehículo">
        </label>
        <label class="check"><input type="checkbox" name="pool" value="1" @checked(($filters['pool'] ?? '') === '1')> Solo pool operacional</label>
        <button type="submit">Aplicar filtros</button>
    </form>
</section>

<section class="card">
    <h2>Vehículos <span class="fleet-sub">({{ $rows->count() }} resultados)</span></h2>
    <p class="fleet-sub">El pool excluye vehículos en mantención, vendidos, robados o sin propiedad verificable. Las mantenciones pendientes y agendadas advierten, sin bloquear automáticamente; aún no se verifican reservas ni coincidencias horarias de Programación.</p>
    <div class="table-wrap">
        <table class="fleet-table">
            <thead><tr><th>Patente</th><th>Vehículo</th><th>Propietaria</th><th>Estado</th><th>Pool operacional</th><th>Ficha</th></tr></thead>
            <tbody>
            @forelse($rows as $row)
                @php($vehicle = $row['vehicle'])
                <tr>
                    <td><strong>{{ $vehicle->plate }}</strong><small>{{ $vehicle->internal_code }}</small></td>
                    <td>{{ $vehicle->vehicle_type }}<small>{{ trim($vehicle->brand.' '.$vehicle->model) }}</small></td>
                    <td>{{ $row['owner']?->code ?? 'Por verificar' }}</td>
                    <td><span class="fleet-pill {{ $row['operational'] ? '' : 'off' }}">{{ $vehicle->operational_status }}</span>@if($row['in_maintenance'])<small><span class="fleet-pill off">En mantención</span></small>@elseif($row['planned_maintenance'])<small><span class="fleet-pill warn">Mantención pendiente/agendada</span></small>@endif</td>
                    <td><span class="fleet-pill {{ $row['operational'] ? '' : 'off' }}">{{ $row['operational'] ? 'Candidato' : 'Excluido' }}</span></td>
                    <td><a href="{{ route('fleet.vehicles.show', $vehicle) }}">Ver ficha</a></td>
                </tr>
            @empty
                <tr><td colspan="6">No hay vehículos para estos filtros.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
