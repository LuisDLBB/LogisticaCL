@extends('operations::layout')
@section('title', $type === 'master' ? 'Maestro Geolize' : 'Recepción de bultos')
@section('content')
<div class="ope-heading"><h1>{{ $type === 'master' ? 'Carga Maestro Geolize' : 'Carga Recepción de bultos' }}</h1></div>
<p class="intro">{{ $type === 'master' ? 'Carga el Maestro diario para completar cliente, servicio y destino de los paquetes.' : 'Carga los escaneos y pesos volumétricos validados por los operarios. Esta fuente define los bultos que se trabajan.' }}</p>
@if($type === 'reception')<p class="note">También puedes <a href="{{ route('operations.system-receptions.index') }}">registrar y escanear bultos desde Recepción Sistema</a>.</p>@endif
<section class="card">
@if($type === 'reception')
    @include('operations::reception-excel-form')
@else
    <form method="POST" enctype="multipart/form-data" action="{{ route('operations.loads.store', 'master') }}" class="ope-form">@csrf
        <label>Archivo Excel .xlsx<input type="file" name="file" accept=".xlsx" required></label>
        <label>Hoja de datos<input name="sheet" value="{{ old('sheet', 'Sheet1') }}" maxlength="80" required></label>
        <div class="ope-full"><button type="submit">Cargar y validar Excel</button></div>
    </form>
@endif
</section>
<h2>Historial de cargas</h2><div class="card table-wrap"><table class="ope-table"><thead><tr><th>Carga</th><th>Fecha</th><th>Archivo</th><th>Filas</th><th>Con errores</th><th>Estado</th><th></th></tr></thead><tbody>@forelse($loads as $load)<tr><td>#{{ $load->id }}</td><td>{{ $load->created_at }}</td><td>{{ $load->filename }}</td><td>{{ $load->row_count }}</td><td>{{ $load->invalid_count }}</td><td>{{ ['queued'=>'En espera','processing'=>'Procesando','completed'=>'Terminada','failed'=>'Revisar archivo'][$load->status] }}</td><td><a href="{{ route('operations.loads.show',$load->id) }}">Revisar</a></td></tr>@empty<tr><td colspan="7" class="ope-empty">Todavía no hay cargas. Selecciona el Excel para comenzar.</td></tr>@endforelse</tbody></table></div>
@include('operations::pager',['rows'=>$loads])
@endsection
