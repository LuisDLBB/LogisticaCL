@extends('fleet::layout')
@section('title','Solicitudes y agenda de retiros')
@section('content')
<div class="ret-requests-compact">
@if($myCoordinationTasks->isNotEmpty())<section class="ret-coordination-strip" aria-label="Mis coordinaciones con clientes"><strong>Mis coordinaciones con clientes · {{ $myCoordinationTasks->count() }}</strong><div class="ret-coordination-links">@foreach($myCoordinationTasks as $task)<a href="{{ route('fleet.requests.show',['serviceRequest'=>$task->service_request_id,'vista'=>'solicitante']) }}" data-ret-modal><strong>{{ $task->ret_code }}</strong> · {{ $task->client_name_snapshot }} · {{ $task->reason }}</a>@endforeach</div></section>@endif

@php($selected=$filters['estado'] ?? '')
@php($baseFilters=collect($filters)->except('estado', 'ret')->filter(fn($v)=>filled($v))->all())
<header class="ret-fixed-header ret-requests-header">
<div class="ret-requests-header-top">
<div class="ret-requests-intro"><span class="ret-fixed-eyebrow">FLUJO COMERCIAL → OPERACIONES</span><h1>Solicitudes y agenda de retiros</h1><p>Operaciones programa y gestiona cambios de fecha con autorización.</p></div>
<form method="get" id="ret-autofilter" class="ret-header-filters" aria-label="Filtrar solicitudes"><input type="hidden" name="estado" value="{{ $selected }}"><label>Fecha vigente<input type="date" name="fecha" value="{{ $filters['fecha']??'' }}"></label><label>Comerciante<select name="client_id"><option value="">Todos</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(($filters['client_id']??'')==$client->id)>{{ $client->commercial_name }}</option>@endforeach</select></label><a href="{{ route('fleet.page.coordination-requests') }}">Limpiar</a><span id="ret-filter-status" class="ret-visually-hidden" role="status" aria-live="polite"></span></form>
@if($canCreate)<a class="button ret-fixed-new" data-ret-modal href="{{ route('fleet.requests.create') }}">＋ Nueva solicitud</a>@endif</div>
<nav class="ret-state-panel" aria-label="Filtrar solicitudes por estado">
@foreach(['pending'=>'Pendientes de programar','reprogramming_pending'=>'Por reprogramar','agenda'=>'Agendados / reagendados','retired'=>'Retirado','cancelled'=>'Anulado',''=>'Todos'] as $code=>$label)
@php($count=match($code){'pending'=>($statusCounts['requested']??0)+($statusCounts['correction_requested']??0),'agenda'=>($statusCounts['scheduled']??0)+($statusCounts['rescheduled']??0),''=>$statusCounts->sum(),default=>$statusCounts[$code]??0})
<a href="{{ route('fleet.page.coordination-requests',$baseFilters+['estado'=>$code]) }}" @if($selected===$code) aria-current="page" @endif><span>{{ $label }}</span><b>{{ $count }}</b></a>@endforeach</nav></header>
<div class="ret-board-content"><p class="ret-agenda-notice"><strong>Control de agenda:</strong> la fecha solicitada nunca se reemplaza. Cambios y autorizaciones quedan en el historial.</p>

<div class="ret-customer-legend" aria-label="Avisos del cliente"><span class="ret-client-cancel">Rojo: cancelado por el cliente</span><span class="ret-client-correction">Amarillo: corrección pendiente del cliente</span></div>
<div id="ret-results"><section class="panel ret-requests-panel"><div class="table-wrap"><table class="ret-requests-table"><thead><tr><th>ID</th><th>Fechas del retiro</th><th>Cliente / dirección</th><th>Carga</th><th>Estado</th><th>Asignación</th><th>Ingreso / aviso</th></tr></thead><tbody>@forelse($rows as $row)
@php($days=(int)now('America/Santiago')->startOfDay()->diffInDays(\Carbon\Carbon::parse($row->service_date->format('Y-m-d'),'America/Santiago')->startOfDay(),false))
@php($clientCancelled=$row->status==='cancelled' && $customerCancellations->has($row->id))
@php($clientCorrection=!in_array($row->status,['cancelled','retired'],true) ? $customerCorrections->get($row->id) : null)
<tr class="clickable-row {{ $clientCancelled ? 'ret-customer-cancelled' : ($clientCorrection ? 'ret-customer-correction' : '') }}" tabindex="0" data-ret-modal data-href="{{ route('fleet.requests.show',$row) }}" aria-label="Revisar {{ $row->ret_code }}"><td data-label="Retiro"><a data-ret-modal href="{{ route('fleet.requests.show',$row) }}"><strong>{{ $row->ret_code }}</strong></a></td>
<td data-label="Fecha de retiro"><small class="ret-date-label">▣ SOLICITADA</small><strong>{{ $row->requested_date_original->locale('es')->translatedFormat('D, d M Y') }}</strong>@if(!$row->service_date->isSameDay($row->requested_date_original))<small class="table-secondary">Agenda: {{ $row->service_date->format('d-m-Y') }}</small>@endif
@if(!in_array($row->status,['retired','cancelled']))<span class="tag {{ $days<0?'ret-late':'' }}">{{ $days===0?'Hoy':($days===1?'Mañana':($days<0?'Atrasado '.abs($days).' d':'En '.$days.' d')) }}</span>@endif<small class="table-secondary">{{ $row->pickup_kind ?: ($row->fixed_pickup_id?'Fijo':'Spot') }} · {{ $row->requesting_area ?: 'Coordinación' }}</small></td>
<td data-label="Cliente / dirección"><strong>{{ $row->client_name_snapshot }} · {{ $row->serviceType?->name }}</strong><small class="table-secondary">{{ $row->point_name_snapshot }} · {{ $row->effective_address }}@if($row->commune_snapshot), {{ $row->commune_snapshot }}@endif</small><small class="table-secondary">{{ $row->pickup_name }}</small></td>
<td data-label="Carga / horario"><strong>{{ $row->status === 'cancelled' ? 0 : $row->packages }} bultos</strong><small class="table-secondary">{{ substr($row->window_start,0,5) }}–{{ substr($row->window_end,0,5) }}</small></td>
<td data-label="Estado">@if($clientCancelled)<span class="tag ret-client-cancel">Cancelado por el cliente</span><small class="ret-customer-message"><strong>Motivo:</strong> {{ $row->cancellation_reason }}</small>@elseif($clientCorrection)<span class="tag ret-client-correction">Cliente solicita corrección</span><small class="ret-customer-message"><strong>Motivo:</strong> {{ $clientCorrection->reason }}<br><strong>Coordina:</strong> {{ $clientCorrection->assigned_name ?: 'Solicitante' }}</small><small class="table-secondary">Agenda: {{ ['requested'=>'Solicitado','scheduled'=>'Agendado','rescheduled'=>'Reagendado','reprogramming_pending'=>'Por reprogramar','correction_requested'=>'Corrección solicitada'][$row->status] ?? $row->status }}</small>@else<span class="tag">{{ ['requested'=>'Solicitado','correction_requested'=>'Corrección solicitada','scheduled'=>'Agendado','rescheduled'=>'Reagendado','reprogramming_pending'=>'Por reprogramar','retired'=>'Retirado','cancelled'=>'Anulado'][$row->status] ?? $row->status }}</span>@endif @if($row->late_pickup_authorization && !in_array($row->status,['scheduled','rescheduled','retired','cancelled']))<small class="table-secondary">Excepción de horario · {{ in_array('rejected',array_column([$row->late_pickup_authorization['operations'],$row->late_pickup_authorization['commercial']], 'status')) ? 'Rechazada' : (($row->late_pickup_authorization['operations']['status']==='approved' && $row->late_pickup_authorization['commercial']['status']==='approved') ? 'Aprobada por ambas áreas' : 'Pendiente de autorización') }}</small>@endif</td>
<td data-label="Asignación"><strong>{{ $row->driver?->name ?: 'Sin asignar' }}</strong><small class="table-secondary">{{ $row->vehicle?->plate }} · {{ $row->shift }}</small></td>
<td data-label="Ingreso / aviso"><strong>{{ $row->requester?->name }}</strong><small class="table-secondary">{{ $row->created_at->timezone('America/Santiago')->format('d-m-Y H:i') }}</small><small class="table-secondary">{{ $row->email_status==='prepared'?'Aviso preparado':'Sin aviso preparado' }}</small></td></tr>
@empty<tr><td colspan="7" class="empty-state ret-requests-empty"><svg viewBox="0 0 32 32" aria-hidden="true"><path d="m16 3 11 6-11 6L5 9l11-6Zm-11 9 11 6 11-6M5 17l11 6 11-6M5 12v11l11 6 11-6V12M16 18v11"/></svg><p>No hay solicitudes en esta vista.</p></td></tr>@endforelse</tbody></table></div></section>{{ $rows->links('fleet::pagination') }}</div><p class="ret-requests-footer"><a href="{{ route('fleet.page.coordination-fixed-pickups') }}">Ver retiros fijos recurrentes →</a></p></div>
<script>
const autoFilter=document.getElementById('ret-autofilter');
let filterController,filterGeneration=0;
async function filterRequests(){
    const run=++filterGeneration;filterController?.abort();filterController=new AbortController();
    const url=new URL(location.href);url.search=new URLSearchParams(new FormData(autoFilter)).toString();
    const status=document.getElementById('ret-filter-status');status.textContent='Buscando…';
    document.getElementById('ret-results').setAttribute('aria-busy','true');
    try{
        const response=await fetch(url,{signal:filterController.signal,headers:{Accept:'text/html'}});
        if(!response.ok)throw new Error('filter');
        const doc=new DOMParser().parseFromString(await response.text(),'text/html');
        if(run!==filterGeneration)return;
        const results=doc.getElementById('ret-results'),states=doc.querySelector('.ret-state-panel');
        if(!results||!states)throw new Error('filter');
        document.getElementById('ret-results').replaceChildren(...results.childNodes);
        document.querySelector('.ret-state-panel').replaceChildren(...states.childNodes);
        history.replaceState(null,'',url);status.textContent='Resultados actualizados.';
    }catch(error){if(error.name!=='AbortError'&&run===filterGeneration)status.textContent='No se pudo actualizar. Vuelve a escribir o cambiar un filtro para reintentar.';}
    finally{if(run===filterGeneration)document.getElementById('ret-results').removeAttribute('aria-busy');}
}
autoFilter.addEventListener('submit',event=>{event.preventDefault();filterRequests();});
autoFilter.addEventListener('change',filterRequests);
</script>
</div>
@endsection
