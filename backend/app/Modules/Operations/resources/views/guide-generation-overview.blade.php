@extends('operations::layout')
@section('title','Generación de Guías')
@section('content')
<h1>Generación de Guías</h1>
<p class="intro">Selecciona una fecha y un proceso para ver sus salidas, emitir las guías aprobadas en Bsale y descargar la planilla con los números generados.</p>
<form method="GET" action="{{ route('operations.guide-generation.overview') }}" class="card ope-actions">
    <label>Fecha de salida <select name="fecha" onchange="this.form.submit()">
        @foreach($dates as $date)<option value="{{ $date }}" @selected($selectedDate === $date)>{{ \Carbon\Carbon::parse($date)->format('d-m-Y') }}</option>@endforeach
    </select></label>
</form>
<div class="card table-wrap"><table class="ope-table"><thead><tr><th>Fecha de salida</th><th>Proceso</th><th>Operación</th></tr></thead><tbody>
    @forelse($processes as $process)
        <tr><td>{{ \Carbon\Carbon::parse($process->departure_date)->format('d-m-Y') }}</td><td>#{{ $process->id }} · {{ $process->name }}</td><td><a href="{{ route('operations.departures.index', ['lot' => $process->id, 'fecha' => $selectedDate]) }}#generacion-guias">Ver y generar guías</a></td></tr>
    @empty
        <tr><td colspan="3" class="ope-empty">No hay salidas programadas para esta fecha.</td></tr>
    @endforelse
</tbody></table></div>
@endsection
