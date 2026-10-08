@php($agency = $route['agency'])
<details class="route-agency {{ $isAirBranch ? 'route-air-branch' : '' }}" id="agency-{{ $agency->id }}" @if($openByDefault) open @endif>
    <summary><strong>{{ $agency->name }}</strong><span>{{ collect($route['segments'])->filter(fn($segment) => in_array($segment['key'], ['troncal', 'posta1', 'posta2', 'posta3'], true))->pluck('name')->join(' → ') }}</span></summary>
    <div class="route-detail">
        <div class="route-path">
        @foreach($route['segments'] as $segment)
            @continue(str_starts_with($segment['key'],'retorno_') || ($isAirBranch && $segment['key'] === 'troncal'))
            <div class="route-stage {{ $segment['air'] ? 'air' : '' }}"><div class="route-stage-icon" aria-hidden="true">
                @if($segment['air'])
                    ✈
                @else
                    @include('operations::route-truck-icon')
                @endif
            </div><div class="route-stage-card">
                <div class="route-stage-head"><strong>{{ $segment['role'] }} · {{ $segment['name'] }}</strong><small>{{ $segment['air'] ? 'Traslado aéreo' : ($segment['plate'] ?: 'Patente pendiente') }}</small></div>
                <div class="route-locations"><span><small>Origen</small><br>{{ $segment['origin'] }}</span><b aria-hidden="true">→</b><span><small>Destino</small><br>{{ $segment['destination'] }}</span></div>
                @if(!$segment['air'])
                    @php($driverName = $segment['driver_name'] ?? null)
                    @php($driverRut = $segment['driver_rut'] ?? null)
                    <p class="route-data">Chofer: {{ $driverName ?: 'Pendiente' }} · RUT: {{ $driverRut ?: 'Pendiente' }}</p>
                @endif
                @include('operations::route-estimate', ['segment' => $segment, 'agency' => $agency, 'canEdit' => $canEdit, 'mapsConfigured' => $mapsConfigured])
            </div></div>
        @endforeach
        </div>
        <h3>Retorno estimado al origen de la troncal</h3>
        <div class="route-return-grid">
        @foreach($route['segments'] as $segment)
            @continue(!str_starts_with($segment['key'],'retorno_'))
            <div class="route-return"><h3>↩ {{ $segment['name'] }}</h3><p>{{ $segment['origin'] }} → {{ $segment['destination'] }}</p>
            @if($segment['air'])<p>Vía aeropuerto de la posta y aeropuerto de la troncal.</p>@endif
            @include('operations::route-estimate', ['segment' => $segment, 'agency' => $agency, 'canEdit' => $canEdit, 'mapsConfigured' => $mapsConfigured])
            </div>
        @endforeach
        </div>
    </div>
</details>
