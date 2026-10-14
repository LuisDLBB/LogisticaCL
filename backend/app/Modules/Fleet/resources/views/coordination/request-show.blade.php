@extends('fleet::layout')
@section('title', $serviceRequest->ret_code)
@section('content')
<p><a href="{{ route('fleet.page.coordination-requests') }}">← Solicitudes</a></p>
<h1>{{ $serviceRequest->ret_code }}</h1>
<p><span class="tag">{{ $serviceRequest->status }}</span> @if(!$serviceRequest->operational_emails_snapshot)<span class="tag off">Correo operacional pendiente</span>@endif</p>
<div class="grid">
    <div class="card"><h2>Origen inmutable</h2><p><strong>Empresa:</strong> {{ $serviceRequest->client->tenant?->name }}</p><p><strong>Cliente:</strong> {{ $serviceRequest->client_name_snapshot }}</p><p><strong>Razón social:</strong> {{ $serviceRequest->legal_name_snapshot }}</p><p><strong>Punto:</strong> {{ $serviceRequest->point_name_snapshot }}</p><p><strong>Fecha solicitada:</strong> {{ $serviceRequest->requested_date_original->format('d-m-Y') }}</p><p><strong>Retiro fijo:</strong> {{ $serviceRequest->fixedPickup?->id ?? 'Solicitud manual' }}</p></div>
    <div class="card"><h2>Agenda vigente</h2><p><strong>Fecha:</strong> {{ $serviceRequest->service_date->format('d-m-Y') }}</p><p><strong>Ventana:</strong> {{ $serviceRequest->shift }} · {{ substr($serviceRequest->window_start,0,5) }}–{{ substr($serviceRequest->window_end,0,5) }}</p><p><strong>Dirección efectiva:</strong> {{ $serviceRequest->effective_address }}</p><p><strong>Dirección original del punto:</strong> {{ $serviceRequest->address_snapshot }}</p><p><strong>Bultos:</strong> {{ $serviceRequest->packages }} de {{ $serviceRequest->material }}</p><p><strong>Correo guardado:</strong> {{ $serviceRequest->operational_emails_snapshot ?: 'Pendiente' }}</p></div>
</div>
@if($serviceRequest->address_override_reason)<div class="card"><strong>Dirección excepcional:</strong> {{ $serviceRequest->address_override_reason }}</div>@endif
@if($canEdit && $serviceRequest->status==='requested')
<form method="post" action="{{ route('fleet.requests.schedule', $serviceRequest) }}" class="card">@csrf<button>Marcar Agendado</button><p class="muted">Esta fase no asigna conductor ni patente y no envía confirmaciones.</p></form>
@endif
@if($canReprogram && in_array($serviceRequest->status,['scheduled','rescheduled'],true))
<form method="post" action="{{ route('fleet.requests.reprogram', $serviceRequest) }}" class="card">
    @csrf<h2>Solicitar reprogramación a Postventa</h2>
    <div class="grid"><div><label>Fecha propuesta</label><input type="date" name="proposed_date" required></div><div><label>Desde</label><input type="time" name="proposed_window_start" required></div><div><label>Hasta</label><input type="time" name="proposed_window_end" required></div></div>
    <label>Motivo</label><textarea name="reason" rows="3" required></textarea><p><button>Solicitar autorización</button></p>
</form>
@endif
@if($canEdit && in_array($serviceRequest->status,['scheduled','rescheduled'],true))
<form method="post" action="{{ route('fleet.requests.retire', $serviceRequest) }}" class="card">@csrf<button>Registrar Retirado manualmente</button></form>
@endif
@if($canApprove && $serviceRequest->status==='reprogramming_pending')<p><a class="button" href="{{ route('fleet.reprogramming.index') }}">Resolver en Postventa</a></p>@endif
<div class="card"><h2>Historial</h2><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Acción</th><th>Usuario</th><th>Detalle</th></tr></thead><tbody>@foreach($events as $event)<tr><td>{{ $event->created_at }}</td><td>{{ $event->action }}</td><td>{{ $event->actor_user_id }}</td><td>{{ $event->details }}</td></tr>@endforeach</tbody></table></div></div>
@endsection
