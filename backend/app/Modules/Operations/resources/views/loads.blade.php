@extends('operations::layout')
@section('title', $type === 'master' ? 'Maestro Geolize' : 'Recepción de bultos')
@section('content')
<div class="ope-heading"><h1>{{ $type === 'master' ? 'Carga Maestro Geolize' : 'Carga Recepción de bultos' }}</h1></div>
<p class="intro">{{ $type === 'master' ? 'Carga el Maestro diario para completar cliente, servicio y destino de los paquetes.' : 'Carga los escaneos y pesos volumétricos validados por los operarios. Esta fuente define los bultos que se trabajan.' }}</p>
<section class="card"><form method="POST" enctype="multipart/form-data" action="{{ route('operations.loads.store',$type) }}" class="ope-form">@csrf
<label>Archivo Excel .xlsx<input type="file" name="file" accept=".xlsx" required></label>
<label>Hoja de datos<input name="sheet" value="{{ old('sheet', $type === 'master' ? 'Sheet1' : 'Hoja1') }}" maxlength="80" required></label>
@if($type === 'reception')
<label>Formato<select name="profile"><option value="legacy" @selected(old('profile','legacy')==='legacy')>ProcesoRecepcionOperaciones actual</option><option value="custom" @selected(old('profile')==='custom')>Columnas configurables</option></select></label>
<p class="note ope-full">Formato actual: fecha A, código completo E, peso F, operario H y referencia ESD I. Se contrastan los pesos de C, F y N. ESD se conserva como referencia; el número de guía cliente debe indicarse en su columna real.</p>
@foreach(['date'=>['Fecha','A'],'tracking'=>['Código paquete','E'],'weight'=>['Peso volumétrico kg','F'],'operator'=>['Usuario operario','H'],'customer_guide'=>['Número guía cliente',''],'reference'=>['Referencia','I']] as $field=>$item)
<label>{{ $item[0] }} · columna<input name="{{ $field }}" value="{{ old($field,$item[1]) }}" pattern="[A-Z]{1,2}" maxlength="2" placeholder="{{ $field==='customer_guide' ? 'Sin columna: dejar vacío' : 'Letra de columna' }}"></label>
@endforeach
<p class="note ope-full">Las columnas configurables se utilizan al seleccionar ese formato. En el formato actual solo puedes indicar la columna de guía cliente. Los encabezados deben estar en la primera fila.</p>
@endif
<div class="ope-full"><button type="submit">Cargar y validar Excel</button></div></form></section>
<h2>Historial de cargas</h2><div class="card table-wrap"><table class="ope-table"><thead><tr><th>Carga</th><th>Fecha</th><th>Archivo</th><th>Filas</th><th>Con errores</th><th>Estado</th><th></th></tr></thead><tbody>@forelse($loads as $load)<tr><td>#{{ $load->id }}</td><td>{{ $load->created_at }}</td><td>{{ $load->filename }}</td><td>{{ $load->row_count }}</td><td>{{ $load->invalid_count }}</td><td>{{ ['queued'=>'En espera','processing'=>'Procesando','completed'=>'Terminada','failed'=>'Revisar archivo'][$load->status] }}</td><td><a href="{{ route('operations.loads.show',$load->id) }}">Revisar</a></td></tr>@empty<tr><td colspan="7" class="ope-empty">Todavía no hay cargas. Selecciona el Excel para comenzar.</td></tr>@endforelse</tbody></table></div>
@include('operations::pager',['rows'=>$loads])
@endsection
