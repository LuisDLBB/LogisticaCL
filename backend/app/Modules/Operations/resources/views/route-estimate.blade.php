@php
    $estimate = $segment['estimate'];
    $mapsUrl = $estimate?->maps_url ?: 'https://www.google.com/maps/dir/?'.http_build_query([
        'api' => '1',
        'origin' => $segment['origin'],
        'destination' => $segment['destination'],
        'travelmode' => 'driving',
    ], '', '&', PHP_QUERY_RFC3986);
@endphp
<div class="route-metric {{ $estimate ? '' : 'missing' }}">
    @if($estimate)
        <strong>{{ number_format((float) $estimate->distance_km, 1, ',', '.') }} km</strong>
        <strong>{{ $estimate->duration_minutes === null ? 'Tiempo pendiente' : intdiv((int) $estimate->duration_minutes, 60).' h '.((int) $estimate->duration_minutes % 60).' min' }}</strong>
        <small>{{ $estimate->source === 'manual' ? 'Ingresado manualmente' : ($estimate->source === 'estimacion_aerea' ? 'Estimación con vuelo' : 'Calculado con mapas') }}</small>
    @else
        <strong>Km y tiempo pendientes</strong>
    @endif
</div>
@if(! $segment['air'])
    <a class="route-map-link" href="{{ $mapsUrl }}" target="_blank" rel="noopener noreferrer">Abrir recorrido en Google Maps ↗</a>
@endif
@if($canEdit)
    <details class="route-estimate-edit"><summary>{{ $estimate ? 'Corregir datos del recorrido' : 'Agregar datos del recorrido' }}</summary>
        @if($mapsConfigured)<form method="POST" action="{{ route('operations.routes.calculate', $agency->id) }}">@csrf<input type="hidden" name="segment" value="{{ $segment['key'] }}"><button class="route-map-button" type="submit">{{ $estimate ? 'Recalcular y reemplazar datos' : 'Calcular con mapas' }}</button></form>@endif
        <form method="POST" action="{{ route('operations.routes.estimates.save', $agency->id) }}">
            @csrf @method('PUT')
            <input type="hidden" name="segment" value="{{ $segment['key'] }}">
            <div class="route-manual">
                <label>Kilómetros<input type="number" name="distance_km" step="0.1" min="0" max="9999999" value="{{ $estimate?->distance_km }}" required></label>
                <label>Tiempo en minutos <small>(opcional)</small><input type="number" name="duration_minutes" step="1" min="0" max="999999" value="{{ $estimate?->duration_minutes }}"></label>
                @if(! $segment['air'])
                    <label>Enlace de Google Maps <small>(opcional)</small><input class="route-map-input" type="url" name="maps_url" maxlength="4000" placeholder="Pega el enlace del recorrido" value="{{ $estimate?->maps_url }}"></label>
                @endif
                <button type="submit">Guardar recorrido</button>
            </div>
        </form>
    </details>
@endif
