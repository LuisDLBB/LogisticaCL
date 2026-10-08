@extends('operations::layout')
@section('title','Programación de salidas')
@section('content')
<h1>Salidas de agencias · Proceso #{{ $lot->id }}</h1><p class="intro">{{ $lot->name }} · {{ $lot->operation_date }}</p><p><a href="{{ route('operations.lots.show',$lot->id) }}">Volver a bultos e incidencias</a> · <a href="#planilla">Ver planilla general</a></p>
@if($blocked)<p class="warning">Hay {{ $blocked }} incidencias pendientes. Resuélvelas antes de programar salidas.</p>@endif
@if($legacyDrafts)<p class="warning">Hay {{ $legacyDrafts }} salidas pendientes con el recorrido anterior. Sus datos se conservan para revisión. Para aplicar la nueva consolidación a esos bultos, cancela primero las salidas pendientes desde el último tramo hacia la troncal y vuelve a programarlas.</p>@endif
@foreach($missingAddresses as $agencyId=>$agencyCoverages)
<div class="warning">Las coberturas de {{ $agencyCoverages->pluck('commune_name')->unique()->join(', ') }} están vinculadas a <strong>{{ $agencyCoverages->first()->agency_name }}</strong>, pero falta la dirección de entrega de la agencia. La comuna y la ruta están en Coberturas; la dirección física se registra en Agencias y guías. <a href="{{ route('operations.setup') }}#agency-{{ $agencyId }}">Completar dirección de {{ $agencyCoverages->first()->agency_name }}</a>.</div>
@endforeach
@if($missingRoutes->isNotEmpty())<div class="warning">No se pudo completar la ruta automática para: @foreach($missingRoutes as $coverage)<span>{{ $coverage->commune_name }} (#{{ $coverage->id }}){{ !$loop->last ? ', ' : '.' }}</span>@endforeach <a href="{{ route('operations.setup') }}">Revisar agencias y coberturas</a></div>@endif
<section class="card" style="margin-bottom:18px">@php($pendingReservationCount=$pendingReservations->sum('package_count'))<h2>Reservas guardadas · {{ $pendingReservationCount }} {{ $pendingReservationCount === 1 ? 'bulto' : 'bultos' }}</h2>
<p class="note">Una reserva es carga que no saldrá en el proceso de origen. Se conserva sin fecha obligatoria y vuelve a partir desde bodega. Selecciona aquí las reservas que saldrán en este proceso: sus bultos se sumarán a los recibidos hoy y usarán la programación de rutas vigente.</p>
@if($pendingReservations->isNotEmpty())
@unless($canIncludeReservations)<p class="warning">Este proceso ya tiene salidas programadas. Para agregar reservas, cancela primero sus salidas pendientes o utiliza un proceso nuevo.</p>@endunless
<form method="POST" action="{{ route('operations.reservations.include',$lot->id) }}">@csrf
<div class="table-wrap"><table class="ope-table"><thead><tr><th>Incluir</th><th>Proceso origen</th><th>Ruta reservada</th><th>Bultos</th><th>Peso kg</th><th></th></tr></thead><tbody>
@foreach($pendingReservations as $reservation)
@php($sourceEligible=$reservation->source_lot_id !== $lot->id && $reservation->source_date <= $lot->operation_date)
@php($eligible=$canIncludeReservations && $sourceEligible)
<tr><td><input type="checkbox" name="batch_ids[]" value="{{ $reservation->batch_id }}" aria-label="Incluir reserva {{ $reservation->route_label }} del proceso {{ $reservation->source_lot_id }}" @disabled(!$eligible)></td><td>#{{ $reservation->source_lot_id }} · {{ $reservation->source_name }}<small>{{ $reservation->source_date }}</small></td><td>{{ $reservation->route_label }}<small>En bodega de origen{{ $reservation->returned_after_departure ? ' · retorno confirmado' : '' }}</small> @unless($sourceEligible)<small>Disponible en otro proceso de la misma fecha o una posterior</small>@endunless</td><td>{{ $reservation->package_count }}</td><td>{{ number_format((float)$reservation->weight,3,',','.') }}</td><td>@if($reservation->source_lot_id === $lot->id && !$reservation->returned_after_departure)<button type="submit" formaction="{{ route('operations.reservations.cancel',[$lot->id,$reservation->batch_id]) }}">Devolver al proceso</button>@endif</td></tr>
@endforeach
</tbody></table></div><div class="ope-actions"><button @disabled(!$canIncludeReservations)>Incluir reservas seleccionadas</button></div></form>
@else<p class="ope-empty">No hay reservas pendientes para incluir.</p>@endif
</section>
@if($includedReservations->isNotEmpty())<section class="card" style="margin-bottom:18px"><h2>Carga incorporada desde reservas</h2><p class="note">Estos bultos ya se sumaron a la recepción del proceso y dejaron de estar pendientes en reservas. Puedes devolverlos a reservas solo antes de programar una salida.</p><div class="table-wrap"><table class="ope-table"><thead><tr><th>Proceso origen</th><th>Ruta reservada</th><th>Bultos</th><th>Peso kg</th><th>Estado</th></tr></thead><tbody>@foreach($includedReservations as $reservation)<tr><td>#{{ $reservation->source_lot_id }} · {{ $reservation->source_name }}</td><td>{{ $reservation->route_label }}</td><td>{{ $reservation->package_count }}</td><td>{{ number_format((float)$reservation->weight,3,',','.') }}</td><td>@if($reservation->scheduled_count)<span class="ope-badge">Programada en este proceso</span>@else<form method="POST" action="{{ route('operations.reservations.return',[$lot->id,$reservation->batch_id]) }}">@csrf<button>Devolver a reservas</button></form>@endif</td></tr>@endforeach</tbody></table></div></section>@endif
<section class="card"><h2>Marcar agencias que salen</h2><form method="POST" action="{{ route('operations.departures.store',$lot->id) }}">@csrf
<div class="ope-form"><label>Nombre de salida<input name="name" value="{{ old('name','Salida de agencias') }}" required maxlength="160" placeholder="Troncal Sur · salida 1"></label><label>Fecha<input type="date" name="departure_date" value="{{ old('departure_date',$lot->operation_date) }}" required></label></div>
<p class="note">Los bultos provienen de Recepción y de las reservas incluidas. Cada fila representa una guía para un vehículo y punto de descarga; las cargas que siguen juntas se consolidan hasta Chillán, Temuco, Coquimbo o el aeropuerto. Marca las rutas que saldrán en la fecha indicada y programa primero la troncal. Si una carga no saldrá, márcala y guárdala como reserva para otro proceso.</p>
@php($agencyLegs=$configurations->groupBy(fn($row) => $row->group_code
    ? $row->sequence.'|'.$row->group_code.'|'.($row->leg_trunk_plate ?? $row->leg_post_plate).'|'.($row->leg_trunk_driver_rut ?? $row->leg_post_driver_rut).'|'.($row->leg_trunk_driver_name ?? $row->leg_post_driver_name)
    : ($row->agency_id ?? 'legacy-'.$row->id).'|'.$row->role))
<label class="ope-actions"><input type="checkbox" data-select-all @disabled($blocked || $agencyLegs->isEmpty())> Seleccionar todas las disponibles</label>
<div class="table-wrap"><table class="ope-table"><thead><tr><th>Sale</th><th>Tramo</th><th>Agencia</th><th>Transporte</th><th>Origen → Destino</th><th>Bultos</th><th>Peso kg</th><th>Estado</th></tr></thead><tbody>@forelse($agencyLegs as $leg)
@php($configuration=$leg->first())
@php($legScheduled=$leg->contains(fn($row) => $scheduled->contains($row->id)))
@php($agencyNames=$leg->pluck('agency_name')->filter()->unique()->values())
@php($agencyLabel=$agencyNames->count()>1 ? $configuration->destination_name.' · '.$agencyNames->count().' agencias' : ($agencyNames->first() ?? $configuration->name))
@php($transportName=$configuration->leg_trunk_name ?? $configuration->leg_post_name ?? ($configuration->role==='troncal' ? $configuration->trunk_name : ($configuration->role==='posta1' ? $configuration->first_post_name : $configuration->second_post_name)))
<tr><td><input aria-label="Seleccionar {{ $agencyLabel }} {{ $configuration->role }}" type="checkbox" name="configuration_ids[]" value="{{ $configuration->id }}" data-departure-choice @disabled($legScheduled || $blocked) @checked(in_array($configuration->id,old('configuration_ids',[])))></td><td>{{ ['troncal'=>'Troncal','posta1'=>'Posta 1','posta2'=>'Posta 2','posta3'=>'Posta 3'][$configuration->role] ?? $configuration->role }}</td><td>{{ $agencyLabel }}<small>{{ $leg->count() }} coberturas @if($agencyNames->count()>1)· {{ $agencyNames->join(', ') }}@endif</small></td><td>{{ $transportName }}</td><td>{{ $configuration->origin_name }} → {{ $configuration->destination_name }}</td><td>{{ $leg->sum(fn($row) => $counts[$row->coverage_id]->count) }}</td><td>{{ number_format($leg->sum(fn($row) => (float)$counts[$row->coverage_id]->weight),3,',','.') }}</td><td><span class="ope-badge">{{ $legScheduled ? 'Programada' : 'Disponible' }}</span></td></tr>@empty<tr><td colspan="8" class="ope-empty">No hay bultos recibidos con agencia y ruta asignadas en este proceso.</td></tr>@endforelse</tbody></table></div>
<label class="ope-actions"><input type="checkbox" name="warehouse_returned" value="1" @checked(old('warehouse_returned'))> Si alguna troncal o posta anterior ya salió, confirmo que estos bultos regresaron físicamente a la bodega de origen. La guía anterior quedará registrada.</label>
<div class="ope-actions"><button @disabled($blocked) data-submit-departures>Programar seleccionadas</button><button type="submit" formaction="{{ route('operations.reservations.store',$lot->id) }}" @disabled($blocked) data-reserve-departures>Guardar seleccionadas como reserva</button><span class="note" data-selection-count role="status">0 seleccionadas</span></div></form></section>
<section id="planilla" class="card" style="margin:18px 0;scroll-margin-top:90px"><div class="ope-actions" style="justify-content:space-between"><h2 style="margin:0">Planilla general de salidas</h2><a class="button" href="{{ route('operations.departures.spreadsheet',$lot->id) }}">Descargar Excel</a></div>
<p class="note">{{ count($spreadsheetRows) }} {{ count($spreadsheetRows) === 1 ? 'fila' : 'filas' }} de las salidas vigentes. El N.º comienza en 1 para cada salida y agencia. El peso se muestra en kg enteros y las salidas canceladas no se incluyen.</p>
<div class="table-wrap" style="max-height:65vh"><table class="ope-table" style="min-width:1850px"><thead><tr><th>Fecha declarada</th><th>Transporte</th><th>N.º</th><th>Dirección origen</th><th>Comuna origen</th><th>Patente</th><th>RUT chofer</th><th>Nombre chofer</th><th>Dirección destino</th><th>Comuna destino</th><th>Agencia</th><th>Glosa</th><th>Bultos</th><th>Suma de peso</th></tr></thead><tbody>@forelse($spreadsheetRows as $row)<tr><td>{{ \Carbon\Carbon::parse($row['declared_date'])->format('d-m-Y') }}</td><td>{{ $row['transport'] }}</td><td>{{ $row['number'] }}</td><td>{{ $row['origin_address'] }}</td><td>{{ $row['origin_commune'] }}</td><td>{{ $row['plate'] }}</td><td>{{ $row['driver_rut'] }}</td><td>{{ $row['driver_name'] }}</td><td>{{ $row['destination_address'] }}</td><td>{{ $row['destination_commune'] }}</td><td>{{ $row['agency'] }}</td><td>{{ $row['description'] }}</td><td>{{ $row['count'] }}</td><td>{{ $row['weight'] }}</td></tr>@empty<tr><td colspan="14" class="ope-empty">Todavía no hay salidas vigentes para incluir en la planilla.</td></tr>@endforelse</tbody></table></div></section>
<section id="generacion-guias" style="scroll-margin-top:90px">
<h2>Generación de Guías</h2>
<p class="note">Revisa las salidas aprobadas y sus números de Bsale. Cada salida puede generar varias hojas de hasta 15 líneas. La emisión masiva respeta el orden troncal, Posta 1, Posta 2 y Posta 3; se detiene si alguna hoja queda incierta.</p>
@if($guideDates->isNotEmpty())
<form method="GET" action="{{ route('operations.departures.index', $lot->id) }}" class="ope-actions">
    <label>Fecha de salida <select name="fecha" onchange="this.form.submit()">@foreach($guideDates as $date)<option value="{{ $date }}" @selected($date === $selectedGuideDate)>{{ \Carbon\Carbon::parse($date)->format('d-m-Y') }}</option>@endforeach</select></label>
    <a href="{{ route('operations.guide-generation.overview', ['fecha' => $selectedGuideDate]) }}">Ver otras fechas y procesos</a>
    <a class="button" href="{{ route('operations.guide-generation.spreadsheet', ['lot' => $lot->id, 'fecha' => $selectedGuideDate]) }}">Descargar Excel con guías Bsale</a>
</form>
@endif
@if($historicalBsaleCount > 0)<p class="warning">Hay {{ $historicalBsaleCount }} guías Bsale de versiones anteriores de estas salidas. Si las nuevas corrigen esas emisiones, comprueba primero su anulación en Bsale.</p>@endif
@if($blockedBsale)<p class="warning">Hay una emisión incierta o en curso. Revísala en <a href="{{ route('operations.guides.bsale.index') }}">el historial de Bsale</a> antes de emitir más guías.</p>@endif
@if($canApproveAll && count($bulkGuideUrls) > 0)
<div class="ope-actions"><button type="button" data-generate-bsale-many>Generar guías masivas en Bsale ({{ count($bulkGuideUrls) }})</button><span class="note" data-generate-bsale-status role="status"></span></div>
@endif
@if($canApproveAll && $pendingDepartureCount > 0)
<form method="POST" action="{{ route('operations.departures.approve-all',$lot->id) }}" class="card" data-approve-all>@csrf
<strong>{{ $pendingDepartureCount }} {{ $pendingDepartureCount === 1 ? 'salida pendiente' : 'salidas pendientes' }}</strong>
<p class="note">Se aprobarán primero las troncales y luego las postas. Si una guía no cumple los requisitos, no se aprobará ninguna y verás cuál debes corregir.</p>
<div class="ope-actions"><label><input type="checkbox" name="confirmed" value="1" required> Confirmo agencias, direcciones, choferes, RUT, patentes, bultos y pesos de todas las salidas pendientes.</label><button type="submit">Aprobar todo</button></div>
</form>
@endif
<div class="card table-wrap"><table class="ope-table"><thead><tr><th>Salida</th><th>Fecha / Tramo</th><th>Transporte</th><th>Estado</th><th>Guía Bsale</th><th>Acción</th></tr></thead><tbody>
@forelse($guideDepartures as $departure)
    @php($guide = $currentGuides->get($departure->id))
    @php($emissions = $guide ? $bsaleEmissions->get($guide->id, collect()) : collect())
    <tr><td>#{{ $departure->id }} · {{ $departure->name }}</td><td>{{ $departure->departure_date }} / {{ $departure->role }}</td><td>{{ $departure->plate ?: 'Sin patente' }} · {{ $departure->driver_name ?: 'Sin chofer' }}</td><td>{{ ['approved'=>'Aprobada','draft'=>'Pendiente supervisor','cancelled'=>'Cancelada'][$departure->status] }}</td><td>
        @forelse($emissions as $emission)
            <div class="ope-guide-links"><strong>Hoja {{ $emission->sheet_number }} de {{ $emission->sheet_count }}:</strong> {{ $emission->numero ?: ucfirst($emission->estado) }}
            @if($emission->estado === 'generada' && $emission->url_pdf && in_array(parse_url($emission->url_pdf, PHP_URL_SCHEME), ['http', 'https'], true))<a href="{{ $emission->url_pdf }}" target="_blank" rel="noopener noreferrer">Ver guía</a>@endif
            @if($emission->estado === 'generada' && \App\Modules\Operations\Services\OperationBsaleGuideService::downloadablePdfUrl($emission->url_pdf))<a href="{{ route('operations.guides.bsale.pdf', $emission->id) }}">Descargar</a>@endif
            </div>
        @empty
            <span class="ope-muted">Aún sin guía Bsale</span>
        @endforelse
    </td><td><a href="{{ $guide ? route('operations.guides.show', $guide->id) : route('operations.departures.show', $departure->id) }}">{{ $guide ? 'Ver guía interna' : 'Revisar salida' }}</a></td></tr>
@empty
    <tr><td colspan="6" class="ope-empty">No hay salidas programadas para esta fecha.</td></tr>
@endforelse
</tbody></table></div>
</section>
@endsection
@push('scripts')
<script>
const departureChoices = [...document.querySelectorAll('[data-departure-choice]:not(:disabled)')];
const selectAllDepartures = document.querySelector('[data-select-all]');
const selectionCount = document.querySelector('[data-selection-count]');
const submitDepartures = document.querySelector('[data-submit-departures]');
const reserveDepartures = document.querySelector('[data-reserve-departures]');
function updateDepartureSelection() {
    const selected = departureChoices.filter(choice => choice.checked).length;
    selectionCount.textContent = `${selected} seleccionada${selected === 1 ? '' : 's'}`;
    submitDepartures.disabled = selected === 0;
    reserveDepartures.disabled = selected === 0;
    selectAllDepartures.disabled = departureChoices.length === 0;
    selectAllDepartures.checked = departureChoices.length > 0 && selected === departureChoices.length;
    selectAllDepartures.indeterminate = selected > 0 && selected < departureChoices.length;
}
selectAllDepartures?.addEventListener('change', () => {
    departureChoices.forEach(choice => { choice.checked = selectAllDepartures.checked; });
    updateDepartureSelection();
});
departureChoices.forEach(choice => choice.addEventListener('change', updateDepartureSelection));
submitDepartures?.closest('form')?.addEventListener('submit', (event) => {
    if (event.submitter === reserveDepartures) {
        reserveDepartures.textContent = 'Guardando reserva…';
    } else {
        submitDepartures.textContent = 'Programando salidas…';
    }
});
if (selectAllDepartures && selectionCount && submitDepartures) updateDepartureSelection();
document.querySelector('[data-generate-bsale-many]')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    const status = document.querySelector('[data-generate-bsale-status]');
    const urls = @json($bulkGuideUrls);
    const token = @json(csrf_token());
    button.disabled = true;
    for (let index = 0; index < urls.length; index++) {
        status.textContent = `Generando ${index + 1} de ${urls.length}…`;
        try {
            const response = await fetch(urls[index], {method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': token}});
            const result = await response.json();
            if (!response.ok || result.estado !== 'generada') {
                status.textContent = result.message || result.errors?.bsale?.[0] || 'Emisión detenida. Revisa la guía en Bsale antes de continuar.';
                return;
            }
        } catch {
            status.textContent = 'No se pudo confirmar la emisión. Revisa Bsale antes de volver a intentarlo.';
            return;
        }
    }
    status.textContent = `${urls.length} guías internas procesadas. Actualizando números Bsale…`;
    window.location.reload();
});
document.querySelector('[data-approve-all]')?.addEventListener('submit', event => {
    const button = event.currentTarget.querySelector('button[type="submit"]');
    button.disabled = true;
    button.textContent = 'Aprobando guías…';
});
</script>
@endpush
