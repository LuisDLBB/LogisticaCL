@extends('provider-payments::layout')
@section('title', 'Peso Real')
@push('styles')
<style>
.real-filters{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:12px;margin:20px 0}.real-filters label{display:grid;gap:6px;font-size:12px;font-weight:800;text-transform:uppercase}.real-filters input,.real-filters select{width:100%;padding:10px;border:1px solid var(--line);border-radius:7px;background:#fff}.filter-actions{display:flex;align-items:end;gap:8px}.real-sheet{max-height:65vh;overflow:auto;border:1px solid var(--line);background:#fff}.real-sheet table{min-width:1100px}.real-sheet th{position:sticky;top:0;z-index:2;white-space:nowrap}.real-sheet td{white-space:nowrap}.pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:16px 0}.pagination-links{display:flex;gap:8px}.pagination a,.pagination span{padding:8px 12px;border:1px solid var(--line);border-radius:7px;background:#fff;text-decoration:none}.pagination .disabled{color:var(--muted)}@media(max-width:900px){.real-filters{grid-template-columns:1fr}}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a><p class="eyebrow">Mantenedor de pesos</p><h1>Peso Real</h1><p class="intro">Consulta los registros de Peso_Real cargados para cada envío, comerciante y servicio.</p>
<form class="card real-filters" method="get">
<label>Año y mes<select name="period"><option value="">Todos</option>@foreach($periods as $option)<option value="{{ $option }}" @selected($option === $period)>{{ $option }}</option>@endforeach</select></label>
<label>Comerciante<select name="merchant"><option value="">Todos</option>@foreach($merchants as $option)<option value="{{ $option }}" @selected($option === $merchant)>{{ $option }}</option>@endforeach</select></label>
<label>Servicio<select name="service"><option value="">Todos</option>@foreach($services as $option)<option value="{{ $option }}" @selected($option === $service)>{{ $option }}</option>@endforeach</select></label>
<label>Buscar<input name="q" value="{{ $search }}" placeholder="Seguimiento, código, comerciante…"></label>
<div class="filter-actions"><button type="submit">Aplicar filtros</button><a class="button" href="{{ route('provider-payments.maintainers.pesos.reales') }}">Limpiar</a></div>
</form>
<p><strong>{{ number_format($rows->total(), 0, ',', '.') }}</strong> registros encontrados. Se muestran 100 por página.</p>
<div class="real-sheet"><table><thead><tr><th>Seguimiento paquete</th><th>Peso Real</th><th>Código de seguimiento</th><th>Fecha Proceso</th><th>Comerciante</th><th>Servicio</th></tr></thead><tbody>@forelse($rows as $row)<tr><td>{{ $row->seguimiento_paquete }}</td><td>{{ number_format($row->peso_real, 0, ',', '.') }}</td><td>{{ $row->codigo_seguimiento }}</td><td>{{ $row->fecha_proceso?->format('d-m-Y') }}</td><td>{{ $row->comerciante }}</td><td>{{ $row->servicio }}</td></tr>@empty<tr><td colspan="6">No hay registros para los filtros seleccionados.</td></tr>@endforelse</tbody></table></div>
@if($rows->hasPages())<nav class="pagination"><span>Página {{ $rows->currentPage() }} de {{ $rows->lastPage() }}</span><div class="pagination-links">@if($rows->onFirstPage())<span class="disabled">Anterior</span>@else<a href="{{ $rows->previousPageUrl() }}">Anterior</a>@endif @if($rows->hasMorePages())<a href="{{ $rows->nextPageUrl() }}">Siguiente</a>@else<span class="disabled">Siguiente</span>@endif</div></nav>@endif
@endsection
