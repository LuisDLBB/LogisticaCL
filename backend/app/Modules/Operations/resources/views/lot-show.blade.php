@extends('operations::layout')
@section('title','Bultos e incidencias')
@section('content')
<h1>Proceso #{{ $lot->id }} · {{ $lot->name }}</h1><p class="intro">{{ $lot->operation_date }} · Maestro #{{ $lot->master_load_id }}</p>
<div class="ope-grid"><div class="card"><span class="ope-stat">{{ $count }}</span>Bultos incluidos</div><div class="card"><span class="ope-stat">{{ number_format((float)$weight,3,',','.') }} kg</span>Peso volumétrico de Recepción</div><div class="card"><span class="ope-stat">{{ $issues->whereNull('resolved_at')->count() }}</span>Incidencias pendientes</div></div>
@if($issues->whereNull('resolved_at')->count())<p class="warning">El total de peso es provisional hasta resolver las incidencias. Las salidas quedan bloqueadas.</p>@endif
<div class="ope-actions"><a class="button" href="{{ route('operations.departures.index',$lot->id) }}">Programar salidas</a><a href="{{ route('operations.dashboard') }}">Volver a procesos</a></div>
<h2>Incidencias</h2>
@forelse($issues as $issue)<details class="card" @if(!$issue->resolved_at) open @endif><summary><span class="ope-badge {{ $issue->resolved_at ? '' : 'ope-error' }}">{{ $issue->resolved_at ? 'Resuelta' : 'Pendiente' }}</span> #{{ $issue->id }} · {{ $issue->message }}</summary>
@if($issue->resolved_at)<p>{{ $issue->resolution }} · Usuario #{{ $issue->resolved_by }} · {{ $issue->resolved_at }}</p>
@else
@php($context=json_decode($issue->context,true))
@if(isset($context['readings']))<table class="ope-table"><thead><tr><th>Lectura</th><th>Fecha</th><th>Peso kg</th><th>Operario</th><th>Guía cliente</th></tr></thead><tbody>@foreach($context['readings'] as $index=>$reading)<tr><td>#{{ $context['row_ids'][$index] }}</td><td>{{ $reading['date'] }}</td><td>{{ $reading['weight'] }}</td><td>{{ $reading['operator'] }}</td><td>{{ $reading['customer_guide'] }}</td></tr>@endforeach</tbody></table>@endif
@if(\App\Modules\Operations\Services\OperationAccess::supervisor(request()) && $issue->code !== 'empty_lot')<form class="ope-form" method="POST" action="{{ route('operations.issues.resolve',[$lot->id,$issue->id]) }}">@csrf
<label>Resolución<select name="action">@if($issue->code==='reading_conflict')<option value="reading">Elegir lectura de Recepción</option>@endif @if($issue->code==='coverage_conflict')<option value="coverage">Elegir cobertura vigente</option>@endif<option value="exclude">Excluir justificadamente del proceso</option></select></label>
@if($issue->code==='reading_conflict')<label>Lectura correcta<select name="reading_id">@foreach($context['row_ids'] as $id)<option value="{{ $id }}">Lectura #{{ $id }}</option>@endforeach</select></label>@endif
@if($issue->code==='coverage_conflict')<label>Cobertura<select name="coverage_id"><option value="">Seleccionar</option>@foreach($coverages as $coverage)<option value="{{ $coverage->id }}">#{{ $coverage->id }} · {{ $coverage->commune_name }} · {{ $coverage->provider_name_source }} · {{ $coverage->trunk_name }} / {{ $coverage->post_name }}</option>@endforeach</select></label>@endif
<label>Motivo de la resolución<textarea name="reason" minlength="10" maxlength="1000" required></textarea></label><button>Guardar resolución</button></form>
@else<p class="note">La resolución requiere un supervisor. Si no hay lecturas válidas, corrige el archivo y prepara un nuevo proceso.</p>@endif
@endif</details>@empty<p class="note">Sin incidencias. Puedes programar las salidas.</p>@endforelse
<h2>Bultos</h2><form class="ope-actions" method="GET"><input type="search" name="search" value="{{ request('search') }}" placeholder="Código del paquete"><button>Buscar</button></form>
<div class="card table-wrap"><table class="ope-table"><thead><tr><th>Código completo</th><th>Cliente / Servicio</th><th>Comuna</th><th>Peso kg</th><th>Operario</th><th>Guía cliente / Referencia</th><th>Estado</th></tr></thead><tbody>@foreach($packages as $package)<tr><td>{{ $package->tracking }}</td><td>{{ $package->merchant }} / {{ $package->service }}</td><td>{{ $package->commune }}<small> · Cobertura #{{ $package->coverage_id }}</small></td><td>{{ $package->weight ?? 'Pendiente' }}</td><td>{{ $package->operator }}</td><td>{{ $package->customer_guide ?: 'Sin guía cliente' }} / {{ $package->reference }}</td><td>{{ $package->excluded ? 'Excluido' : 'Incluido' }}</td></tr>@endforeach</tbody></table></div>@include('operations::pager',['rows'=>$packages])
@endsection
