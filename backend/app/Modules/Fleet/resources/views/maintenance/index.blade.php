@extends('fleet::layout')
@section('title', 'Mantenciones')
@push('styles')
<style>
    .maintenance-alert{display:inline-block;border-radius:99px;padding:4px 9px;font-size:12px;font-weight:700;background:#eef3f3;color:#49666a}.maintenance-alert.upcoming{background:#fff0b8;color:#674700}.maintenance-alert.overdue{background:#fbd5d1;color:#9b211a}.maintenance-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.maintenance-filter{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:end}.maintenance-filter label{margin:0}.maintenance-filter button{align-self:end}
</style>
@endpush
@section('content')
<div class="maintenance-actions"><h1>Mantenciones</h1><a class="button" href="{{ route('fleet.page.operations-fleet') }}">Elegir vehículo en Flota</a></div>
<p class="muted">Historial de mantenciones por vehículo. Cada registro conserva su empresa propietaria y los eventos de cambio.</p>
<form class="card maintenance-filter" method="get" aria-label="Filtros de mantenciones">
    @if(count($companyCodes) > 1)<label>Empresa<select name="empresa"><option value="">4N + PMCB</option>@foreach($companyCodes as $code)<option value="{{ $code }}" @selected(($filters['empresa'] ?? '') === $code)>{{ $code }}</option>@endforeach</select></label>@endif
    <label>Estado<select name="estado"><option value="">Todos</option>@foreach(\App\Fleet\MaintenanceService::STATUSES as $code => $name)<option value="{{ $code }}" @selected(($filters['estado'] ?? '') === $code)>{{ $name }}</option>@endforeach</select></label>
    <label>Patente<input name="patente" value="{{ $filters['patente'] ?? '' }}" placeholder="Buscar patente"></label>
    <button type="submit">Filtrar</button>
</form>
<section class="card"><h2>Historial ({{ $rows->count() }})</h2><div class="table-wrap"><table>
    <thead><tr><th>Vehículo</th><th>Propietaria</th><th>Tipo / ejecución</th><th>Fecha</th><th>Estado</th><th>Proveedor</th><th>Costo real</th><th>Alerta</th><th></th></tr></thead>
    <tbody>@forelse($rows as $maintenance)
        @php($setting = $settings->get($maintenance->tenant_id))
        @php($alert = ($maintenance->status !== 'cancelled' && ($maintenance->status !== 'closed' || in_array($maintenance->id, $latestClosedIds, true))) ? $service->alert($maintenance, $maintenance->vehicle->odometer_km, $setting?->warning_days ?? 30, $setting?->warning_km ?? 1000) : null)
        <tr><td><strong>{{ $maintenance->vehicle->plate }}</strong></td><td>{{ $maintenance->tenant->code }}</td><td>{{ $maintenance->maintenance_type }}<br><span class="muted">{{ $maintenance->execution_type === 'internal' ? 'Interna' : 'Externa' }}</span></td><td>{{ $maintenance->scheduled_at?->format('d/m/Y H:i') ?? 'Por agendar' }}</td><td>{{ \App\Fleet\MaintenanceService::STATUSES[$maintenance->status] }}</td><td>{{ $maintenance->provider?->operational_name ?: ($maintenance->provider?->legal_name ?? '—') }}</td><td>{{ $maintenance->actual_cost !== null ? '$'.number_format($maintenance->actual_cost, 0, ',', '.') : '—' }}</td><td>@if($alert)<span class="maintenance-alert {{ $alert['level'] }}">{{ $alert['label'] }}</span>@if($alert['missing_reading'])<br><small>Sin lectura de km</small>@endif@else<span class="muted">Histórica</span>@endif</td><td><a href="{{ route('fleet.maintenance.show', $maintenance) }}">Ver detalle</a></td></tr>
    @empty<tr><td colspan="9">Todavía no hay mantenciones registradas. Elige un vehículo en Flota para crear la primera.</td></tr>@endforelse</tbody>
</table></div></section>
@endsection
