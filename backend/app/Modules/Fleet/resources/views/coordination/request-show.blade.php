@extends('fleet::layout')
@section('title','Revisar '.$serviceRequest->ret_code)
@push('styles')
<style>#ret-change-dialog{padding:20px;max-height:88vh}#ret-change-dialog .ret-form{gap:12px}#ret-change-dialog .grid{grid-template-columns:1.3fr 1fr 1fr;gap:12px}#ret-change-dialog .ret-form p{margin:4px 0}#ret-change-dialog>p{margin:12px 0 18px}@media(max-width:600px){#ret-change-dialog .grid{grid-template-columns:1fr}}.ret-original{padding:14px!important}.ret-original .page-heading{margin-bottom:12px!important}.ret-original .ret-facts{gap:7px!important}.ret-original .ret-facts>div{padding:9px 11px!important;min-height:0!important}.ret-original h3{margin:14px 0 8px!important}.ret-original .ret-facts strong{line-height:1.35}.ret-review-original>.muted{margin:8px 0}.ret-task-form .grid{grid-template-columns:repeat(3,minmax(0,1fr))}@media(max-width:700px){.ret-task-form .grid{grid-template-columns:1fr}}.ret-requester-review .ret-review-grid{grid-template-columns:1fr}.ret-requester-review .ret-facts{grid-template-columns:repeat(3,minmax(0,1fr))}.ret-requester-review .ret-review-agenda:empty{display:none}@media(max-width:700px){.ret-requester-review .ret-facts{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:420px){.ret-requester-review .ret-facts{grid-template-columns:1fr}}</style>
@endpush
@section('content')
@php($fleetTenant=app(\App\Fleet\FleetAccess::class)->tenant(request()))
@php($lateIsCurrent=$lateAuthorization && app(\App\Fleet\LatePickupAuthorization::class)->current($serviceRequest))
@php($original=$serviceRequest->original_data ?? [])
@php($originalValue=fn(string $key)=>array_key_exists($key,$original)?$original[$key]:$serviceRequest->$key)
@php($stateLabel=['requested'=>'Solicitado','correction_requested'=>'Corrección solicitada','scheduled'=>'Agendado','rescheduled'=>'Reagendado','reprogramming_pending'=>'Por reprogramar','retired'=>'Retirado','cancelled'=>'Anulado'][$serviceRequest->status] ?? $serviceRequest->status)
<div class="ret-window ret-review {{ $requesterView ? 'ret-requester-review' : '' }}"><div class="page-heading ret-review-heading"><h1>{{ $requesterView ? 'Tu solicitud de retiro' : 'Revisar' }} {{ $serviceRequest->ret_code }}</h1><span class="tag">{{ $stateLabel }}</span>@if($requesterView && $canEdit && in_array($serviceRequest->status,['requested','correction_requested'],true))<button type="button" class="ret-change-open" data-open-change>Editar solicitud</button>@endif<a class="ret-standalone-back" href="{{ route('fleet.page.coordination-requests') }}">← Solicitudes</a></div>
@if($requesterView)<p class="ret-agenda-notice"><strong>Tu solicitud ya fue creada bajo el código {{ $serviceRequest->ret_code }}.</strong> @if(in_array($serviceRequest->status,['requested','correction_requested'],true)){{ $lateAuthorization ? 'Está pendiente de las aprobaciones por excepción de horario y de programación por Operaciones.' : 'Está pendiente de programación por Operaciones.' }} Revisa los antecedentes. Si detectas un error, utiliza Editar solicitud.@else Puedes revisar los antecedentes y el estado de tu retiro.@endif</p>@endif
@if($cancelledByCustomer)<div class="ret-customer-alert" role="status"><strong>Cancelado por el cliente</strong><p><strong>Motivo:</strong> {{ $serviceRequest->cancellation_reason }}</p><small>Este retiro no debe realizarse. {{ $serviceRequest->fixed_pickup_id ? 'La programación fija se mantiene.' : '' }}</small></div>@endif
@foreach($coordinationTasks as $task)
<section class="card {{ $task->kind==='correction' ? 'ret-customer-alert ret-correction-alert' : '' }}"><h2>{{ $task->kind==='correction' ? 'Cliente solicita corrección' : 'Coordinación con el cliente' }}</h2><p><strong>Responsable:</strong> {{ $task->assigned_name ?: 'Solicitante pendiente de identificar' }} · {{ $task->kind==='reschedule' ? 'Reagendamiento solicitado por Operaciones' : 'Corrección solicitada por el cliente' }}</p><p>{{ $task->reason }}</p>
@if($task->proposed_date)<p><strong>Propuesta:</strong> {{ \Carbon\Carbon::parse($task->proposed_date)->format('d-m-Y') }} · {{ substr($task->proposed_start,0,5) }}–{{ substr($task->proposed_end,0,5) }}. Coordina primero con el cliente.</p>@endif
@if($canEdit && ($task->assigned_to_user_id===auth()->id() || $canResolveTasks))
<form method="post" action="{{ route('fleet.requests.task.resolve',[$serviceRequest,$task->id]) }}" class="ret-form ret-task-form">@csrf
@if($task->kind==='reschedule')<div class="grid"><label>Fecha confirmada por el cliente<input type="date" name="service_date" value="{{ $task->proposed_date }}" min="{{ now('America/Santiago')->toDateString() }}"></label><label>Desde<input type="time" name="window_start" value="{{ substr($task->proposed_start,0,5) }}"></label><label>Hasta<input type="time" name="window_end" value="{{ substr($task->proposed_end,0,5) }}"></label></div>@endif
<label>Respuesta del cliente<textarea name="customer_response" rows="2" required maxlength="2000"></textarea></label><p><button name="decision" value="confirmed">{{ $task->kind==='reschedule' ? 'Confirmar y devolver a Operaciones' : 'Registrar coordinación completada' }}</button> <button class="secondary" name="decision" value="rejected">{{ $task->kind==='reschedule' ? 'Mantener la agenda vigente' : 'Cerrar coordinación' }}</button></p></form>
@else<p>Esta coordinación debe responderla quien ingresó la solicitud o un administrador.</p>@endif</section>
@endforeach
<div class="ret-review-grid"><div class="ret-review-original"><section class="ret-original"><div class="page-heading"><h2>♙ Antecedentes originales protegidos</h2><span class="tag">{{ $stateLabel }}</span></div><div class="ret-facts">
<div><small>Cliente</small><strong>{{ $serviceRequest->client_name_snapshot }}</strong><small>{{ $serviceRequest->legal_name_snapshot }}</small></div>
<div><small>Fecha de retiro</small><strong>{{ $serviceRequest->requested_date_original->locale('es')->translatedFormat('D, d M Y') }}</strong></div>
<div><small>Horario de retiro</small><strong>{{ isset($original['window_start']) ? substr($original['window_start'],0,5).'–'.substr($original['window_end'],0,5) : 'No registrado históricamente' }}</strong></div>
<div><small>Carga original</small><strong>{{ isset($original['packages']) ? $original['packages'].' bultos' : 'No registrada históricamente' }}</strong></div>
<div><small>Tipo de bultos</small><strong>{{ ($original['load_unit'] ?? $serviceRequest->load_unit) === 'pallets' ? 'Pallets' : 'Bultos sueltos' }}</strong>@if($original['pallet_type'] ?? $serviceRequest->pallet_type)<small>{{ ($original['pallet_type'] ?? $serviceRequest->pallet_type) === 'european' ? 'Europeo · 120 × 80' : 'Americano · 120 × 100' }} × {{ $original['pallet_height_cm'] ?? $serviceRequest->pallet_height_cm }} cm</small>@endif</div>
<div><small>Dirección de retiro</small><strong>{{ $serviceRequest->point_name_snapshot }} · {{ $original['effective_address'] ?? $serviceRequest->address_snapshot }}</strong></div>
<div><small>Comuna de retiro</small><strong>{{ $originalValue('commune_snapshot') ?: 'Sin informar' }}</strong></div>
<div><small>Área / tipo</small><strong>{{ $original['requesting_area'] ?? $serviceRequest->requesting_area ?? 'Coordinación' }} · {{ $original['pickup_kind'] ?? $serviceRequest->pickup_kind ?? ($serviceRequest->fixed_pickup_id?'Fijo':'Variable') }}</strong></div></div><h3>Contacto e instrucciones del retiro</h3><div class="ret-facts ret-contact-facts">
<div><small>Contacto</small><strong>{{ $originalValue('contact_name_snapshot') ?: 'Sin informar' }}</strong></div>
<div><small>Teléfono</small><strong>{{ $originalValue('contact_phone_snapshot') ?: 'Sin informar' }}</strong></div>
<div><small>Correo para aviso</small><strong>{{ $originalValue('operational_emails_snapshot') ?: 'Correo operacional pendiente' }}</strong></div>
<div><small>Comuna</small><strong>{{ $originalValue('commune_snapshot') ?: 'Sin informar' }}</strong></div>
<div><small>Peso / volumen informado</small><strong>{{ $originalValue('requested_weight_kg') ?? 'Sin informar' }} kg · {{ $originalValue('requested_volume_m3') ?? 'Sin informar' }} m³</strong></div>
<div class="ret-fact-wide"><small>Referencia de acceso e instrucciones</small><strong>{{ $originalValue('access_reference_snapshot') ?: 'Sin instrucciones adicionales' }}</strong></div>
@if($originalValue('notes'))<div class="ret-fact-wide"><small>Detalle de la carga / observaciones</small><strong>{{ $originalValue('notes') }}</strong></div>@endif
</div></section>
<p class="muted">{{ $serviceRequest->pickup_name }} @if($serviceRequest->is_test) · Dato de prueba @endif</p>
<p class="ret-agenda-notice"><strong>Datos vigentes:</strong> {{ $serviceRequest->status === 'cancelled' ? 0 : $serviceRequest->packages }} bultos · {{ $serviceRequest->effective_address }} · {{ substr($serviceRequest->window_start,0,5) }}–{{ substr($serviceRequest->window_end,0,5) }}. Volumen: {{ $serviceRequest->requested_volume_m3 ?? 'Sin informar' }} m³ · Peso: {{ $serviceRequest->requested_weight_kg ?? 'Sin informar' }} kg.</p>
@if(!$serviceRequest->operational_emails_snapshot)<p class="notice">Correo operacional pendiente. Completa los destinatarios en la hoja de vida del comerciante.</p>@endif
@if($serviceRequest->operations_notes)<p class="ret-agenda-notice"><strong>Observaciones de Operaciones:</strong> {{ $serviceRequest->operations_notes }}</p>@endif

</div><div class="ret-review-agenda">
@if($showOperations)
@if($canSchedule && in_array($serviceRequest->status,['requested','correction_requested'],true))
<section class="ret-operations"><div class="ret-agenda-heading"><h2>Agenda de Operaciones</h2>@if($canSchedule && in_array($serviceRequest->status,['requested','correction_requested','scheduled','rescheduled'],true))<button type="button" class="ret-change-open" data-open-change>Solicitar reagendamiento</button>@endif</div><p>Confirma la agenda con el horario y la jornada de la solicitud. Para proponer cambios, utiliza Solicitar reagendamiento.</p>
<form method="post" action="{{ route('fleet.requests.decision',$serviceRequest) }}" class="ret-form" data-ret-validate id="operations-decision">@csrf
<input type="hidden" name="weight_warning_acknowledged" value="0">
<input type="hidden" name="decision" value="scheduled">
<input type="hidden" name="shift" value="{{ $serviceRequest->shift }}">
<input type="hidden" name="window_start" value="{{ substr($serviceRequest->window_start,0,5) }}">
<input type="hidden" name="window_end" value="{{ substr($serviceRequest->window_end,0,5) }}">
<div class="ret-two"><div><small>Estado al confirmar</small><p><span class="badge">Agendado</span></p></div>
<label>Fecha definitiva de agenda<input type="date" name="service_date" value="{{ old('service_date',$serviceRequest->service_date->format('Y-m-d')) }}" data-schedule required></label>
<label>Conductor<select name="driver_user_id" data-schedule required><option value="">Sin asignar</option>@foreach($drivers as $driver)<option value="{{ $driver->id }}" @selected(old('driver_user_id',$serviceRequest->driver_user_id)==$driver->id)>{{ $driver->name }} · {{ $driver->license_type }}</option>@endforeach</select></label>
<label>Patente<select name="vehicle_id" data-schedule required><option value="">Sin asignar</option>@foreach($vehicles as $row)<option value="{{ $row['vehicle']->id }}" data-unavailable="{{ $row['selectable'] ? '0' : '1' }}" @disabled(!$row['selectable']) data-weight-warning="{{ app(\App\Fleet\RetVehicleEligibility::class)->weightWarning($serviceRequest,$row['vehicle']) }}" data-drivers="{{ implode(',',$drivers->filter(fn($d)=>app(\App\Fleet\RetVehicleEligibility::class)->compatible($d,$row['vehicle']))->modelKeys()) }}" @selected(old('vehicle_id',$serviceRequest->vehicle_id)==$row['vehicle']->id)>{{ $row['vehicle']->plate }} · {{ $row['vehicle']->vehicle_type }}{{ $row['selectable'] ? '' : ' · No disponible: '.$row['unavailability_reason'] }}</option>@endforeach</select><small id="weight-warning-inline" role="status"></small></label></div>
@if(($lateAuthorization['operations']['status']??null)==='rejected' || ($lateAuthorization['commercial']['status']??null)==='rejected')
<label><input type="checkbox" name="customer_date_confirmed" value="1" @checked(old('customer_date_confirmed'))> El cliente confirmó la nueva fecha de retiro.</label>
@endif
<label>Observaciones de Operaciones<textarea name="operations_notes" rows="3" placeholder="Agrega un comentario para la coordinación del retiro.">{{ old('operations_notes') }}</textarea></label>
<p class="ret-agenda-notice">El aviso se prepara únicamente al confirmar <strong>Agendado</strong> con fecha, ventana, conductor y patente. En modo automático de pruebas, la confirmación se envía a Hans cuando está activo Microsoft 365.</p><div class="ret-form-footer"><button>Guardar decisión</button></div></form></section>
<dialog id="ret-weight-dialog" class="ret-change-dialog" aria-labelledby="ret-weight-title"><h2 id="ret-weight-title">Revisar capacidad del transporte</h2><p id="ret-weight-message"></p><p>Quizás no sea recomendable utilizar este vehículo. ¿Quieres continuar guardando la decisión o modificar el transporte?</p><div class="ret-actions"><button type="button" id="ret-weight-continue">Continuar y guardar</button><button type="button" class="secondary" id="ret-weight-modify">Modificar transporte</button></div></dialog>
<script>
const ops=document.getElementById('operations-decision');
ops.querySelector('[name=driver_user_id]').addEventListener('change',e=>{const select=ops.querySelector('[name=vehicle_id]');for(const o of select.options){if(!o.value)continue;o.disabled=o.dataset.unavailable==='1'||(!!e.target.value&&!o.dataset.drivers.split(',').includes(e.target.value));if(o.disabled&&o.selected)select.value='';}});
const weightDialog=document.getElementById('ret-weight-dialog');
const vehicleSelect=ops.querySelector('[name=vehicle_id]');
const weightAck=ops.querySelector('[name=weight_warning_acknowledged]');
function updateWeightWarning(){weightAck.value='0';document.getElementById('weight-warning-inline').textContent=vehicleSelect.selectedOptions[0]?.dataset.weightWarning||'';}
vehicleSelect.addEventListener('change',updateWeightWarning);
ops.querySelector('[name=driver_user_id]').addEventListener('change',updateWeightWarning);
updateWeightWarning();
ops.addEventListener('submit',event=>{const warning=vehicleSelect.selectedOptions[0]?.dataset.weightWarning;if(warning&&weightAck.value!=='1'&&ops.checkValidity()){event.preventDefault();document.getElementById('ret-weight-message').textContent=warning;weightDialog.showModal();}});
document.getElementById('ret-weight-continue').addEventListener('click',()=>{weightAck.value='1';weightDialog.close();ops.requestSubmit();});
document.getElementById('ret-weight-modify').addEventListener('click',()=>{weightAck.value='0';weightDialog.close();vehicleSelect.focus();});
</script>
@else
<section class="ret-operations"><div class="ret-agenda-heading"><h2>Agenda de Operaciones · {{ $stateLabel }}</h2>@if($canSchedule && in_array($serviceRequest->status,['requested','correction_requested','scheduled','rescheduled'],true))<button type="button" class="ret-change-open" data-open-change>Solicitar reagendamiento</button>@endif</div><div class="ret-facts"><div><small>Fecha / ventana</small><strong>{{ $serviceRequest->service_date->format('d-m-Y') }} · {{ substr($serviceRequest->window_start,0,5) }}–{{ substr($serviceRequest->window_end,0,5) }} · {{ $serviceRequest->shift }}</strong></div><div><small>Conductor / patente</small><strong>{{ $serviceRequest->driver?->name ?: 'Sin asignar' }} · {{ $serviceRequest->vehicle?->plate ?: 'Sin asignar' }}</strong></div><div><small>Cantidad vigente</small><strong>{{ $serviceRequest->status === 'cancelled' ? 0 : $serviceRequest->packages }} bultos</strong></div></div></section>
@endif

@endif

@if(app(\App\Fleet\LatePickupAuthorization::class)->afterCutoff($serviceRequest->service_date->toDateString(),$fleetTenant->id))
<section class="panel"><h2>Excepción para retiro del mismo día</h2><p>Después de las {{ app(\App\Fleet\LatePickupAuthorization::class)->cutoff($fleetTenant->id) }} (hora de Chile), la programación para hoy requiere <strong>las dos aprobaciones</strong>: Operaciones y gerente comercial. Un rechazo bloquea hoy. Para mañana, confirma primero la nueva fecha con el cliente.</p>
@if($lateAuthorization)
<p><strong>Fecha de excepción:</strong> {{ $lateAuthorization['date'] }} · {{ $lateAuthorization['reason'] }}</p>
@foreach(['operations'=>'Operaciones','commercial'=>'Gerente comercial'] as $area=>$label)
<p><strong>{{ $label }}:</strong> {{ ['pending'=>'Pendiente','approved'=>'Aprobado','rejected'=>'Rechazado'][$lateAuthorization[$area]['status']] }} @if(isset($lateAuthorization[$area]['actor_name'])) · {{ $lateAuthorization[$area]['actor_name'] }} · {{ $lateAuthorization[$area]['notes'] }} @endif</p>
@if($lateIsCurrent && $lateAreas->contains($area) && $lateAuthorization[$area]['status']==='pending' && $lateAuthorization['date']===now('America/Santiago')->toDateString() && !in_array('rejected',[$lateAuthorization['operations']['status'],$lateAuthorization['commercial']['status']]) && in_array($serviceRequest->status,['requested','correction_requested','reprogramming_pending']))
<form method="post" action="{{ route('fleet.requests.late.decide',$serviceRequest) }}" class="ret-form">@csrf<input type="hidden" name="area" value="{{ $area }}"><label>Motivo de la decisión de {{ $label }}<textarea name="notes" required maxlength="2000"></textarea></label><div class="ret-form-footer"><button name="decision" value="approved">Aprobar {{ $label }}</button><button name="decision" value="rejected">Rechazar {{ $label }}</button></div></form>
@endif
@endforeach
@if(!$lateIsCurrent)<p class="notice">Cambió el horario o la matriz. Renueva esta excepción para solicitar las aprobaciones vigentes. Un rechazo previo sigue bloqueando su fecha.</p>@endif
@if($lateAuthorization['date']!==now('America/Santiago')->toDateString())<p class="notice">Esta excepción venció; no autoriza otras fechas.</p>@endif
@endif
@if($canEdit && in_array($serviceRequest->status,['requested','correction_requested','reprogramming_pending']) && (($lateAuthorization['date']??null)!==now('America/Santiago')->toDateString() || (!$lateIsCurrent && !in_array('rejected',[$lateAuthorization['operations']['status']??null,$lateAuthorization['commercial']['status']??null]))) && app(\App\Fleet\LatePickupAuthorization::class)->afterCutoff(now('America/Santiago')->toDateString(),$fleetTenant->id))
<form method="post" action="{{ route('fleet.requests.late.request',$serviceRequest) }}" class="ret-form">@csrf<input type="hidden" name="date" value="{{ now('America/Santiago')->toDateString() }}"><label>Motivo para solicitar el retiro hoy<textarea name="reason" required maxlength="2000"></textarea></label><button>Solicitar autorización para hoy</button></form>
@endif
</section>
@endif
@if($serviceRequest->cancellation_reason)<p class="notice">Anulado: {{ $serviceRequest->cancellation_reason }}</p>@endif
@if($serviceRequest->guide_number)<p>Guía: {{ $serviceRequest->guide_number }} · Bultos reales: {{ $serviceRequest->actual_packages }}</p>@endif
@if($canReprogram && $serviceRequest->status === 'reprogramming_pending' && $serviceRequest->driver_user_id && $serviceRequest->vehicle_id)
    @php($pendingChange = $serviceRequest->reprogrammings->firstWhere('status', 'pending'))
    @if($pendingChange)
    <form method="post" action="{{ route('fleet.scheduling.overload.request', $serviceRequest) }}" class="card">@csrf
        <h2>Sobrecarga de la fecha propuesta</h2>
        <p>Si la nueva ventana supera el límite, solicita autorización a jefatura antes de que Postventa la apruebe.</p>
        <input type="hidden" name="driver_user_id" value="{{ $pendingChange->proposed_driver_user_id ?? $serviceRequest->driver_user_id }}">
        <input type="hidden" name="vehicle_id" value="{{ $pendingChange->proposed_vehicle_id ?? $serviceRequest->vehicle_id }}">
        <input type="hidden" name="reprogramming_id" value="{{ $pendingChange->id }}">
        <label>Motivo<input name="reason" required maxlength="2000"></label>
        <button>Solicitar autorización de sobrecarga</button>
    </form>
    @endif
@endif
@if($canEdit && in_array($serviceRequest->status,['scheduled','rescheduled'],true))
<form method="post" action="{{ route('fleet.requests.retire', $serviceRequest) }}" class="card">@csrf<button>Retirado!</button></form>
@endif
@if($canApprove && $serviceRequest->status==='reprogramming_pending' && $serviceRequest->reprogrammings->contains('status','pending'))<p><a class="button" href="{{ route('fleet.reprogramming.index') }}">Resolver en Postventa</a></p>@endif
@if($canEdit && !in_array($serviceRequest->status,['retired','cancelled']))
<details class="card"><summary>Anular retiro</summary><form method="post" action="{{ route('fleet.requests.cancel',$serviceRequest) }}">@csrf<label>Motivo de anulación<textarea name="reason" required></textarea></label><p><button>Anular con motivo</button></p></form></details>
@endif
</div></div>
<section class="card"><h2>Aviso al cliente · solicitud recibida</h2><p>Confirma la recepción de la solicitud; la programación se confirma en un correo posterior. En modo automático de pruebas, este aviso se envía únicamente a Hans cuando el envío automático de Microsoft 365 está activo.</p>
@forelse($customerNotifications as $notice)<details><summary>{{ $notice->subject }} · {{ ['prepared'=>'Preparado','captured'=>'Capturado localmente','missing_recipient'=>'Falta correo del cliente','test_accepted'=>'Prueba a Hans aceptada por Microsoft','accepted'=>'Aceptado por Microsoft','sending'=>'Envío en curso','uncertain'=>'Envío por verificar','error'=>'Error'][$notice->status] ?? $notice->status }}</summary>@if($notice->status==='test_accepted')<p>Para (prueba): {{ \App\Fleet\MicrosoftRetMail::TEST_RECIPIENT }} · Sin copias a clientes.</p>@else<p>Para: {{ $notice->recipients ?: 'Sin configurar' }} · CC: {{ $notice->cc ?: 'Sin copia' }} · Responder a: {{ $notice->reply_to }}</p>@endif<iframe title="Vista previa de solicitud recibida" sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="{{ $notice->html }}" style="width:100%;height:700px;border:1px solid #d5e9e9;border-radius:12px"></iframe>
@if($notice->last_error)<p class="notice">{{ $notice->last_error }}</p>@endif
@if($canEdit && ($notice->kind==='cancellation' || !in_array($serviceRequest->status,['cancelled','retired'],true)))<form method="post" action="{{ route('fleet.requests.receipt.send',[$serviceRequest,$notice->id]) }}">@csrf<button name="mode" value="test">Enviar prueba a Hans</button>@if(!$serviceRequest->is_test && !in_array($notice->status,['accepted','sending','uncertain'],true)) <button name="mode" value="customer">Enviar al cliente</button>@endif</form>@endif
</details>@empty @if($canEdit && in_array($serviceRequest->status,['requested','correction_requested'],true))<form method="post" action="{{ route('fleet.requests.receipt.prepare',$serviceRequest) }}">@csrf<button>Preparar aviso de solicitud recibida</button></form>@else<p>No se preparó un aviso de recepción para esta solicitud anterior.</p>@endif @endforelse</section>
<section class="card"><h2>Notificaciones</h2><p>En modo automático de pruebas, las confirmaciones se envían únicamente a Hans cuando está activo el envío automático de Microsoft 365. El enlace del conductor requiere acceso a este localhost.</p>@forelse($notifications as $notification)<details><summary>{{ $notification->created_at }} · {{ ['prepared'=>'Preparado','captured'=>'Capturado localmente','test_accepted'=>'Prueba a Hans aceptada por Microsoft','sending'=>'Envío en curso','uncertain'=>'Envío por verificar','error'=>'Error','superseded'=>'Reemplazado'][$notification->status] ?? $notification->status }} · Intentos: {{ $notification->attempts }}</summary>@if($notification->status==='test_accepted')<p>Para (prueba): {{ \App\Fleet\MicrosoftRetMail::TEST_RECIPIENT }} · Sin copias a clientes.</p>@else<p>De: {{ $notification->sender }}<br>Para: {{ $notification->recipients }}<br>CC: {{ $notification->cc ?: 'Postventa pendiente de configurar' }}</p>@endif<h3>{{ $notification->subject }}</h3><iframe title="Vista previa del correo de retiro {{ $serviceRequest->ret_code }}" sandbox srcdoc="{{ app(\App\Fleet\RetNotificationService::class)->emailHtml($notification->body,$serviceRequest->client_name_snapshot) }}" style="display:block;width:100%;height:1050px;border:1px solid #d5e9e9;border-radius:14px;background:#eef3f4;"></iframe><details><summary>Texto y registro interno del aviso</summary><pre class="ret-history">{{ $notification->body }}</pre></details>@if($notification->last_error)<p>{{ $notification->last_error }}</p>@endif @if($canEdit && in_array($notification->status,['prepared','error']))<form method="post" action="{{ route('fleet.ret.notification.retry',$notification->id) }}">@csrf<button>Reintentar captura</button></form>@endif</details>@empty<p>Sin notificaciones preparadas.</p>@endforelse</section>
<details class="ret-history-panel"><summary>Historial de la solicitud</summary><ol class="ret-timeline">@foreach($events as $event)
@php($detail=json_decode($event->details,true) ?? [])
<li><div><strong>{{ ['created'=>'Creación','edited'=>'Solicitud corregida / editada','scheduled'=>'Retiro agendado','assignment_changed'=>'Asignación actualizada','operations_decision'=>'Decisión de Operaciones','cancelled'=>'Anulado','reprogramming_requested'=>'Reprogramación solicitada','reprogramming_approved'=>'Reprogramación aprobada','reprogramming_rejected'=>'Reprogramación rechazada','retired_manual'=>'Retiro registrado','retired_driver_token'=>'Retiro registrado por conductor','notification_test_accepted'=>'Prueba HTML aceptada por Microsoft','notification_captured'=>'Correo capturado localmente','notification_error'=>'Error al capturar correo','feasibility_linked'=>'Factibilidad vinculada','late_pickup_requested'=>'Autorización fuera de horario solicitada','late_pickup_approved'=>'Autorización fuera de horario aprobada','late_pickup_rejected'=>'Autorización fuera de horario rechazada','requester_reschedule_requested'=>'Reagendamiento enviado al solicitante','requester_reschedule_resolved'=>'Coordinación con el cliente resuelta','customer_cancel'=>'Cliente informó que no tiene carga','customer_correction'=>'Cliente solicitó una corrección','weight_warning_confirmed'=>'Advertencia de carga útil confirmada','overload_requested'=>'Autorización de sobrecarga solicitada','overload_approved'=>'Sobrecarga aprobada','overload_rejected'=>'Sobrecarga rechazada'][$event->action] ?? 'Actualización de la solicitud' }}</strong><time>{{ \Carbon\Carbon::parse($event->created_at)->timezone('America/Santiago')->format('d-m-Y H:i') }}</time></div>
@if(!empty($detail['area']))<p>Área: {{ $detail['area']==='operations'?'Operaciones':'Gerencia comercial' }}</p>@endif
@if(!empty($detail['notes']))<p>{{ $detail['notes'] }}</p>@endif
@if(!empty($detail['reason']))<p>{{ $detail['reason'] }}</p>@endif
@if(!empty($detail['proposed_date']))<p>Fecha propuesta: {{ $detail['proposed_date'] }}</p>@endif
@if(!empty($detail['customer_response']))<p>Respuesta del cliente: {{ $detail['customer_response'] }}</p>@endif
@if($event->action==='weight_warning_confirmed')<p>{{ $detail['warning'] ?? '' }} · Patente: {{ $detail['plate'] ?? '' }} · Peso informado: {{ $detail['requested_weight_kg'] ?? 'Pendiente' }} kg · Carga útil registrada: {{ $detail['payload_capacity_kg'] ?? 'Pendiente' }} kg. Se confirmó continuar con la asignación.</p>@endif
@if(!empty($detail['decision']))<p>{{ ['scheduled'=>'Agendado','correction_requested'=>'Corrección solicitada','cancelled'=>'Anulado'][$detail['decision']] ?? $detail['decision'] }}</p>@endif
<small>{{ $event->actor_name ?: 'Sistema / conductor' }}</small></li>@endforeach</ol></details></div>

@if(($showOperations && $canSchedule && in_array($serviceRequest->status,['requested','correction_requested','scheduled','rescheduled'],true)) || ($requesterView && $canEdit && in_array($serviceRequest->status,['requested','correction_requested'],true)))
<dialog id="ret-change-dialog" class="ret-change-dialog" aria-labelledby="ret-change-title"><div class="ret-agenda-heading"><h2 id="ret-change-title">{{ $showOperations ? 'Solicitar reagendamiento' : 'Editar solicitud' }}</h2><button type="button" data-close-change aria-label="Cerrar">×</button></div>
@if($showOperations)
<p>La propuesta se enviará a <strong>{{ $requester?->name ?: 'quien solicitó el servicio' }}</strong> para coordinar con el cliente. La agenda vigente no cambia hasta que confirme la nueva fecha.</p>
<form method="post" action="{{ route('fleet.requests.requester-reschedule',$serviceRequest) }}" class="ret-form">@csrf
<div class="grid"><label>Nueva fecha propuesta<input type="date" name="proposed_date" min="{{ now('America/Santiago')->toDateString() }}" value="{{ old('proposed_date',$serviceRequest->service_date->format('Y-m-d')) }}" required></label><label>Desde<input type="time" name="proposed_start" value="{{ old('proposed_start',substr($serviceRequest->window_start,0,5)) }}" required></label><label>Hasta<input type="time" name="proposed_end" value="{{ old('proposed_end',substr($serviceRequest->window_end,0,5)) }}" required></label></div>
<label>Motivo de reprogramación<select name="reason_code" required><option value="">Seleccionar motivo</option>@foreach(\App\Fleet\PickupCustomerService::REASONS as $code=>$label)<option value="{{ $code }}" @selected(old('reason_code')===$code)>{{ $label }}</option>@endforeach</select></label>
<label>Detalle del motivo<textarea name="reason_detail" rows="2" maxlength="2000" placeholder="Agrega un detalle; obligatorio si seleccionas Otro.">{{ old('reason_detail') }}</textarea></label><p><button>Enviar a quien solicitó el retiro</button></p></form>
@else
<p>Selecciona los datos que necesitas corregir. Los antecedentes originales se conservarán en el historial y Operaciones recibirá la solicitud actualizada.</p><form method="post" action="{{ route('fleet.requests.update',$serviceRequest) }}" class="ret-form" data-ret-validate>@csrf @method('put')@php($changeOptions=['schedule'=>'Fecha y horario','packages'=>'Cantidad de bultos','address'=>'Dirección e instrucciones','dimensions'=>'Peso y volumen','notes'=>'Observaciones'])<fieldset class="ret-change-items"><legend>¿Qué necesitas cambiar?</legend>@foreach($changeOptions as $item=>$label)<label><input type="checkbox" name="change_items[]" value="{{ $item }}" @checked(in_array($item,old('change_items',['schedule'])))> {{ $label }}</label>@endforeach</fieldset><input type="hidden" name="pickup_name" value="{{ $serviceRequest->pickup_name }}">
<div class="grid">@foreach(['service_date'=>'Fecha vigente','window_start'=>'Desde','window_end'=>'Hasta','packages'=>'Bultos','effective_address'=>'Dirección efectiva','access_reference_snapshot'=>'Referencia de acceso e instrucciones'] as $key=>$label)<label>{{ $label }}<input name="{{ $key }}" type="{{ $key==='service_date'?'date':(in_array($key,['window_start','window_end'])?'time':($key==='packages'?'number':'text')) }}" value="{{ $key==='service_date'?$serviceRequest->service_date->format('Y-m-d'):(in_array($key,['window_start','window_end'])?substr($serviceRequest->$key,0,5):$serviceRequest->$key) }}" @if($key!=='access_reference_snapshot') required @endif></label>@endforeach<label>Jornada<select name="shift"><option @selected($serviceRequest->shift==='AM')>AM</option><option @selected($serviceRequest->shift==='PM')>PM</option><option @selected($serviceRequest->shift==='AM/PM')>AM/PM</option></select></label></div>
<div class="grid"><label>Volumen solicitado (m³)<input type="number" step="0.001" min="0.001" name="requested_volume_m3" value="{{ $serviceRequest->requested_volume_m3 }}"></label><label>Peso de la carga (kg)<input type="number" step="0.01" min="0.01" name="requested_weight_kg" value="{{ $serviceRequest->requested_weight_kg }}"></label></div><label>Observaciones<textarea name="notes">{{ $serviceRequest->notes }}</textarea></label><label>Motivo del cambio<textarea name="reason" required></textarea></label><p><button>Enviar propuesta a Operaciones</button></p></form>
@endif
<button type="button" class="secondary" data-close-change>Cancelar</button></dialog>
<script>
const changeDialog=document.getElementById('ret-change-dialog');
document.querySelectorAll('[data-open-change]').forEach(button=>button.addEventListener('click',()=>changeDialog.showModal()));
changeDialog.querySelectorAll('[data-close-change]').forEach(button=>button.addEventListener('click',()=>changeDialog.close()));
const changeForm=changeDialog.querySelector('form');
const reasonChoice=changeForm.querySelector('[name=reason_code]');
if(reasonChoice){const detail=changeForm.querySelector('[name=reason_detail]');const updateReason=()=>detail.required=reasonChoice.value==='other';reasonChoice.addEventListener('change',updateReason);updateReason();}
const groups={schedule:['service_date','window_start','window_end','shift'],packages:['packages'],address:['effective_address','access_reference_snapshot'],dimensions:['requested_weight_kg','requested_volume_m3'],notes:['notes']};
function updateChangeItems(){const boxes=[...changeForm.querySelectorAll('[name="change_items[]"]')];if(!boxes.length)return;boxes[0].setCustomValidity(boxes.some(box=>box.checked)?'':'Selecciona al menos un ítem.');for(const box of boxes){for(const name of groups[box.value]||[]){const field=changeForm.querySelector(`[name="${name}"]`);if(field){field.disabled=!box.checked;(field.closest('label') || field.parentElement).toggleAttribute('hidden',!box.checked);}}}}
changeForm.querySelectorAll('[name="change_items[]"]').forEach(box=>box.addEventListener('change',updateChangeItems));updateChangeItems();
@if($errors->any() && (old('change_items') || old('reason_code'))) changeDialog.showModal(); @endif
</script>
@endif
@endsection
