@extends('operations::layout')
@section('title','Resultado de carga')
@section('content')
<a class="back" href="{{ route('operations.loads.index',$load->source_type) }}">← Volver a {{ $load->source_type === 'master' ? 'Maestro Geolize' : 'Recepción de bultos' }}</a>
<h1>Carga #{{ $load->id }} · {{ $load->filename }}</h1><p class="intro">Hoja {{ $load->sheet }} · {{ $load->row_count }} filas · {{ $load->invalid_count }} filas con errores</p>
@if(in_array($load->status,['queued','processing']))<div class="warning" role="status">{{ $load->status==='queued' ? 'Archivo en espera de procesamiento.' : 'Procesando Excel. Esta pantalla se actualizará automáticamente.' }} Puedes volver al menú mientras termina.</div>@push('scripts')<script>setTimeout(()=>window.location.reload(),8000);</script>@endpush
@endif
@if($load->status==='failed')<div class="error">{{ $load->error }} <a href="{{ route('operations.loads.index',$load->source_type) }}">Volver a cargar</a></div>@endif
<div class="ope-actions"><a class="button" href="{{ route('operations.dashboard',['load'=>$load->id]).'#preparar-proceso' }}">Preparar proceso</a><a href="{{ route('operations.loads.show',[$load->id,'errors'=>1]) }}">Solo errores</a><a href="{{ route('operations.loads.show',$load->id) }}">Todas las filas</a></div>
<div class="card table-wrap"><table class="ope-table"><thead><tr><th>Fila</th><th>Paquete</th><th>Datos importados</th><th>Validación</th></tr></thead><tbody>@foreach($rows as $row)<tr><td>{{ $row->line }}</td><td>{{ $row->tracking ?: 'Sin código válido' }}</td><td><details><summary>Ver datos</summary><pre>{{ json_encode(json_decode($row->data,true),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre><details><summary>Fila original</summary><pre>{{ $row->raw }}</pre></details></details></td><td>@forelse(json_decode($row->errors,true) as $error)<div class="ope-badge ope-error">{{ $error }}</div>@empty<span class="ope-badge">Validada</span>@endforelse</td></tr>@endforeach</tbody></table></div>@include('operations::pager',['rows'=>$rows])
<p class="note">Huella del archivo: {{ $load->sha256 }}. La carga y sus filas se conservan sin sobrescribir versiones anteriores.</p>
@endsection
