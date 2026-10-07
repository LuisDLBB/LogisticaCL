@extends('operations::layout')
@section('title','Programación de salidas')
@section('content')
<h1>Salidas de agencias · Proceso #{{ $lot->id }}</h1><p class="intro">{{ $lot->name }} · {{ $lot->operation_date }}</p><p><a href="{{ route('operations.lots.show',$lot->id) }}">Volver a bultos e incidencias</a> · <a href="#planilla">Ver planilla general</a></p>
@if($blocked)<p class="warning">Hay {{ $blocked }} incidencias pendientes. Resuélvelas antes de programar salidas.</p>@endif
@foreach($missingAddresses as $agencyId=>$agencyCoverages)
<div class="warning">Las coberturas de {{ $agencyCoverages->pluck('commune_name')->unique()->join(', ') }} están vinculadas a <strong>{{ $agencyCoverages->first()->agency_name }}</strong>, pero falta la dirección de entrega de la agencia. La comuna y la ruta están en Coberturas; la dirección física se registra en Agencias y guías. <a href="{{ route('operations.setup') }}#agency-{{ $agencyId }}">Completar dirección de {{ $agencyCoverages->first()->agency_name }}</a>.</div>
@endforeach
@if($missingRoutes->isNotEmpty())<div class="warning">No se pudo completar la ruta automática para: @foreach($missingRoutes as $coverage)<span>{{ $coverage->commune_name }} (#{{ $coverage->id }}){{ !$loop->last ? ', ' : '.' }}</span>@endforeach <a href="{{ route('operations.setup') }}">Revisar agencias y coberturas</a></div>@endif
<section class="card"><h2>Marcar agencias que salen</h2><form method="POST" action="{{ route('operations.departures.store',$lot->id) }}">@csrf
<div class="ope-form"><label>Nombre de salida<input name="name" value="{{ old('name','Salida de agencias') }}" required maxlength="160" placeholder="Troncal Sur · salida 1"></label><label>Fecha<input type="date" name="departure_date" value="{{ old('departure_date',$lot->operation_date) }}" required></label></div>
<p class="note">Los bultos provienen de Recepción y se cruzan con Maestro Geolize. Selecciona varias agencias o todas las disponibles: se creará una salida por agencia y tramo, en orden Troncal, Posta 1 y Posta 2. Podrás revisar cada salida y su transporte antes de aprobar la guía.</p>
@php($agencyLegs=$configurations->groupBy(fn($row) => ($row->agency_id ?? 'legacy-'.$row->id).'|'.$row->role))
<label class="ope-actions"><input type="checkbox" data-select-all @disabled($blocked || $agencyLegs->isEmpty())> Seleccionar todas las disponibles</label>
<div class="table-wrap"><table class="ope-table"><thead><tr><th>Sale</th><th>Tramo</th><th>Agencia</th><th>Transporte</th><th>Origen → Destino</th><th>Bultos</th><th>Peso kg</th><th>Estado</th></tr></thead><tbody>@forelse($agencyLegs as $leg)@php($configuration=$leg->first())@php($legScheduled=$leg->contains(fn($row) => $scheduled->contains($row->id)))<tr><td><input aria-label="Seleccionar {{ $configuration->name }} {{ $configuration->role }}" type="checkbox" name="configuration_ids[]" value="{{ $configuration->id }}" data-departure-choice @disabled($legScheduled || $blocked) @checked(in_array($configuration->id,old('configuration_ids',[])))></td><td>{{ ['troncal'=>'Troncal','posta1'=>'Posta 1','posta2'=>'Posta 2'][$configuration->role] }}</td><td>{{ $configuration->agency_name ?? $configuration->name }}<small>{{ $leg->count() }} coberturas</small></td><td>{{ $configuration->role==='troncal' ? $configuration->trunk_name : ($configuration->role==='posta1' ? $configuration->first_post_name : $configuration->second_post_name) }}</td><td>{{ $configuration->origin_name }} → {{ $configuration->destination_name }}</td><td>{{ $leg->sum(fn($row) => $counts[$row->coverage_id]->count) }}</td><td>{{ number_format($leg->sum(fn($row) => (float)$counts[$row->coverage_id]->weight),3,',','.') }}</td><td><span class="ope-badge">{{ $legScheduled ? 'Programada' : 'Reserva' }}</span></td></tr>@empty<tr><td colspan="8" class="ope-empty">No hay bultos recibidos con agencia y ruta asignadas en este proceso.</td></tr>@endforelse</tbody></table></div>
<div class="ope-actions"><button @disabled($blocked) data-submit-departures>Programar seleccionadas</button><span class="note" data-selection-count role="status">0 seleccionadas</span></div></form></section>
<section id="planilla" class="card" style="margin:18px 0;scroll-margin-top:90px"><div class="ope-actions" style="justify-content:space-between"><h2 style="margin:0">Planilla general de salidas</h2><a class="button" href="{{ route('operations.departures.spreadsheet',$lot->id) }}">Descargar Excel</a></div>
<p class="note">{{ count($spreadsheetRows) }} {{ count($spreadsheetRows) === 1 ? 'fila' : 'filas' }} de las salidas vigentes. El N.º comienza en 1 para cada salida y agencia. El peso se muestra en kg enteros y las salidas canceladas no se incluyen.</p>
<div class="table-wrap" style="max-height:65vh"><table class="ope-table" style="min-width:1850px"><thead><tr><th>Fecha declarada</th><th>Transporte</th><th>N.º</th><th>Dirección origen</th><th>Comuna origen</th><th>Patente</th><th>RUT chofer</th><th>Nombre chofer</th><th>Dirección destino</th><th>Comuna destino</th><th>Agencia</th><th>Glosa</th><th>Bultos</th><th>Suma de peso</th></tr></thead><tbody>@forelse($spreadsheetRows as $row)<tr><td>{{ \Carbon\Carbon::parse($row['declared_date'])->format('d-m-Y') }}</td><td>{{ $row['transport'] }}</td><td>{{ $row['number'] }}</td><td>{{ $row['origin_address'] }}</td><td>{{ $row['origin_commune'] }}</td><td>{{ $row['plate'] }}</td><td>{{ $row['driver_rut'] }}</td><td>{{ $row['driver_name'] }}</td><td>{{ $row['destination_address'] }}</td><td>{{ $row['destination_commune'] }}</td><td>{{ $row['agency'] }}</td><td>{{ $row['description'] }}</td><td>{{ $row['count'] }}</td><td>{{ $row['weight'] }}</td></tr>@empty<tr><td colspan="14" class="ope-empty">Todavía no hay salidas vigentes para incluir en la planilla.</td></tr>@endforelse</tbody></table></div></section>
<h2>Salidas y revisión del supervisor</h2><div class="card table-wrap"><table class="ope-table"><thead><tr><th>Salida</th><th>Fecha / Tramo</th><th>Transporte</th><th>Estado</th><th></th></tr></thead><tbody>@forelse($departures as $departure)<tr><td>#{{ $departure->id }} · {{ $departure->name }}</td><td>{{ $departure->departure_date }} / {{ $departure->role }}</td><td>{{ $departure->plate ?: 'Sin patente' }} · {{ $departure->driver_name ?: 'Sin chofer' }}</td><td>{{ ['approved'=>'Aprobada','draft'=>'Pendiente supervisor','cancelled'=>'Cancelada'][$departure->status] }}</td><td><a href="{{ route('operations.departures.show',$departure->id) }}">Revisar salida y guía</a></td></tr>@empty<tr><td colspan="5" class="ope-empty">Selecciona las agencias para crear la primera salida.</td></tr>@endforelse</tbody></table></div>
@endsection
@push('scripts')
<script>
const departureChoices = [...document.querySelectorAll('[data-departure-choice]:not(:disabled)')];
const selectAllDepartures = document.querySelector('[data-select-all]');
const selectionCount = document.querySelector('[data-selection-count]');
const submitDepartures = document.querySelector('[data-submit-departures]');
function updateDepartureSelection() {
    const selected = departureChoices.filter(choice => choice.checked).length;
    selectionCount.textContent = `${selected} seleccionada${selected === 1 ? '' : 's'}`;
    submitDepartures.disabled = selected === 0;
    selectAllDepartures.disabled = departureChoices.length === 0;
    selectAllDepartures.checked = departureChoices.length > 0 && selected === departureChoices.length;
    selectAllDepartures.indeterminate = selected > 0 && selected < departureChoices.length;
}
selectAllDepartures?.addEventListener('change', () => {
    departureChoices.forEach(choice => { choice.checked = selectAllDepartures.checked; });
    updateDepartureSelection();
});
departureChoices.forEach(choice => choice.addEventListener('change', updateDepartureSelection));
submitDepartures?.closest('form')?.addEventListener('submit', () => {
    submitDepartures.disabled = true;
    submitDepartures.textContent = 'Programando salidas…';
});
if (selectAllDepartures && selectionCount && submitDepartures) updateDepartureSelection();
</script>
@endpush
