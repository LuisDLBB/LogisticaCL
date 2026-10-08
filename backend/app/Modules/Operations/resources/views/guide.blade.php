@extends('operations::layout')
@section('title','Guía interna aprobada')
@section('content')
<h1>Guía interna #{{ $guide->id }} · versión {{ $guide->version }}</h1><p class="intro">{{ $preview['departure']['name'] }} · {{ $preview['departure']['departure_date'] }} · {{ $preview['departure']['role'] }}</p>
@if(!$current)<p class="warning">Versión histórica. La salida fue reabierta o tiene una versión posterior.</p>@endif
<p class="note">Documento interno de revisión operacional.</p>
@if($canEmitBsale)<p><a href="{{ route('operations.guides.bsale.index') }}">Ver historial de guías Bsale</a></p>@endif
@if($bsaleEmission?->estado === 'generada')
<p class="success">GDE emitida en Bsale · número {{ $bsaleEmission->numero }}</p>
<div class="ope-actions">
@if($bsaleEmission->url_pdf && in_array(parse_url($bsaleEmission->url_pdf, PHP_URL_SCHEME), ['http', 'https'], true))<a href="{{ $bsaleEmission->url_pdf }}" target="_blank" rel="noopener noreferrer">Ver PDF de Bsale</a>@endif
@if($bsaleEmission->url_publica && in_array(parse_url($bsaleEmission->url_publica, PHP_URL_SCHEME), ['http', 'https'], true))<a href="{{ $bsaleEmission->url_publica }}" target="_blank" rel="noopener noreferrer">Ver guía pública en Bsale</a>@endif
</div>
@elseif($bsaleEmission?->estado === 'incierta')
<p class="warning">{{ \App\Modules\Operations\Services\OperationBsaleGuideService::UNCERTAIN_MESSAGE }}</p>
@if($canEmitBsale)<p><a href="{{ route('operations.guides.bsale.index') }}#bsale-{{ $bsaleEmission->id }}">Conciliar esta emisión</a></p>@endif
@elseif($bsaleEmission?->estado === 'enviando')
<p class="note">El envío a Bsale está en curso. No se realizará otro envío de esta guía y versión.</p>
@endif
@if($current && $canEmitBsale && (!$bsaleEmission || $bsaleEmission->estado === 'error'))
<form method="post" action="{{ route('operations.guides.bsale.store', $guide->id) }}">@csrf<button type="submit">Generar en Bsale</button></form>
@endif
<div class="ope-actions"><a class="button" href="{{ route('operations.guides.export',$guide->id) }}">Descargar resumen CSV</a><button type="button" onclick="window.print()">Imprimir revisión</button><a href="{{ route('operations.departures.show',$guide->departure_id) }}">Volver a salida</a></div>
@include('operations::guide-content',['preview'=>$preview])
<p class="note">Aprobada por usuario #{{ $guide->approved_by }} · {{ $guide->created_at }}<br>Huella de esta versión: {{ $guide->sha256 }}</p>
@endsection
