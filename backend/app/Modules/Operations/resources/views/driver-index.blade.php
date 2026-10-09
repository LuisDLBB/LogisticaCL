@extends('operations::driver-layout')
@section('title', 'Mis rutas')
@section('content')
<span class="eyebrow">Jornada del chofer</span><h1>Mis rutas</h1>
<p class="muted">{{ $driver->name }} · {{ $driver->rut }}</p>
<form method="GET" action="{{ route('operations.driver.index') }}" class="card">
    <label class="field">Fecha de salida<input type="date" name="date" value="{{ $date }}"></label>
    <button class="full">Ver fecha</button>
</form>
@if($incoming->isNotEmpty())
<section class="card"><h2>Devoluciones para recibir</h2>
@foreach($incoming as $transfer)
<div class="notice"><strong>{{ $transfer->package_count }} bultos</strong> desde {{ $transfer->source_name }}@if($transfer->stop_name) · {{ $transfer->stop_name }}@endif<br><small>{{ $transfer->departure_date }} · {{ $transfer->observation }}</small>
<form method="POST" action="{{ route('operations.driver.transfers.receive', $transfer->id) }}" data-offline>@csrf
<label class="field">Recibir en mi ruta<select name="journey_id" required><option value="">Seleccionar recorrido iniciado</option>@foreach($journeys->where('status', 'in_progress') as $journey)<option value="{{ $journey->id }}">{{ $journey->name }} · {{ $journey->plate }}</option>@endforeach</select></label>
<button @disabled($journeys->where('status', 'in_progress')->isEmpty())>Confirmar recepción</button></form></div>
@endforeach</section>
@endif
<h2>Recorridos tomados</h2>
@forelse($journeys as $journey)
<article class="card route-card"><div class="row"><span class="eyebrow">{{ $journey->transport_kind === 'trunk' ? 'Troncal' : 'Posta' }}</span><span class="status">{{ ['assigned'=>'Por iniciar','in_progress'=>'En ruta','completed'=>'Terminada'][$journey->status] ?? $journey->status }}</span></div><h3>{{ $journey->name }}</h3><p class="muted">{{ $journey->plate }} · {{ $journey->departure_date }}</p><a class="button full" href="{{ route('operations.driver.show', $journey->id) }}">Abrir recorrido</a></article>
@empty<p class="muted">Aún no has tomado rutas para esta fecha.</p>@endforelse
<h2>Rutas aprobadas para tomar</h2>
@forelse($assigned as $group)
<article class="card route-card"><span class="eyebrow">{{ $group['transport_kind'] === 'trunk' ? 'Troncal' : 'Posta' }}</span><h3>{{ $group['name'] }}</h3><p class="muted">{{ $group['plate'] }} · {{ count($group['stops']) }} {{ count($group['stops']) === 1 ? 'parada' : 'paradas' }}</p><div class="evidence">@foreach($group['stops'] as $stop)<span class="status">{{ $stop['name'] }}</span>@endforeach</div><form method="POST" action="{{ route('operations.driver.claim') }}">@csrf<input type="hidden" name="date" value="{{ $date }}"><input type="hidden" name="key" value="{{ $group['key'] }}"><button class="full">Tomar ruta</button></form></article>
@empty<p class="muted">No hay rutas aprobadas nuevas para esta fecha. Si esperabas una, confirma que la guía esté aprobada y el RUT del chofer coincida.</p>@endforelse
@endsection
