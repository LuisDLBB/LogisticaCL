@extends('fleet::layout')
@section('title', 'Factibilidad')
@section('content')
<div class="page-heading"><div><h1>Factibilidad de servicios</h1><p>Un expediente compartido por Comercial, Postventa, Operaciones y Finanzas.</p></div></div>
<section class="panel"><div class="table-wrap"><table><thead><tr><th>Expediente</th><th>Cliente</th><th>Etapa</th><th>Resultado operativo</th><th>Solicitudes</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td><a href="{{ route('fleet.feasibility.show',$row) }}">RET-FAC-{{ $row->id }}</a>@if($row->is_test) <span class="tag off">Prueba</span>@endif</td><td>{{ $row->client->commercial_name }}</td><td>{{ \App\Http\Controllers\Fleet\RetFeasibilityController::STATES[$row->status] ?? $row->status }}</td><td>{{ $row->operational_result ?? 'Pendiente' }}</td><td>@if($row->service_request_id)<a href="{{ route('fleet.requests.show',$row->service_request_id) }}">Ver RET</a>@else — @endif</td></tr>@empty<tr><td colspan="5" class="empty-state">Aún no hay expedientes.</td></tr>@endforelse
</tbody></table></div></section>{{ $rows->links() }}
@if($canCreate)
<form class="card ret-form" method="post" action="{{ route('fleet.feasibility.store') }}" id="feasibility-form">@csrf
<h2>Nueva factibilidad</h2>
<label for="pasted-text">Pegar texto de correo o WhatsApp</label><textarea id="pasted-text" rows="5" placeholder="Origen: …&#10;Destino: …&#10;Bultos: …&#10;Peso: … kg&#10;Fecha: AAAA-MM-DD"></textarea>
<p><button type="button" id="recognize">Reconocer y completar</button></p><p id="recognition-status" role="status">Reconocimiento local por etiquetas. Revisa los datos antes de guardar.</p>
<div class="grid"><div><label for="client_id">Cliente</label><select name="client_id" id="client_id" required><option value="">Seleccionar</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(old('client_id')==$client->id)>{{ $client->commercial_name }}</option>@endforeach</select></div><div><label for="service_type_id">Servicio requerido</label><select name="service_type_id" id="service_type_id" required>@foreach($types as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select></div></div>
@include('fleet::feasibility.fields',['fields'=>\App\Http\Controllers\Fleet\RetFeasibilityController::REQUEST_FIELDS,'values'=>[]])
<label for="requires_finance">¿Requiere revisión de Finanzas?</label><select name="requires_finance" id="requires_finance" required><option value="">Seleccionar</option><option value="1">Sí</option><option value="0">No</option></select>
@include('fleet::coordination.test-flag')
<p><button>Guardar y enviar a Operaciones</button></p></form>
<script>
document.getElementById('recognize').addEventListener('click', async () => {
 const button=document.getElementById('recognize'); button.disabled=true;
 try {
  const response=await fetch(@json(route('fleet.feasibility.recognize')), {method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('#feasibility-form input[name=_token]').value},body:JSON.stringify({text:document.getElementById('pasted-text').value})});
  if(!response.ok) throw new Error('No se pudo reconocer. Revisa el texto.');
  const result=await response.json();
  for(const key of [...result.missing,...result.ambiguous]) { const el=document.getElementById('field-'+key); if(el && !el.value) el.classList.add('ret-missing'); }
  for(const [key,value] of Object.entries(result.values)) { const el=document.getElementById('field-'+key); if(el && !el.value) {el.value=value;el.classList.remove('ret-missing');} }
  document.getElementById('recognition-status').textContent='Datos reconocidos: '+Object.keys(result.values).length+'. Los vacíos o ambiguos están resaltados. Se conservaron los campos que ya habías completado.';
 } catch(e) {document.getElementById('recognition-status').textContent=e.message;} finally {button.disabled=false;}
});
</script>
@endif
@endsection
