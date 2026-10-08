@extends('operations::layout')
@section('title','Guías Bsale')
@section('content')
<h1>Historial de guías Bsale</h1>
<p class="intro">Las emisiones permanecen aquí aunque se limpie la guía interna o el proceso original.</p>
<div class="card table-wrap">
<table class="ope-table">
<thead><tr><th>Fecha</th><th>Guía interna</th><th>Versión</th><th>Hoja</th><th>Estado</th><th>Número Bsale</th><th>Documento</th></tr></thead>
<tbody>
@forelse($emissions as $emission)
<tr id="bsale-{{ $emission->id }}">
<td>{{ $emission->created_at }}</td>
<td>@if($emission->internal_guide_id)<a href="{{ route('operations.guides.show', $emission->internal_guide_id) }}">#{{ $emission->guide_id }}</a>@else #{{ $emission->guide_id }} · guía interna limpiada @endif</td>
<td>{{ $emission->version }}</td>
<td>Hoja {{ $emission->sheet_number }} de {{ $emission->sheet_count }}</td>
<td>{{ ucfirst($emission->estado) }}</td>
<td>{{ $emission->numero ?: '—' }}</td>
<td>
@if($emission->url_pdf && in_array(parse_url($emission->url_pdf, PHP_URL_SCHEME), ['http', 'https'], true))<a href="{{ $emission->url_pdf }}" target="_blank" rel="noopener noreferrer">PDF</a>@endif
@if($emission->url_publica && in_array(parse_url($emission->url_publica, PHP_URL_SCHEME), ['http', 'https'], true))<a href="{{ $emission->url_publica }}" target="_blank" rel="noopener noreferrer">Vista pública</a>@endif
</td>
</tr>
@if($linesByEmission->has($emission->id))
<tr><td colspan="7" style="text-align:left"><details><summary>Líneas {{ $emission->line_start }}–{{ $emission->line_end }} y códigos de bulto asociados @if($emission->numero)· GDE {{ $emission->numero }}@endif</summary>
<div class="table-wrap"><table class="ope-table"><thead><tr><th>Línea</th><th>Glosa</th><th>Bultos</th><th>Códigos</th></tr></thead><tbody>
@foreach($linesByEmission->get($emission->id) as $lineRecord)
@php($line = json_decode($lineRecord->line_snapshot, true))
<tr><td>{{ $lineRecord->line_number }}</td><td>{{ $line['description'] ?? '' }}</td><td>{{ $line['count'] ?? '' }}</td><td>{{ implode(', ', $line['packages'] ?? []) }}</td></tr>
@endforeach
</tbody></table></div>
</details></td></tr>
@endif
@if($emission->estado === 'incierta')
<tr><td colspan="7" style="text-align:left">
<p class="warning">Revisa en Bsale si la guía fue creada antes de reintentar.</p>
<details class="card"><summary>Conciliar emisión incierta #{{ $emission->id }}</summary>
<div class="ope-grid">
<form method="post" action="{{ route('operations.guides.bsale.reconcile', $emission->id) }}" class="ope-form">
@csrf<input type="hidden" name="outcome" value="created">
<h3 class="ope-full">Sí se creó en Bsale</h3>
<label>ID envío<input name="shipping_id" type="number" min="1" required></label>
<label>ID documento<input name="document_id" type="number" min="1" required></label>
<label>Número de guía<input name="numero" maxlength="100" required></label>
<label>URL del PDF<input name="url_pdf" type="url" required></label>
<label>URL de la vista pública<input name="url_publica" type="url" required></label>
<div class="ope-full"><button type="submit">Guardar datos de Bsale</button></div>
</form>
<form method="post" action="{{ route('operations.guides.bsale.reconcile', $emission->id) }}" class="ope-form">
@csrf<input type="hidden" name="outcome" value="not_created">
<h3 class="ope-full">No se creó en Bsale</h3>
<label class="check ope-full"><input type="checkbox" name="confirmed_absent" value="1" required> Confirmo que revisé Bsale y no existe esta guía.</label>
<div class="ope-full"><button type="submit">Habilitar nuevo intento</button></div>
</form>
</div>
</details>
</td></tr>
@endif
@empty
<tr><td colspan="7" class="ope-empty">Todavía no hay guías enviadas a Bsale.</td></tr>
@endforelse
</tbody>
</table>
</div>
@include('operations::pager',['rows'=>$emissions])
@endsection
