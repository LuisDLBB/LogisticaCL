@extends('fleet::layout')
@section('title','Excepciones de horario')
@section('content')
@php($fleetTenant=app(\App\Fleet\FleetAccess::class)->tenant(request()))
<h1>Excepciones de horario</h1><p>Retiros del mismo día solicitados después de las {{ app(\App\Fleet\LatePickupAuthorization::class)->cutoff($fleetTenant->id) }}. Operaciones y el gerente comercial deben registrar su aprobación; cualquier rechazo bloquea ese día.</p>
<section class="panel"><div class="table-wrap"><table><thead><tr><th>Solicitud</th><th>Comerciante / punto</th><th>Fecha de excepción</th><th>Operaciones</th><th>Gerente comercial</th><th>Acción</th></tr></thead><tbody>
@forelse($rows as $row)
<tr><td>{{ $row->ret_code }}</td><td>{{ $row->client_name_snapshot }} · {{ $row->point_name_snapshot }}</td><td>{{ $row->late_pickup_authorization['date'] }} @if($row->late_pickup_authorization['date']!==now('America/Santiago')->toDateString()) · Vencida @endif</td>
@foreach(['operations','commercial'] as $area)<td>{{ ['pending'=>'Pendiente','approved'=>'Aprobado','rejected'=>'Rechazado'][$row->late_pickup_authorization[$area]['status']] }}</td>@endforeach
<td>@if($canReview)<a class="button" href="{{ route('fleet.requests.show',$row) }}">Revisar</a>@endif
@if((int)($row->late_pickup_authorization['revision']??0)!==$policyRevision)<p>Requiere renovar la excepción por cambio de parámetros.</p>
@elseif($row->late_pickup_authorization['date']===now('America/Santiago')->toDateString() && !in_array('rejected',[$row->late_pickup_authorization['operations']['status'],$row->late_pickup_authorization['commercial']['status']]))
@foreach($lateAreas as $area)@if($row->late_pickup_authorization[$area]['status']==='pending')
<details><summary>Decidir {{ $area==='operations'?'Operaciones':'Gerencia comercial' }}</summary><p>{{ $row->late_pickup_authorization['reason'] }}</p><form method="post" action="{{ route('fleet.requests.late.decide',$row) }}" class="ret-form">@csrf<input type="hidden" name="area" value="{{ $area }}"><label>Motivo de la decisión<textarea name="notes" required maxlength="2000"></textarea></label><button name="decision" value="approved">Aprobar</button><button name="decision" value="rejected">Rechazar</button></form></details>
@endif @endforeach
@endif
</td></tr>
@empty<tr><td colspan="6">No hay excepciones pendientes de programación.</td></tr>@endforelse
</tbody></table></div></section>{{ $rows->links('fleet::pagination') }}
@endsection
