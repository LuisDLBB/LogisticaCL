@extends('operations::layout')
@section('title','Guía interna aprobada')
@section('content')
<h1>Guía interna #{{ $guide->id }} · versión {{ $guide->version }}</h1><p class="intro">{{ $preview['departure']['name'] }} · {{ $preview['departure']['departure_date'] }} · {{ $preview['departure']['role'] }}</p>
@if(!$current)<p class="warning">Versión histórica. La salida fue reabierta o tiene una versión posterior.</p>@endif
<p class="note">Documento interno de revisión operacional. La emisión y conexión con facturación se incorporarán en la etapa final.</p>
<div class="ope-actions"><a class="button" href="{{ route('operations.guides.export',$guide->id) }}">Descargar resumen CSV</a><button type="button" onclick="window.print()">Imprimir revisión</button><a href="{{ route('operations.departures.show',$guide->departure_id) }}">Volver a salida</a></div>
@include('operations::guide-content',['preview'=>$preview])
<p class="note">Aprobada por usuario #{{ $guide->approved_by }} · {{ $guide->created_at }}<br>Huella de esta versión: {{ $guide->sha256 }}</p>
@endsection
