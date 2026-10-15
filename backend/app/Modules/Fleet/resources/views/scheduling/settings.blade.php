@extends('fleet::layout')
@section('title', 'Parámetros de Programación')
@section('content')
<h1>Parámetros de Programación</h1>
<p class="muted">Empresa de servicio: {{ $tenant->name }}. Las coincidencias se cuentan en el pool conjunto 4N + PMCB.</p>
<form class="card" method="post" action="{{ route('fleet.scheduling.settings.update') }}">@csrf @method('PUT')
    <label>Máximo de retiros coincidentes sin autorización<input type="number" name="max_concurrent_pickups" min="1" max="100" required value="{{ old('max_concurrent_pickups', $limit) }}"></label>
    <button type="submit">Guardar límite</button>
</form>
<form class="card ret-form" method="post" action="{{ route('fleet.scheduling.cutoff.update') }}">@csrf @method('PUT')
<h2>Horario de corte para retiros del mismo día</h2><label>Hora de corte (Chile)<input type="time" name="pickup_cutoff_time" value="{{ old('pickup_cutoff_time',$cutoff) }}" required></label><p>Después de esta hora, la programación para hoy exige una aprobación de Operaciones y otra de Gerencia comercial.</p><button>Guardar horario de corte</button>
</form>
<section class="card"><h2>Matriz de aprobadores de excepciones</h2><p>Puedes agregar varias cuentas por área. Una cuenta de cada área debe aprobar; cualquier rechazo bloquea ese día. Las dos decisiones deben registrarse con cuentas distintas.</p><p>Los cambios se aplican a la empresa activa. Si cambia el horario o la matriz, las excepciones abiertas deberán renovarse antes de utilizar aprobaciones anteriores. Los rechazos y el historial se conservan.</p>
<form class="ret-form" method="post" action="{{ route('fleet.scheduling.approvers.add') }}">@csrf<div class="ret-two"><label>Correo de usuario registrado<input type="email" name="email" required value="{{ old('email') }}" placeholder="usuario@empresa.cl"></label><label>Área de aprobación<select name="area" required><option value="operations" @selected(old('area')==='operations')>Operaciones</option><option value="commercial" @selected(old('area')==='commercial')>Gerencia comercial</option></select></label></div><button>Agregar aprobador</button></form>
<div class="table-wrap"><table><thead><tr><th>Usuario</th><th>Correo</th><th>Área</th><th>Estado</th><th>Acción</th></tr></thead><tbody>@forelse($approvers as $approver)<tr><td>{{ $approver->name }}</td><td>{{ $approver->email }}</td><td>{{ $approver->area==='operations'?'Operaciones':'Gerencia comercial' }}</td><td>{{ $approver->is_active?'Activo':'Fuera de matriz activa' }}</td><td><form method="post" action="{{ route('fleet.scheduling.approvers.toggle',$approver->id) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $approver->is_active?0:1 }}"><button>{{ $approver->is_active?'Quitar de matriz':'Reactivar' }}</button></form></td></tr>@empty<tr><td colspan="5">No hay aprobadores configurados. Agrega responsables de ambas áreas.</td></tr>@endforelse</tbody></table></div>
@foreach(['operations'=>'Operaciones','commercial'=>'Gerencia comercial'] as $area=>$label)@if(!$approvers->contains(fn($row)=>$row->area===$area && $row->is_active))<p class="notice">{{ $label }} no tiene aprobadores activos. Sus excepciones quedarán bloqueadas hasta configurarlos.</p>@endif @endforeach
</section>
@endsection
