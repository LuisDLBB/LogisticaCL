@php($current = $trend['current'])
@php($previous = $trend['previous'])
@php($delta = $trend['delta'])
@php($review = $delta !== null && ($delta < 0 || ! $current->accepted || ! $previous->accepted))
<h3 id="km-trend-title">{{ $vehicle->plate }} · Comparación semanal</h3>
<p class="km-trend-sub">Lecturas informadas de los últimos 12 lunes</p>
<div class="km-trend-metrics"><div><span>Lunes {{ $week->format('d/m/Y') }}</span><b>{{ $current !== null ? number_format($current->odometer_km, 0, ',', '.').' km' : 'Sin lectura' }}</b></div><div><span>Lunes {{ $week->copy()->subWeek()->format('d/m/Y') }}</span><b>{{ $previous !== null ? number_format($previous->odometer_km, 0, ',', '.').' km' : 'Sin lectura' }}</b></div></div>
@if($delta === null)<div class="km-trend-delta review">Sin datos para comparar estos dos lunes.</div>
@elseif($review)<div class="km-trend-delta review">Variación informada: {{ $delta > 0 ? '+' : '' }}{{ number_format($delta, 0, ',', '.') }} km</div><p>{{ $delta < 0 ? 'Kilometraje menor al anterior: revisar.' : 'Hay una lectura pendiente de verificar.' }} Esta variación no se considera distancia recorrida.</p>
@elseif($delta === 0)<div class="km-trend-delta">Sin variación: 0 km esta semana.</div>
@else<div class="km-trend-delta">Recorrió {{ number_format($delta, 0, ',', '.') }} km esta semana.</div>@endif
@php($points = $trend['series']->filter(fn ($point) => $point['km'] !== null))
@if($points->isEmpty())<p class="km-trend-empty">Aún no hay lecturas semanales para mostrar el gráfico.</p>
@else
@php($minimum = $points->min('km'))
@php($maximum = $points->max('km'))
@php($range = max(1, $maximum - $minimum))
<svg class="km-trend-chart" viewBox="0 0 360 175" role="img" aria-label="Gráfico de kilómetros informados por semana de {{ $vehicle->plate }}. Las lecturas pendientes de verificar se muestran en naranja.">
<path d="M30 26H334M30 80H334M30 134H334" stroke="#e2ecec" stroke-width="1"/>
<text x="30" y="16" fill="#617779" font-size="10">{{ number_format($maximum, 0, ',', '.') }} km</text><text x="30" y="148" fill="#617779" font-size="10">{{ number_format($minimum, 0, ',', '.') }} km</text>
@foreach($trend['series'] as $index => $point)
@if($point['km'] !== null)
@php($x = 30 + $index * 27.5)
@php($y = $maximum === $minimum ? 80 : 134 - (($point['km'] - $minimum) / $range) * 108)
@php($prior = $index > 0 ? $trend['series'][$index - 1] : null)
@if($prior !== null && $prior['km'] !== null)
@php($priorY = $maximum === $minimum ? 80 : 134 - (($prior['km'] - $minimum) / $range) * 108)
<line x1="{{ $x - 27.5 }}" y1="{{ $priorY }}" x2="{{ $x }}" y2="{{ $y }}" stroke="{{ $point['accepted'] && $prior['accepted'] ? '#0c987e' : '#ce6d27' }}" stroke-width="2.5" @if(! $point['accepted'] || ! $prior['accepted']) stroke-dasharray="4 3" @endif/>
@endif
<circle cx="{{ $x }}" cy="{{ $y }}" r="4" fill="{{ $point['accepted'] ? '#0c987e' : '#ce6d27' }}"><title>{{ $point['date'] }}: {{ number_format($point['km'], 0, ',', '.') }} km{{ $point['accepted'] ? '' : ' · Pendiente de verificar' }}</title></circle>
@endif
@endforeach
<text x="30" y="168" fill="#617779" font-size="10">{{ $trend['series']->first()['label'] }}</text><text x="334" y="168" text-anchor="end" fill="#617779" font-size="10">{{ $trend['series']->last()['label'] }}</text>
</svg>
<div class="km-trend-legend"><span><i></i>Lectura válida</span><span class="review"><i></i>Revisar</span>Los períodos sin lectura quedan sin línea.</div>
@endif
<p><a href="{{ route('fleet.vehicles.show', $vehicle) }}" data-ret-modal>Ver ficha e historial completo</a></p>
