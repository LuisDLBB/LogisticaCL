@extends('operations::layout')
@section('title','Guía interna aprobada')
@section('content')
<h1>Guía interna #{{ $guide->id }} · versión {{ $guide->version }}</h1><p class="intro">{{ $preview['departure']['name'] }} · {{ $preview['departure']['departure_date'] }} · {{ $preview['departure']['role'] }}</p>
@if(!$current)<p class="warning">Versión histórica. La salida fue reabierta o tiene una versión posterior.</p>@endif
<p class="note">Documento interno de revisión operacional.</p>
@if($canEmitBsale)<p><a href="{{ route('operations.guides.bsale.index') }}">Ver historial de guías Bsale</a></p>@endif
@php($generatedSheets = $bsaleEmissions->where('estado', 'generada')->count())
<p class="note">{{ $generatedSheets }} de {{ $bsaleSheetCount }} {{ $bsaleSheetCount === 1 ? 'hoja generada' : 'hojas generadas' }} en Bsale. Cada GDE incluye hasta 15 líneas de detalle.</p>
@if($bsaleEmissions->isNotEmpty())
<div class="card table-wrap"><table class="ope-table"><thead><tr><th>Hoja</th><th>Líneas</th><th>Estado</th><th>Número Bsale</th><th>Documentos</th></tr></thead><tbody>
@foreach($bsaleEmissions as $emission)
<tr><td>Hoja {{ $emission->sheet_number }} de {{ $emission->sheet_count }}</td><td>{{ $emission->line_start }}–{{ $emission->line_end }}</td><td>{{ ucfirst($emission->estado) }}</td><td>{{ $emission->numero ?: '—' }}</td><td>
@if($emission->url_pdf && in_array(parse_url($emission->url_pdf, PHP_URL_SCHEME), ['http', 'https'], true))<a class="button" href="{{ $emission->url_pdf }}" target="_blank" rel="noopener noreferrer">Ver PDF de Bsale</a>@endif
@if($emission->estado === 'generada' && \App\Modules\Operations\Services\OperationBsaleGuideService::downloadablePdfUrl($emission->url_pdf))<a class="button" href="{{ route('operations.guides.bsale.pdf', $emission->id) }}">Descargar PDF</a>@endif
@if($emission->url_publica && in_array(parse_url($emission->url_publica, PHP_URL_SCHEME), ['http', 'https'], true))<a href="{{ $emission->url_publica }}" target="_blank" rel="noopener noreferrer">Vista pública</a>@endif
</td></tr>
@endforeach
</tbody></table></div>
@foreach($bsaleEmissions as $emission)
@if($bsaleLinesByEmission->has($emission->id))
<details class="card"><summary>Hoja {{ $emission->sheet_number }} de {{ $emission->sheet_count }} · líneas y bultos asociados @if($emission->numero)· GDE {{ $emission->numero }}@endif</summary>
<div class="table-wrap"><table class="ope-table"><thead><tr><th>Línea</th><th>Glosa</th><th>Bultos</th><th>Códigos de bulto</th></tr></thead><tbody>
@foreach($bsaleLinesByEmission->get($emission->id) as $lineRecord)
@php($line = json_decode($lineRecord->line_snapshot, true))
<tr><td>{{ $lineRecord->line_number }}</td><td>{{ $line['description'] ?? '' }}</td><td>{{ $line['count'] ?? '' }}</td><td>{{ implode(', ', $line['packages'] ?? []) }}</td></tr>
@endforeach
</tbody></table></div></details>
@endif
@endforeach
@endif
@foreach($bsaleEmissions->where('estado', 'incierta') as $emission)
<p class="warning">Hoja {{ $emission->sheet_number }} de {{ $emission->sheet_count }}: {{ \App\Modules\Operations\Services\OperationBsaleGuideService::UNCERTAIN_MESSAGE }}</p>
@if($canEmitBsale)<p><a href="{{ route('operations.guides.bsale.index') }}#bsale-{{ $emission->id }}">Conciliar esta hoja</a></p>@endif
@endforeach
@if($bsaleEmissions->contains('estado', 'enviando'))<p class="note">Hay una hoja en envío. No se enviarán más hasta conocer su resultado.</p>@endif
@if($current && $canEmitBsale && $generatedSheets < $bsaleSheetCount && !$bsaleEmissions->contains('estado', 'incierta') && !$bsaleEmissions->contains('estado', 'enviando'))
<form method="post" action="{{ route('operations.guides.bsale.store', $guide->id) }}">@csrf<button type="submit">{{ $generatedSheets ? 'Generar hojas restantes en Bsale' : 'Generar en Bsale' }}</button></form>
@endif
<div class="ope-actions"><a class="button" href="{{ route('operations.guides.export',$guide->id) }}">Descargar resumen CSV</a><button type="button" onclick="window.print()">Imprimir revisión</button><a href="{{ route('operations.departures.show',$guide->departure_id) }}">Volver a salida</a></div>
@include('operations::guide-content',['preview'=>$preview])
<p class="note">Aprobada por usuario #{{ $guide->approved_by }} · {{ $guide->created_at }}<br>Huella de esta versión: {{ $guide->sha256 }}</p>
@endsection
