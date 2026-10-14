@php($planning = $planning ?? false)
<div class="km-vehicle-list">
@forelse($displayRows as $row)
@php($vehicle = $row['vehicle'])
@php($canUpdate = $editable && $row['can_edit'])
<article class="km-vehicle-card {{ $row['level'] }}" data-maintenance-interval="{{ $row['interval'] }}">
<div class="km-card-title"><span class="km-trend-anchor"><a href="{{ route('fleet.vehicles.show', $vehicle) }}" data-ret-modal data-km-hover="{{ $vehicle->id }}"><strong>{{ $vehicle->plate }}</strong></a><button type="button" class="km-trend-trigger" data-km-trend="{{ $vehicle->id }}" aria-label="Ver comparación semanal de {{ $vehicle->plate }}" aria-haspopup="dialog" aria-expanded="false" aria-controls="km-trend-panel">↗</button></span><small>{{ $row['owner']->code }}</small></div>
<p class="km-card-model">{{ $vehicle->brand }} {{ $vehicle->model }}</p>
<div class="km-card-data">
<div><span>Última mantención</span><strong data-maintenance-baseline="{{ $row['baseline'] }}">{{ $row['baseline'] !== null ? number_format($row['baseline'], 0, ',', '.').' km' : 'Sin registro' }}</strong></div>
<div><span>Próxima mantención</span><strong data-next-km>{{ $row['due'] !== null ? number_format($row['due'], 0, ',', '.').' km' : 'Sin definir' }}</strong></div>
</div>
@if($canUpdate)
<input type="hidden" name="readings[{{ $vehicle->id }}][vehicle_id]" value="{{ $vehicle->id }}">
<label class="km-card-reading">Actualizar kilometraje<input aria-label="KM actual {{ $vehicle->plate }}" type="number" min="0" max="2000000000" name="readings[{{ $vehicle->id }}][odometer_km]" value="{{ old('readings.'.$vehicle->id.'.odometer_km', $row['weekly_missing'] ? null : $row['current']) }}"></label>
@if($row['weekly_missing'])<small class="km-card-hint">Sin lectura esta semana.@if($row['current'] !== null) Último: {{ number_format($row['current'], 0, ',', '.') }} km.@endif</small>@endif
@else<p class="km-card-hint">KM actual: {{ $row['current'] !== null ? number_format($row['current'], 0, ',', '.') : 'Sin lectura' }}</p>@endif
<div class="km-card-status"><span class="km-pill {{ $row['level'] }}">{{ $row['label'] }}</span>@if($row['remaining'] !== null)<small data-remaining-km>{{ number_format($row['remaining'], 0, ',', '.') }} km restantes</small>@endif</div>
@foreach($row['issues'] as $issue)<small class="km-card-hint">{{ $issue }}</small>@endforeach
@if($planning)
<label class="km-card-reading">Observaciones @if($canUpdate)<input type="text" maxlength="2000" aria-label="Observaciones {{ $vehicle->plate }}" name="readings[{{ $vehicle->id }}][notes]" value="{{ old('readings.'.$vehicle->id.'.notes', $row['reading']?->notes) }}">@else<span>{{ $row['reading']?->notes ?: 'Sin observaciones' }}</span>@endif</label>
@if($row['can_plan'])<a class="button km-plan" data-ret-modal href="{{ route('fleet.maintenance.create', ['vehicle_id' => $vehicle->id]) }}">Programar mantención</a>@endif
@elseif($canUpdate)<input type="hidden" name="readings[{{ $vehicle->id }}][notes]" value="{{ old('readings.'.$vehicle->id.'.notes', $row['reading']?->notes) }}">@endif
</article>
@empty<p class="km-lane-empty">Sin vehículos pendientes en esta sección.</p>@endforelse
</div>
