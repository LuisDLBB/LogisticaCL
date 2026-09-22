@extends('provider-payments::layout')
@section('title', 'Consulta de movimientos')
@push('styles')
<style>
.sheet-filters{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:12px;margin:20px 0}.sheet-filters label{display:grid;gap:6px;font-size:12px;font-weight:800;text-transform:uppercase}.sheet-filters input,.sheet-filters select{width:100%;padding:10px;border:1px solid var(--line);border-radius:7px;background:#fff}.sheet-actions{display:flex;align-items:end;gap:8px}.sheet-wrap{max-height:65vh;overflow:auto;border:1px solid var(--line);background:#fff}.sheet{min-width:1900px;font-size:13px}.sheet th{position:sticky;top:0;z-index:2;white-space:nowrap}.sheet td{max-width:300px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.sheet td:last-child,.sheet th:last-child{text-align:left}.pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:16px 0}.pagination-links{display:flex;gap:8px}.pagination a,.pagination span{padding:8px 12px;border:1px solid var(--line);border-radius:7px;background:#fff;text-decoration:none}.pagination .disabled{color:var(--muted)}@media(max-width:900px){.sheet-filters{grid-template-columns:1fr}}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard', ['period' => $period]) }}">← Volver al resumen</a>
<p class="eyebrow">Consulta de movimientos</p><h1>Planilla de movimientos Courier</h1><p class="intro">Vista de solo consulta. Los nombres y direcciones se muestran descifrados por la aplicación.</p>
<form class="card sheet-filters" method="get">
<label>Año y mes<select name="period">@foreach($periods as $option)<option value="{{ $option }}" @selected($option === $period)>{{ substr($option,0,4) }}-{{ substr($option,4,2) }}</option>@endforeach</select></label>
<label>Proceso<select name="process"><option value="">Todos</option>@foreach($processOptions as $option)<option value="{{ $option }}" @selected($option === $process)>{{ $option }}</option>@endforeach</select></label>
<label>Estado<select name="status"><option value="">Todos</option>@foreach($statusOptions as $option)<option value="{{ $option }}" @selected($option === $status)>{{ $option }}</option>@endforeach</select></label>
<label>Cliente<select name="merchant"><option value="">Todos</option>@foreach($merchantOptions as $option)<option value="{{ $option }}" @selected($option === $merchant)>{{ $option }}</option>@endforeach</select></label>
<label>Comuna<select name="commune"><option value="">Todas</option>@foreach($communeOptions as $option)<option value="{{ $option }}" @selected($option === $commune)>{{ $option }}</option>@endforeach</select></label>
<label>Buscar<input name="q" value="{{ $search }}" placeholder="Seguimiento, cliente, servicio…"></label>
<div class="sheet-actions"><button type="submit">Aplicar filtros</button><a class="button" href="{{ route('provider-payments.movements.index', ['period' => $period]) }}">Limpiar</a></div>
</form>
<p><strong>{{ number_format($movements->total(),0,',','.') }}</strong> registros encontrados. Se muestran 100 por página.</p>
<div class="sheet-wrap"><table class="sheet"><thead><tr><th>Fecha</th><th>Seguimiento</th><th>Cliente</th><th>Servicio</th><th>Estado</th><th>Destinatario</th><th>Dirección</th><th>Comuna destino</th><th>Peso</th><th>Peso transformado</th><th>Tipo pago</th><th>Proceso</th></tr></thead><tbody>
@forelse($movements as $movement)<tr><td>{{ $movement->fecha?->format('d-m-Y') }}</td><td>{{ $movement->tracking_number }}</td><td>{{ $movement->merchant_name }}</td><td>{{ $movement->service_name }}</td><td>{{ $movement->status }}</td><td title="{{ $movement->recipient_name }}">{{ $movement->recipient_name }}</td><td title="{{ $movement->recipient_address }}">{{ $movement->recipient_address }}</td><td>{{ $movement->destination_commune_name }}</td><td>{{ $movement->weight_kg }}</td><td>{{ $movement->peso_transformado }}</td><td>{{ $movement->tipo_pago }}</td><td>{{ $movement->nombre_proceso }}</td></tr>
@empty<tr><td colspan="12">No hay movimientos para los filtros seleccionados.</td></tr>@endforelse
</tbody></table></div>
@if($movements->hasPages())<nav class="pagination"><span>Página {{ $movements->currentPage() }} de {{ $movements->lastPage() }}</span><div class="pagination-links">@if($movements->onFirstPage())<span class="disabled">Anterior</span>@else<a href="{{ $movements->previousPageUrl() }}">Anterior</a>@endif @if($movements->hasMorePages())<a href="{{ $movements->nextPageUrl() }}">Siguiente</a>@else<span class="disabled">Siguiente</span>@endif</div></nav>@endif
@endsection
