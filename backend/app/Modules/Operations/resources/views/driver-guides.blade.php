@extends('operations::driver-layout')
@section('title', 'Guías del recorrido')
@section('content')
<a href="{{ route('operations.driver.show', $record->id) }}" class="muted">← Volver al recorrido</a>
<span class="eyebrow" style="display:block;margin-top:16px">{{ $record->name }} · {{ $record->departure_date }}</span>
<h1>Guías del recorrido</h1>
<p class="muted">Todas las guías de las descargas asignadas a este vehículo. Puedes abrirlas durante el trayecto si te las solicitan.</p>
@foreach($stops as $stop)
@php($snapshot = json_decode($stop->guide_snapshot, true))
@php($sheets = $emissions->get($stop->guide_id, collect())->where('version', $stop->guide_version))
<article class="card route-card" id="guia-{{ $stop->sequence }}">
    <div class="row"><span class="eyebrow">Parada {{ $stop->sequence }}</span><span class="status">Guía interna #{{ $stop->guide_id }}</span></div>
    <h2>{{ $stop->name }}</h2>
    <p>{{ $stop->address }} · {{ $stop->commune }}</p>
    <p class="muted">{{ $snapshot['count'] ?? 0 }} bultos · {{ count($snapshot['lines'] ?? []) }} líneas de detalle</p>
    @forelse($sheets as $sheet)
    <div class="notice"><strong>GDE Bsale N.º {{ $sheet->numero }}</strong> · Hoja {{ $sheet->sheet_number }} de {{ $sheet->sheet_count }}<div class="evidence">
        @if($sheet->url_pdf && in_array(parse_url($sheet->url_pdf, PHP_URL_SCHEME), ['http','https'], true))<a class="button" href="{{ $sheet->url_pdf }}" target="_blank" rel="noopener noreferrer">Ver PDF</a>@endif
        @if($sheet->url_publica && in_array(parse_url($sheet->url_publica, PHP_URL_SCHEME), ['http','https'], true))<a class="button secondary" href="{{ $sheet->url_publica }}" target="_blank" rel="noopener noreferrer">Vista pública</a>@endif
    </div></div>
    @empty
    <div class="notice">Esta parada tiene guía interna aprobada, pero todavía no tiene una GDE Bsale generada.</div>
    @endforelse
    <details><summary>Ver detalle de la guía interna</summary><p class="muted">Origen: {{ $snapshot['origin']['address'] ?? '' }} · {{ $snapshot['origin']['commune'] ?? '' }}<br>Destino: {{ $snapshot['destination']['address'] ?? '' }} · {{ $snapshot['destination']['commune'] ?? '' }}<br>Patente: {{ $snapshot['departure']['plate'] ?? '' }} · Chofer: {{ $snapshot['departure']['driver_name'] ?? '' }}</p>
    @foreach($snapshot['lines'] ?? [] as $line)<p style="border-top:1px solid #e3eeee;padding-top:8px">{{ $line['description'] ?? '' }} <small>· {{ $line['count'] ?? 0 }} bultos</small></p>@endforeach</details>
</article>
@endforeach
@endsection
