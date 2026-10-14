<div class="monitor-board" data-page="{{ $rows->currentPage() }}" data-pages="{{ $rows->lastPage() }}" data-updated="{{ $updatedAt }}" data-date="{{ \Carbon\Carbon::parse($filters['fecha'])->format('d-m-Y') }}">
@php($labels = ['red'=>'Cancelados / anulados','yellow'=>'Por corregir / reagendar','white'=>'Pendientes','blue'=>'Agendados','green'=>'Retirados'])
<div class="summary-columns"><section class="summary-panel"><h2 class="section-heading">Retiros y factibilidad de hoy <span class="summary-total">Total {{ $total }}</span></h2>
<div class="counters">@foreach($labels as $color=>$label)<div class="counter {{ $color }}"><span>{{ $label }}</span><strong>{{ $counts->get($color, 0) }}</strong></div>@endforeach</div>
</section>
@if($fleetSummary !== null)
<section class="summary-panel"><h2 class="section-heading">Flota operacional</h2>
<div class="fleet-counters">
@foreach(['available' => ['Disponibles', 'green'], 'booked' => ['Agendados hoy', 'blue'], 'warning' => ['Programar mantención', 'yellow'], 'workshop' => ['En taller', 'red'], 'papers' => ['Papeles vencidos', 'dark-red'], 'unavailable' => ['No disponibles', 'dark-red']] as $state => [$label, $color])
<div class="counter {{ $color }}"><span>{{ $label }}</span><strong>{{ $fleetSummary[$state] }}</strong></div>
@endforeach
</div><small class="fleet-note">Programar mantención permite seguir agendando servicios.</small></section>
@endif
</div>
<div class="board-meta"><strong>{{ $rows->total() }} solicitudes visibles</strong><span>Actualizado {{ $updatedAt }} · Página {{ $rows->currentPage() }} de {{ $rows->lastPage() }}</span></div>
<div class="table-wrap"><table><thead><tr><th>Solicitud</th><th>Cliente / lugar de servicio</th><th>Desde / hasta</th><th>Bultos</th><th>Estado / motivo</th><th>Asignación</th></tr></thead><tbody>@forelse($rows as $row)<tr class="state-{{ $row['color'] }}"><td data-label="Solicitud"><a href="{{ $row['url'] }}" target="_blank" rel="noopener">{{ $row['code'] }}</a><small>{{ $row['type'] }}{{ $row['test'] ? ' · Prueba' : '' }}</small></td><td data-label="Cliente / lugar"><strong>{{ $row['client'] }}</strong><small>{{ $row['address'] }}</small></td><td data-label="Desde / hasta"><strong>{{ $row['time'] }}</strong></td><td data-label="Bultos"><strong>{{ $row['packages'] ?? '—' }}</strong></td><td data-label="Estado / motivo"><span class="badge {{ $row['color'] }}">{{ $row['state'] }}</span>@if(filled($row['reason']))<small class="reason">{{ $row['reason'] }}</small>@endif</td><td data-label="Asignación">{{ $row['assignment'] }}</td></tr>@empty<tr><td colspan="6" class="empty">Sin solicitudes para la jornada de hoy.</td></tr>@endforelse</tbody></table></div>
<footer><span>Rojo: anulado · Amarillo: corrección · Blanco: pendiente · Azul: agendado · Verde: retirado</span><div>@if($rows->currentPage()>1)<a data-page="{{ $rows->currentPage()-1 }}" href="{{ $rows->previousPageUrl() }}">← Anterior</a>@endif @if($rows->hasMorePages())<a data-page="{{ $rows->currentPage()+1 }}" href="{{ $rows->nextPageUrl() }}">Siguiente →</a>@endif</div></footer></div>
