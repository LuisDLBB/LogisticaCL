@extends('fleet::layout')
@section('title', 'RET-FAC-'.$feasibility->id)
@section('content')
@php($labels = \App\Http\Controllers\Fleet\RetFeasibilityController::STATES)
<p><a href="{{ route('fleet.page.coordination-feasibility') }}">← Factibilidad</a></p>
<h1>RET-FAC-{{ $feasibility->id }} · {{ $feasibility->client->commercial_name }}</h1><p class="tag">{{ $labels[$feasibility->status] }}</p>
<section class="card"><h2>Solicitud y datos de la carga</h2><div class="grid">@foreach(\App\Http\Controllers\Fleet\RetFeasibilityController::REQUEST_FIELDS as $key=>$label)<p><strong>{{ $label }}:</strong> {{ $feasibility->request_data[$key] ?? 'Sin informar' }}</p>@endforeach</div><p>Revisión de Finanzas: {{ $feasibility->requires_finance ? 'Sí' : 'No' }}</p></section>
@foreach(['operations_data'=>'Evaluación operativa','finance_data'=>'Valorización financiera'] as $field=>$heading)
@if($feasibility->$field)<section class="card"><h2>{{ $heading }}</h2>@foreach($feasibility->$field as $key=>$value)<p><strong>{{ \App\Http\Controllers\Fleet\RetFeasibilityController::OPERATION_FIELDS[$key] ?? \App\Http\Controllers\Fleet\RetFeasibilityController::COSTS[$key] ?? ['result'=>'Resultado','allowances'=>'Viáticos','reason'=>'Justificación','reason_code'=>'Motivo'][$key] ?? $key }}:</strong> {{ $value }}</p>@endforeach</section>@endif
@endforeach
@php($cost = $feasibility->requires_finance ? ($feasibility->finance_data ? collect($feasibility->finance_data)->only(array_keys(\App\Http\Controllers\Fleet\RetFeasibilityController::COSTS))->sum() : null) : ($feasibility->operations_data['estimated_cost'] ?? null))
<div class="card"><strong>Costo estimado:</strong> {{ $cost === null ? 'Pendiente' : number_format($cost,0,',','.').' CLP' }} · <strong>Precio:</strong> {{ $feasibility->proposed_price ?? 'Pendiente' }}
@if($cost !== null && $feasibility->proposed_price > 0)<p>Margen: {{ number_format($feasibility->proposed_price - $cost,0,',','.') }} CLP ({{ number_format(($feasibility->proposed_price-$cost)/$feasibility->proposed_price*100,1,',','.') }}%)</p>@endif</div>
@php($scope = ['operations'=>'operations.feasibility','finance'=>'finance.feasibility','cost_validation'=>'finance.feasibility.validate','commercial'=>'commercial.feasibility','approval'=>'commercial.feasibility.approve','approved'=>'coordination.requests'][$feasibility->status] ?? null)
@if($scope && ($permissions[$scope] ?? false))
<form class="card ret-form" method="post" action="{{ route('fleet.feasibility.act',$feasibility) }}">@csrf
<h2>{{ $labels[$feasibility->status] }}</h2>
@if($feasibility->status==='operations')
@include('fleet::feasibility.fields',['fields'=>\App\Http\Controllers\Fleet\RetFeasibilityController::OPERATION_FIELDS,'values'=>[]])
<label>Viáticos<select name="allowances" required><option value="">Seleccionar</option><option value="0">No</option><option value="1">Sí</option></select></label>
<label>Resultado operativo<select name="result" required><option>Factible</option><option>Factible con condiciones</option><option>No factible</option></select></label>
<label>Motivo del resultado<select name="reason_code"><option value="">Seleccionar si corresponde</option>@foreach(\App\Http\Controllers\Fleet\RetFeasibilityController::REASONS as $reason)<option>{{ $reason }}</option>@endforeach</select></label>
@elseif($feasibility->status==='finance')
<p>Montos en CLP. Escribe 0 únicamente cuando confirmes que ese costo no aplica.</p>
@include('fleet::feasibility.fields',['fields'=>\App\Http\Controllers\Fleet\RetFeasibilityController::COSTS,'values'=>[],'costFields'=>true])
@elseif(in_array($feasibility->status,['commercial','approval']))
<label>Precio propuesto (CLP)<input type="number" name="proposed_price" min="0.01" step="0.01" value="{{ $feasibility->proposed_price }}" required></label>
@elseif($feasibility->status==='approved')
<p>Confirma los datos concretos del retiro. Solo se creará una solicitud vinculada a este expediente.</p>
<label>Punto de retiro<select name="client_branch_id" required><option value="">Seleccionar</option>@foreach($points as $point)<option value="{{ $point->id }}" @disabled($point->address_status!=='ready')>{{ $point->name }} · {{ $point->address }}</option>@endforeach</select></label>
@include('fleet::feasibility.fields',['fields'=>array_intersect_key(\App\Http\Controllers\Fleet\RetFeasibilityController::REQUEST_FIELDS,array_flip(['service_date','window_start','window_end','packages','material'])),'values'=>$feasibility->request_data])
<label>Jornada<select name="shift"><option>AM</option><option>PM</option></select></label>
@endif
<label>Justificación / comentarios<textarea name="reason" required rows="3"></textarea></label>
<div class="ret-actions"><button name="action" value="{{ ['cost_validation'=>'validate','approval'=>'approve','approved'=>'convert'][$feasibility->status] ?? $feasibility->status }}">{{ $feasibility->status==='approved' ? 'Pasar a Coordinación' : 'Confirmar etapa' }}</button></div>
</form>
@if($feasibility->status!=='approved')
<form class="card" method="post" action="{{ route('fleet.feasibility.act',$feasibility) }}">@csrf<label>Motivo de rechazo o corrección<textarea name="reason" required></textarea></label><div class="ret-actions"><button class="button-secondary" name="action" value="correction">Solicitar corrección</button><button class="button-secondary" name="action" value="reject">Rechazar</button></div></form>
@endif
@endif
@if($feasibility->status==='correction' && $canCreate)
<form class="card ret-form" method="post" action="{{ route('fleet.feasibility.act',$feasibility) }}">@csrf<input type="hidden" name="action" value="resubmit"><h2>Corregir y reenviar</h2>
@include('fleet::feasibility.fields',['fields'=>\App\Http\Controllers\Fleet\RetFeasibilityController::REQUEST_FIELDS,'values'=>$feasibility->request_data])
<label>¿Requiere Finanzas?<select name="requires_finance"><option value="1" @selected($feasibility->requires_finance)>Sí</option><option value="0" @selected(!$feasibility->requires_finance)>No</option></select></label><label>Justificación<textarea name="reason" required></textarea></label><p><button>Reenviar a Operaciones</button></p></form>
@endif
@if($feasibility->service_request_id)<p><a class="button" href="{{ route('fleet.requests.show',$feasibility->service_request_id) }}">Ver solicitud vinculada</a></p>@endif
<section class="card"><h2>Historial y estimaciones originales</h2>@foreach($events as $event)<details><summary>{{ $event->created_at }} · {{ $event->action }} · Usuario {{ $event->actor_user_id }}</summary><pre class="ret-history">{{ json_encode(json_decode($event->details),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>@endforeach</section>
@endsection
