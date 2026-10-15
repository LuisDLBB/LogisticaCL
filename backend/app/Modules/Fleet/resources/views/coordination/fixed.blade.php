@extends('fleet::layout')
@section('title', 'Retiros fijos recurrentes')
@section('content')
@php($baseFilters = array_filter(['fecha' => $selectedDate->toDateString(), 'buscar' => $filters['buscar'] ?? null]))
@php($dayNames = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'])
<header class="ret-fixed-header">
    <div><span class="ret-fixed-eyebrow">PLANIFICACIÓN BASE</span><h1>Retiros fijos recurrentes</h1><p>Administra los retiros habituales y pásalos a Solicitudes según la fecha.</p></div>
    <div class="ret-fixed-toolbar">
        @if($canEdit)<button type="button" class="ret-fixed-new" data-fixed-open="fixed-new">＋ Nuevo retiro fijo</button>@endif
        <form method="get" action="{{ route('fleet.page.coordination-fixed-pickups') }}"><label class="ret-visually-hidden" for="fixed-date">Fecha de retiro</label><input id="fixed-date" name="fecha" type="date" value="{{ $selectedDate->toDateString() }}" required onchange="this.form.requestSubmit()">@if(filled($filters['buscar'] ?? null))<input type="hidden" name="buscar" value="{{ $filters['buscar'] }}">@endif<noscript><button>Aplicar fecha</button></noscript></form>
        @if($canEdit)<button type="submit" form="convert-form" name="action" value="all" class="ret-fixed-generate" data-confirm-generate data-confirm="{{ $selectedDate->isToday() ? '¿Está seguro de que desea pasar los retiros fijos de hoy a Solicitudes para comenzar a agendar?' : '¿Está seguro de que desea pasar los retiros fijos del '.$selectedDate->format('d-m-Y').' a Solicitudes para comenzar a agendar?' }}" @disabled(($statusCounts['Habilitado'] ?? 0)===0)>↗ Generar solicitudes del día</button>@endif
    </div>
</header>
<p class="ret-fixed-day"><strong>{{ $dayNames[$selectedDate->dayOfWeekIso] }}:</strong> {{ $statusCounts['Habilitado'] ?? 0 }} retiro(s) fijo(s) por pasar a Solicitudes.<span>Solo se muestran los pendientes de esta fecha.</span></p>
<details class="ret-fixed-filters" @if(filled($filters['buscar'] ?? null)) open @endif><summary>Buscar · {{ $rows->count() }} pendientes</summary><form method="get" action="{{ route('fleet.page.coordination-fixed-pickups') }}" class="filter-grid"><input type="hidden" name="fecha" value="{{ $selectedDate->toDateString() }}"><label>Cliente, punto o dirección<input type="search" name="buscar" value="{{ $filters['buscar'] ?? '' }}" placeholder="Buscar retiro"></label><div class="filter-actions"><button>Buscar</button><a href="{{ route('fleet.page.coordination-fixed-pickups',['fecha'=>$selectedDate->toDateString()]) }}">Limpiar</a></div></form></details>
@if($canEdit)<form method="post" action="{{ route('fleet.fixed.convert') }}" id="convert-form">@csrf<input type="hidden" name="service_date" value="{{ $selectedDate->toDateString() }}">@foreach($packageDefaults as $id=>$amount)<input type="hidden" name="packages[{{ $id }}]" value="{{ $amount }}">@endforeach</form>@endif
<section class="panel ret-fixed-panel"><div class="table-wrap"><table class="ret-fixed-table"><thead><tr><th>Cliente / punto</th><th>Dirección</th><th>Días</th><th>Jornada</th><th>Material / bultos</th><th>Asignación habitual</th><th>Acciones</th></tr></thead><tbody>
@forelse($rows as $row)
@php($state=$statuses[$row->id])
<tr class="{{ $state==='Inactivo'?'ret-fixed-inactive':($state==='En solicitudes'?'ret-fixed-created':'') }}">
<td><strong>{{ $row->source_client_name }}</strong><small class="table-secondary">{{ $row->source_point_name }}</small></td>
<td>{{ $row->branch?->address ?: 'Dirección pendiente' }}@if($row->branch?->commune_name)<small class="table-secondary">{{ $row->branch->commune_name }}</small>@endif</td>
<td class="ret-fixed-days">{{ collect($row->weekdays)->map(fn($day)=>$dayNames[$day] ?? '?')->join(' · ') }}</td>
<td class="ret-fixed-window"><span class="ret-fixed-shift">{{ $row->shift }}</span> {{ substr($row->window_start,0,5) }}–{{ substr($row->window_end,0,5) }}</td>
<td>{{ $row->material }} · {{ $packageDefaults[$row->id] }}</td>
<td>{{ $usualDrivers[$row->usual_driver_user_id] ?? 'Sin conductor' }}<small class="table-secondary">{{ $usualVehicles[$row->usual_vehicle_id] ?? 'Sin patente' }}</small></td>
<td><div class="ret-fixed-actions">
@if($canEdit)<button type="button" class="ret-fixed-edit" data-fixed-open="fixed-edit-{{ $row->id }}" aria-label="Editar {{ $row->source_client_name }} · {{ $row->source_point_name }}" title="Editar retiro"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m16 3 5 5L9 20l-6 1 1-6L16 3Z M14 5l5 5"/></svg></button>@endif
@if($state==='En solicitudes')<span class="ret-fixed-done">✓ En solicitudes</span>@elseif($state==='Habilitado' && $canEdit)<button form="convert-form" type="submit" name="single_id" value="{{ $row->id }}" class="ret-fixed-pass">Pasar</button>@elseif($state!=='Inactivo')<span class="ret-fixed-state">{{ $state }}</span>@endif
@if($canEdit)<form method="post" action="{{ route('fleet.fixed.toggle',$row) }}">@csrf<button class="ret-fixed-toggle">{{ $row->is_active?'Desactivar':'Activar' }}</button></form>@endif
</div></td></tr>
@empty<tr><td colspan="7" class="empty-state">No hay retiros fijos pendientes de pasar a Solicitudes para esta fecha.</td></tr>@endforelse
</tbody></table></div></section>
<div class="ret-fixed-footer"><a href="{{ route('fleet.page.coordination-requests') }}">Ver solicitudes →</a>@if($canEdit && config('fleet.test_tools_enabled') && app()->environment(['local','testing']))@if(config('fleet.auto_test_mode'))<span class="muted">Las solicitudes se generarán como pruebas automáticamente.</span>@else<label><input form="convert-form" type="checkbox" name="is_test" value="1"> Generar como datos de prueba</label>@endif @endif</div>
@if($canEdit && $rows->isNotEmpty())

@foreach($rows as $row)
<dialog class="ret-fixed-dialog" id="fixed-edit-{{ $row->id }}" aria-labelledby="fixed-title-{{ $row->id }}"><button type="button" class="ret-fixed-close" data-fixed-close aria-label="Cerrar edición">×</button>
    <h2 id="fixed-title-{{ $row->id }}">{{ $row->source_client_name }} · {{ $row->source_point_name }}</h2><p class="muted">Los cambios del maestro no modifican las solicitudes ya creadas.</p>
    <form class="ret-form" method="post" action="{{ route('fleet.fixed.update', $row) }}">
        @csrf @method('PUT')<input type="hidden" name="_fixed_dialog" value="fixed-edit-{{ $row->id }}">
        <label>Días <span class="ret-required">*</span></label>
        @foreach([1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'] as $number=>$label)
            <label style="display:inline-block;margin-right:12px"><input type="checkbox" name="weekdays[]" value="{{ $number }}" @checked(in_array($number,$row->weekdays ?? [],true))> {{ $label }}</label>
        @endforeach
        <div class="grid">
            <label>Jornada<select name="shift" required><option @selected($row->shift==='AM')>AM</option><option @selected($row->shift==='PM')>PM</option><option @selected($row->shift==='AM/PM')>AM/PM</option></select></label>
            <label>Desde<input type="time" required name="window_start" value="{{ substr($row->window_start,0,5) }}"></label>
            <label>Hasta<input type="time" required name="window_end" value="{{ substr($row->window_end,0,5) }}"></label>
            <label>Material<input type="text" required name="material" value="{{ $row->material }}"></label>
            <label>Bultos habituales (opcional)<input type="number" min="1" name="usual_packages" value="{{ $row->usual_packages }}"></label>
        </div>
        @include('fleet::coordination.fixed-extra',['fixed'=>$row])
        <p><button type="submit">Guardar retiro fijo</button></p>
    </form>
    <form method="post" action="{{ route('fleet.fixed.toggle', $row) }}">@csrf<button type="submit" class="button-secondary">{{ $row->is_active ? 'Desactivar' : 'Activar' }}</button></form>
    @if($row->branch)
        <h3>Punto {{ $row->branch->name }} @if($row->branch->address_status !== 'ready') · Pendiente completar @endif</h3>
        <form class="ret-form" method="post" action="{{ route('fleet.points.update', $row->branch) }}">
            @csrf @method('PUT')<input type="hidden" name="_fixed_dialog" value="fixed-edit-{{ $row->id }}">
            <div class="grid">
                <label>Dirección real<input type="text" name="address" value="{{ $row->branch->address }}" required></label>
                <label>Comuna<input type="text" name="commune_name" value="{{ $row->branch->commune_name }}" required></label>
                <label>Correo operacional (separar varios con ;)<input type="text" name="operational_emails" value="{{ $row->branch->operational_emails }}"></label>
            </div>
            <p><button type="submit">Actualizar punto</button></p>
        </form>
    @else<p class="errors">Punto pendiente de asociación: requiere la dirección confirmada de Orquídea.</p>@endif
</dialog>
@endforeach

@endif

@if($canEdit)
<dialog class="ret-fixed-dialog" id="fixed-new" aria-labelledby="fixed-new-title"><button type="button" class="ret-fixed-close" data-fixed-close aria-label="Cerrar nuevo retiro">×</button><h2 id="fixed-new-title">Nuevo retiro fijo</h2>@include('fleet::coordination.fixed-create')</dialog>
<script>
document.querySelectorAll('[data-fixed-open]').forEach(button=>button.addEventListener('click',()=>document.getElementById(button.dataset.fixedOpen).showModal()));
document.querySelectorAll('[data-fixed-close]').forEach(button=>button.addEventListener('click',()=>button.closest('dialog').close()));
document.querySelector('[data-confirm-generate]')?.addEventListener('click',event=>{
    if(!window.confirm(event.currentTarget.dataset.confirm))event.preventDefault();
});
@if($errors->any())
const retryDialog=document.getElementById(@json(old('_fixed_dialog')));
if(retryDialog?.matches('.ret-fixed-dialog')){
    const values=@json(session()->getOldInput());
    retryDialog.querySelectorAll('input,select,textarea').forEach(control=>{
        const key=control.name.replace(/\[\]$/,'');
        if(!(key in values)||['_token','_method','_fixed_dialog'].includes(key))return;
        if(control.type==='checkbox')control.checked=Array.isArray(values[key])&&values[key].map(String).includes(control.value);
        else control.value=values[key]??'';
    });
    if(retryDialog.id==='fixed-new')fixedPoints();
    retryDialog.showModal();
}
@endif
</script>
@endif
@endsection
