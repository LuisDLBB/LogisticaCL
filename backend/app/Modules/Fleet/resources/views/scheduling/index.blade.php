@extends('fleet::layout')
@section('title', 'Programación')
@section('content')
<div class="page-heading"><div><h1>Programación</h1><p class="muted">Agenda del {{ \Carbon\Carbon::parse($date)->format('d-m-Y') }} · hasta {{ $limit }} retiros coincidentes por conductor o vehículo sin autorización.</p></div></div>
<form method="get" class="card filter-grid">
    <label>Fecha<input type="date" name="fecha" value="{{ $date }}"></label>
    <label>Jornada<select name="jornada"><option value="">Todas</option>@foreach(['AM', 'PM'] as $shift)<option value="{{ $shift }}" @selected(($filters['jornada'] ?? '') === $shift)>{{ $shift }}</option>@endforeach</select></label>
    <label>Cliente<select name="client_id"><option value="">Todos</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(($filters['client_id'] ?? '') == $client->id)>{{ $client->commercial_name }}</option>@endforeach</select></label>
    <label>RET<input name="ret" value="{{ $filters['ret'] ?? '' }}" placeholder="RET-00030"></label>
    <label>Estado<select name="estado"><option value="">Todos</option>@foreach(['requested'=>'Solicitado','scheduled'=>'Agendado','reprogramming_pending'=>'Reprogramación pendiente','rescheduled'=>'Reagendado','retired'=>'Retirado','cancelled'=>'Anulado'] as $code=>$label)<option value="{{ $code }}" @selected(($filters['estado'] ?? '') === $code)>{{ $label }}</option>@endforeach</select></label>
    <label>Conductor<select name="driver_user_id"><option value="">Todos</option>@foreach($drivers as $driver)<option value="{{ $driver->id }}" @selected(($filters['driver_user_id'] ?? '') == $driver->id)>{{ $driver->name }}</option>@endforeach</select></label>
    <label>Vehículo<select name="vehicle_id"><option value="">Todos</option>@foreach($pool as $item)<option value="{{ $item['vehicle']->id }}" @selected(($filters['vehicle_id'] ?? '') == $item['vehicle']->id)>{{ $item['vehicle']->plate }} · {{ $item['owner']->code }}</option>@endforeach</select></label>
    <div><button type="submit">Filtrar</button></div>
</form>
@if($canApprove && $pending->isNotEmpty())
<section class="card"><h2>Autorizaciones pendientes</h2>
    @foreach($pending as $approval)
    <div class="scheduling-approval"><strong>{{ $approval->ret_code }}</strong> · {{ $approval->service_date }} {{ substr($approval->window_start,0,5) }}–{{ substr($approval->window_end,0,5) }} · {{ $approval->driver_name }} ({{ $approval->driver_concurrences }}) · {{ $approval->plate }} ({{ $approval->vehicle_concurrences }}) · límite {{ $approval->limit_snapshot }}<p>{{ $approval->reason }}</p>
        @if($approval->requested_by_user_id === auth()->id())
            <small>Tu solicitud requiere la decisión de otra persona con permiso de jefatura.</small>
        @else
            <form method="post" action="{{ route('fleet.scheduling.overload.decide', $approval->id) }}">@csrf<input name="decision_notes" placeholder="Observación de jefatura"><button name="decision" value="approved">Aprobar</button><button name="decision" value="rejected" class="button-quiet">Rechazar</button></form>
        @endif
    </div>
    @endforeach
</section>
@endif
<section class="card"><h2>Retiros del día</h2><div class="table-wrap"><table><thead><tr><th>RET / estado</th><th>Cliente / punto</th><th>Dirección</th><th>Ventana</th><th>Bultos / material</th><th>Conductor</th><th>Vehículo</th><th>Alertas</th><th>Acciones</th></tr></thead><tbody>
@forelse($rows as $ret)
@php($vehicleRow = $pool->first(fn ($item) => $item['vehicle']->id === $ret->vehicle_id))
@php($warning = $warnings[$ret->id])
<tr>
    <td><a href="{{ route('fleet.requests.show', $ret) }}"><strong>{{ $ret->ret_code }}</strong></a><br><span class="tag">{{ ['requested'=>'Solicitado','scheduled'=>'Agendado','reprogramming_pending'=>'Reprogramación pendiente','rescheduled'=>'Reagendado','retired'=>'Retirado','cancelled'=>'Anulado'][$ret->status] ?? $ret->status }}</span></td>
    <td>{{ $ret->client_name_snapshot }}<br><small>{{ $ret->point_name_snapshot }}</small></td>
    <td>{{ $ret->effective_address }}</td>
    <td>{{ $ret->shift }}<br>{{ substr($ret->window_start,0,5) }}–{{ substr($ret->window_end,0,5) }}</td>
    <td>{{ $ret->packages ?? 'Pendiente' }}<br><small>{{ $ret->material }}<br>{{ $ret->requested_volume_m3 ?? '—' }} m³ · {{ $ret->requested_weight_kg ?? '—' }} kg</small></td>
    <td>{{ $ret->driver?->name ?? 'Sin asignar' }}</td>
    <td>{{ $ret->vehicle?->plate ?? 'Sin asignar' }}@if($vehicleRow)<br><small>{{ $vehicleRow['vehicle']->vehicle_type }} · {{ $vehicleRow['owner']->code }} · {{ $vehicleRow['vehicle']->operational_status }}</small>@endif</td>
    <td>@if($warning)<span class="tag">Conductor {{ $warning['driver'] }}/{{ $limit }} · Vehículo {{ $warning['vehicle'] }}/{{ $limit }}</span>@endif
        @if($vehicleRow && $vehicleRow['planned_maintenances']->isNotEmpty())<br><strong>Vehículo con mantención programada</strong>@foreach($vehicleRow['planned_maintenances'] as $maintenance)<br><small>{{ $maintenance->scheduled_at?->format('d-m-Y H:i') ?? 'Fecha pendiente' }} · {{ $maintenance->status }} · {{ $maintenance->maintenance_type }}</small>@endforeach@endif
        @if($ret->email_status === 'not_prepared' && !$ret->operational_emails_snapshot)<br><span class="tag off">Correo operacional pendiente</span>@endif
        @if($ret->email_cc_snapshot === null)<br><small>Postventa no asignado</small>@endif
    </td>
    <td>@if($canEdit && in_array($ret->status,['requested','scheduled','rescheduled'],true))
        <form method="post" action="{{ route('fleet.scheduling.assign', $ret) }}" data-preview-url="{{ route('fleet.scheduling.preview', $ret) }}" class="scheduling-form">@csrf
            <label>Conductor<select name="driver_user_id" required><option value="">Seleccionar</option>@foreach($drivers as $driver)<option value="{{ $driver->id }}" @selected($ret->driver_user_id === $driver->id)>{{ $driver->name }}</option>@endforeach</select></label>
            <label>Vehículo<select name="vehicle_id" required><option value="">Seleccionar</option>
                @foreach($pool as $item)
                    @if($item['selectable'])
                        <option value="{{ $item['vehicle']->id }}" @selected($ret->vehicle_id === $item['vehicle']->id)>{{ $item['vehicle']->plate }} · {{ $item['vehicle']->vehicle_type }} · {{ $item['owner']->code }}@if($item['planned_maintenances']->isNotEmpty()) · Mantención programada @endif</option>
                    @endif
                @endforeach
            </select></label>
            @if($ret->status !== 'requested')<label>Motivo del cambio<input name="reason" maxlength="2000"></label>@endif
            <small class="scheduling-preview" aria-live="polite"></small>
            <button type="submit">Guardar asignación</button>
        </form>
        <form method="post" action="{{ route('fleet.scheduling.overload.request', $ret) }}" class="scheduling-form">@csrf
            <input type="hidden" name="driver_user_id" class="scheduling-driver"><input type="hidden" name="vehicle_id" class="scheduling-vehicle">
            <input name="reason" required placeholder="Motivo de sobrecarga"><button type="submit" class="button-quiet">Solicitar autorización</button>
        </form>
        @if($ret->status === 'requested')<form method="post" action="{{ route('fleet.requests.schedule', $ret) }}">@csrf<button>Marcar Agendado</button></form>@endif
        @endif</td>
</tr>
@empty<tr><td colspan="9" class="empty-state">No hay RET para estos filtros.</td></tr>@endforelse
</tbody></table></div></section>
<script>
const compatibleVehicles = @json($drivers->mapWithKeys(fn($driver)=>[$driver->id=>$pool->filter(fn($item)=>app(\App\Fleet\RetVehicleEligibility::class)->compatible($driver,$item['vehicle']))->map(fn($item)=>$item['vehicle']->id)->values()])->all());
for (const row of document.querySelectorAll('tbody tr')) {
    const assignment = row.querySelector('form[action*="/asignar"]');
    const overload = row.querySelector('form[action*="/sobrecarga"]');
    if (!assignment || !overload) continue;
    const filterVehicles = () => {
        const driver = assignment.querySelector('[name="driver_user_id"]').value;
        const select = assignment.querySelector('[name="vehicle_id"]');
        for(const option of select.options) {
            if(!option.value) continue;
            option.disabled = !!driver && !(compatibleVehicles[driver] || []).includes(Number(option.value));
            if(option.disabled && option.selected) select.value='';
        }
    };
    assignment.querySelector('[name="driver_user_id"]').addEventListener('change', filterVehicles);
    filterVehicles();
    const sync = () => {
        overload.querySelector('.scheduling-driver').value = assignment.querySelector('[name="driver_user_id"]').value;
        overload.querySelector('.scheduling-vehicle').value = assignment.querySelector('[name="vehicle_id"]').value;
    };
    const preview = async () => {
        sync();
        const driver = assignment.querySelector('[name="driver_user_id"]').value;
        const vehicle = assignment.querySelector('[name="vehicle_id"]').value;
        const output = assignment.querySelector('.scheduling-preview');
        if (!driver || !vehicle) { output.textContent = ''; return; }
        const url = new URL(assignment.dataset.previewUrl, window.location.href);
        url.searchParams.set('driver_user_id', driver);
        url.searchParams.set('vehicle_id', vehicle);
        try {
            const response = await fetch(url, {headers: {'Accept': 'application/json'}});
            if (!response.ok) { const error=await response.json(); output.textContent = Object.values(error.errors || {}).flat().join(' ') || 'No se pudo validar la asignación.'; return; }
            const counts = await response.json();
            output.textContent = `Coincidencias: conductor ${counts.driver}, vehículo ${counts.vehicle}; límite ${counts.limit}. ` +
                (Math.max(counts.driver, counts.vehicle) > counts.limit ? 'Requiere autorización.' : Math.max(counts.driver, counts.vehicle) >= counts.limit - 1 ? 'Cerca del límite.' : 'Disponible.');
        } catch { output.textContent = 'No se pudo calcular el conflicto.'; }
    };
    assignment.addEventListener('change', preview);
    overload.addEventListener('submit', sync);
    sync();
}
</script>
@endsection
