@extends('operations::layout')
@section('title','Resultado de carga')
@section('content')
@php($systemReceptionId = json_decode($load->mapping, true)['reception_id'] ?? null)
<a class="back" href="{{ $systemReceptionId ? route('operations.system-receptions.show',$systemReceptionId) : route('operations.loads.index',$load->source_type) }}">← Volver a {{ $systemReceptionId ? 'Recepción Sistema' : ($load->source_type === 'master' ? 'Maestro Geolize' : 'Recepción de bultos') }}</a>
<h1>Carga #{{ $load->id }} · {{ $load->filename }}</h1><p class="intro">Hoja {{ $load->sheet }} · {{ $load->row_count }} filas · {{ $load->invalid_count }} filas con errores</p>
@if(in_array($load->status,['queued','processing']))<div class="warning" role="status">{{ $load->status==='queued' ? 'Archivo en espera de procesamiento.' : 'Procesando Excel. Esta pantalla se actualizará automáticamente.' }} Puedes volver al menú mientras termina.</div>@push('scripts')<script>setTimeout(()=>window.location.reload(),8000);</script>@endpush
@endif
@if($load->status==='failed')<div class="error">{{ $load->error }} <a href="{{ route('operations.loads.index',$load->source_type) }}">Volver a cargar</a></div>@endif
@if($load->source_type === 'master' && $load->status === 'completed')
<section class="card" aria-labelledby="master-comparison-title">
    <h2 id="master-comparison-title">Comparación con la carga anterior</h2>
    @if($comparison)
        <p class="note">Comparada por código de paquete con la carga #{{ $comparison['previous_load_id'] }} · {{ $comparison['previous_filename'] }}.</p>
        <div class="table-wrap"><table class="ope-table"><thead><tr><th>Nuevos</th><th>Modificados</th><th>Sin cambios</th><th>Ausentes en esta carga</th><th>Códigos ambiguos</th></tr></thead><tbody><tr><td>{{ number_format($comparison['new_count'], 0, ',', '.') }}</td><td>{{ number_format($comparison['changed_count'], 0, ',', '.') }}</td><td>{{ number_format($comparison['unchanged_count'], 0, ',', '.') }}</td><td>{{ number_format($comparison['missing_count'], 0, ',', '.') }}</td><td>{{ number_format($comparison['ambiguous_count'], 0, ',', '.') }}</td></tr></tbody></table></div>
        <p class="note">Se comparan los datos válidos de Geolize. Las filas inválidas (anterior: {{ $comparison['previous_invalid_count'] }}; actual: {{ $load->invalid_count }}) y los códigos repetidos quedan fuera de estos cuatro totales. «Ausente» solo indica que el código no vino en el nuevo archivo; no se borra. Los procesos y guías ya creados conservan sus datos.</p>
        @if($comparison['changed_samples'])
            <h3>Ejemplos de cambios (hasta 10)</h3>
            <div class="table-wrap"><table class="ope-table"><thead><tr><th>Paquete</th><th>Campo</th><th>Antes</th><th>Ahora</th></tr></thead><tbody>
            @foreach($comparison['changed_samples'] as $sample)
                @foreach($sample['fields'] as $field => $values)
                    <tr><td>{{ $sample['tracking'] }}</td><td>{{ ['commune' => 'Comuna', 'merchant' => 'Cliente', 'service' => 'Servicio', 'recipient' => 'Destinatario', 'address' => 'Dirección', 'geolize_guide' => 'Guía Geolize', 'geolize_weight' => 'Peso Geolize'][$field] ?? $field }}</td><td>{{ $values['before'] ?: '—' }}</td><td>{{ $values['after'] ?: '—' }}</td></tr>
                @endforeach
            @endforeach
            </tbody></table></div>
        @endif
        @if($comparison['new_samples'])<p class="note"><strong>Ejemplos nuevos:</strong> {{ implode(', ', $comparison['new_samples']) }}</p>@endif
        @if($comparison['missing_samples'])<p class="note"><strong>Ejemplos ausentes:</strong> {{ implode(', ', $comparison['missing_samples']) }}</p>@endif
    @else
        <p class="note">Esta carga no tiene una comparación guardada. Las próximas cargas de Geolize se compararán automáticamente con la última carga terminada.</p>
    @endif
</section>
@endif
<div class="ope-actions"><a class="button" href="{{ route('operations.lots.index',['load'=>$load->id]).'#preparar-proceso' }}">Preparar proceso</a><a href="{{ route('operations.loads.show',[$load->id,'errors'=>1]) }}">Solo errores</a><a href="{{ route('operations.loads.show',$load->id) }}">Todas las filas</a></div>
<div class="card table-wrap"><table class="ope-table"><thead><tr><th>Fila</th><th>Paquete</th><th>Datos importados</th><th>Validación</th></tr></thead><tbody>@foreach($rows as $row)<tr><td>{{ $row->line }}</td><td>{{ $row->tracking ?: 'Sin código válido' }}</td><td><details><summary>Ver datos</summary><pre>{{ json_encode(json_decode($row->data,true),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre><details><summary>Fila original</summary><pre>{{ $row->raw }}</pre></details></details></td><td>@forelse(json_decode($row->errors,true) as $error)<div class="ope-badge ope-error">{{ $error }}</div>@empty<span class="ope-badge">Validada</span>@endforelse</td></tr>@endforeach</tbody></table></div>@include('operations::pager',['rows'=>$rows])
<p class="note">Huella de {{ $systemReceptionId ? 'la recepción' : 'archivo' }}: {{ $load->sha256 }}. La carga y sus filas se conservan sin sobrescribir versiones anteriores.</p>
@endsection
