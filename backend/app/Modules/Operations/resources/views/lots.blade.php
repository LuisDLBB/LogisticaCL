@extends('operations::layout')
@section('title','Procesos de Operaciones')
@section('content')
<h1>Procesos</h1><p class="intro">Prepara el cruce de bultos recibidos con Maestro Geolize, resuelve las incidencias y luego programa las salidas.</p>
<div class="ope-grid ope-steps">
    <a class="card" href="{{ route('operations.system-receptions.index') }}"><h3>1. Recepción Sistema</h3><p class="note">Registrar y escanear los bultos físicos.</p></a>
    <a class="card" href="{{ route('operations.loads.index','master') }}"><h3>2. Maestro Geolize</h3><p class="note">Cargar los datos de cliente, servicio y destino.</p></a>
    <a class="card" href="#preparar-proceso"><h3>3. Procesos</h3><p class="note">Cruzar las fuentes y revisar incidencias.</p></a>
    <a class="card" href="{{ route('operations.departures.overview') }}"><h3>4. Salidas</h3><p class="note">Programar agencias y revisar las guías.</p></a>
</div>
<section class="card" id="preparar-proceso"><h2>Preparar proceso</h2><form method="POST" action="{{ route('operations.lots.store') }}" class="ope-form">@csrf
<label>Nombre del proceso<input name="name" value="{{ old('name','Primera milla') }}" maxlength="160" required></label><label>Fecha del proceso<input type="date" name="operation_date" value="{{ old('operation_date',now()->timezone('America/Santiago')->format('Y-m-d')) }}" required></label>
<label>Maestro Geolize<select name="master_load_id" required><option value="">Seleccionar carga</option>@foreach($loads->where('source_type','master') as $load)<option value="{{ $load->id }}" @selected(old('master_load_id',$selectedMasterLoadId)==$load->id)>#{{ $load->id }} · {{ $load->filename }} · {{ $load->created_at }}</option>@endforeach</select></label>
<fieldset class="ope-full"><legend>Recepción · selecciona una o más recepciones cerradas</legend>@forelse($loads->where('source_type','reception') as $load)<label class="check"><input type="checkbox" name="reception_load_ids[]" value="{{ $load->id }}" @checked(in_array($load->id,old('reception_load_ids',$selectedReceptionLoadIds)))> #{{ $load->id }} · {{ $load->filename }} · {{ $load->created_at }} · {{ $load->row_count }} bultos</label>@empty<p class="note">Cierra primero una Recepción Sistema.</p>@endforelse</fieldset>
@if($loads->where('source_type','reception')->isEmpty())
<div class="ope-full warning" role="status">Falta cargar Recepción de bultos para preparar el proceso. Los escaneos definen los paquetes y sus pesos. <a href="{{ route('operations.system-receptions.index') }}">Abrir Recepción Sistema</a></div>
@endif
@if($loads->where('source_type','master')->isEmpty())
<div class="ope-full warning" role="status">Falta cargar el Maestro Geolize. <a href="{{ route('operations.loads.index','master') }}">Cargar Maestro Geolize</a></div>
@endif
<div class="ope-full"><button @disabled($loads->where('source_type','master')->isEmpty() || $loads->where('source_type','reception')->isEmpty())>Preparar y cruzar datos</button><p class="note">Se incluyen únicamente los códigos presentes en Recepción. Se usa su peso; si falta, se toma la parte entera del peso de Geolize.</p></div></form></section>
<h2>Procesos registrados</h2><div class="card table-wrap"><table class="ope-table"><thead><tr><th>Proceso</th><th>Fecha</th><th>Nombre</th><th>Acciones</th></tr></thead><tbody>@forelse($lots as $lot)<tr><td>#{{ $lot->id }}</td><td>{{ $lot->operation_date }}</td><td>{{ $lot->name }}</td><td><a href="{{ route('operations.lots.show',$lot->id) }}">Bultos e incidencias</a> · <a href="{{ route('operations.departures.index',$lot->id) }}">Salidas y supervisor</a></td></tr>@empty<tr><td colspan="4" class="ope-empty">Todavía no hay procesos preparados.</td></tr>@endforelse</tbody></table></div>@include('operations::pager',['rows'=>$lots])
@endsection
