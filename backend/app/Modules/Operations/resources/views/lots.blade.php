@extends('operations::layout')
@section('title','Procesos de Operaciones')
@section('content')
<h1>Operaciones · Primera milla</h1><p class="intro">Carga las fuentes, revisa los bultos y prepara las salidas para aprobación del supervisor.</p>
<div class="ope-grid"><a class="card" href="{{ route('operations.loads.index','master') }}"><h3>1. Maestro Geolize</h3><p class="note">Datos diarios de cliente, servicio y destino.</p></a><a class="card" href="{{ route('operations.loads.index','reception') }}"><h3>2. Recepción</h3><p class="note">Escaneos y peso volumétrico validado.</p></a><a class="card" href="{{ route('operations.setup') }}"><h3>3. Agencias y guías</h3><p class="note">Direcciones y configuración por troncal y postas.</p></a></div>
<section class="card" id="preparar-proceso"><h2>Preparar proceso</h2><form method="POST" action="{{ route('operations.lots.store') }}" class="ope-form">@csrf
<label>Nombre del proceso<input name="name" value="{{ old('name','Primera milla') }}" maxlength="160" required></label><label>Fecha del proceso<input type="date" name="operation_date" value="{{ old('operation_date',now()->timezone('America/Santiago')->format('Y-m-d')) }}" required></label>
<label>Maestro Geolize<select name="master_load_id" required><option value="">Seleccionar carga</option>@foreach($loads->where('source_type','master') as $load)<option value="{{ $load->id }}" @selected(old('master_load_id',$selectedMasterLoadId)==$load->id)>#{{ $load->id }} · {{ $load->filename }} · {{ $load->created_at }}</option>@endforeach</select></label>
<fieldset class="ope-full"><legend>Recepción · selecciona una o más cargas</legend>@forelse($loads->where('source_type','reception') as $load)<label class="check"><input type="checkbox" name="reception_load_ids[]" value="{{ $load->id }}" @checked(in_array($load->id,old('reception_load_ids',$selectedReceptionLoadIds)))> #{{ $load->id }} · {{ $load->filename }} · {{ $load->created_at }} · {{ $load->row_count }} filas</label>@empty<p class="note">Carga primero el Excel de Recepción.</p>@endforelse</fieldset>
@if($loads->where('source_type','reception')->isEmpty())
<div class="ope-full warning" role="status">Falta cargar Recepción de bultos para preparar el proceso. Este archivo define los paquetes y sus pesos. <a href="{{ route('operations.loads.index','reception') }}">Cargar Recepción de bultos</a></div>
@endif
@if($loads->where('source_type','master')->isEmpty())
<div class="ope-full warning" role="status">Falta cargar el Maestro Geolize. <a href="{{ route('operations.loads.index','master') }}">Cargar Maestro Geolize</a></div>
@endif
<div class="ope-full"><button @disabled($loads->where('source_type','master')->isEmpty() || $loads->where('source_type','reception')->isEmpty())>Preparar y cruzar datos</button><p class="note">Se incluyen únicamente los códigos presentes en Recepción. El peso del Maestro nunca sustituye el peso de escaneo.</p></div></form></section>
<h2>Procesos registrados</h2><div class="card table-wrap"><table class="ope-table"><thead><tr><th>Proceso</th><th>Fecha</th><th>Nombre</th><th>Acciones</th></tr></thead><tbody>@forelse($lots as $lot)<tr><td>#{{ $lot->id }}</td><td>{{ $lot->operation_date }}</td><td>{{ $lot->name }}</td><td><a href="{{ route('operations.lots.show',$lot->id) }}">Bultos e incidencias</a> · <a href="{{ route('operations.departures.index',$lot->id) }}">Salidas y supervisor</a></td></tr>@empty<tr><td colspan="4" class="ope-empty">Todavía no hay procesos preparados.</td></tr>@endforelse</tbody></table></div>@include('operations::pager',['rows'=>$lots])
@endsection
