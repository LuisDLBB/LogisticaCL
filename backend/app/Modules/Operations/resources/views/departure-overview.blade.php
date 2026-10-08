@extends('operations::layout')
@section('title','Salidas')
@section('content')
<h1>Salidas</h1>
<p class="intro">Selecciona un proceso para programar las agencias que salen, revisar las guías y descargar su planilla general.</p>
<div class="card" style="margin-bottom:18px"><h2>Reservas guardadas · {{ $reservationCount }} {{ $reservationCount === 1 ? 'bulto' : 'bultos' }}</h2><p class="note">La carga reservada está en bodega y puede incorporarse a un proceso posterior. Abre el proceso de la nueva salida para seleccionar las reservas y sumarlas a la recepción de ese día.</p></div>
<div class="card table-wrap">
    <table class="ope-table">
        <thead><tr><th>Fecha</th><th>Proceso</th><th>Total</th><th>Pendientes de aprobación</th><th>Aprobadas</th><th>Canceladas</th><th>Acción</th></tr></thead>
        <tbody>
        @forelse($lots as $lot)
            @php($counts = $departureCounts->get($lot->id))
            <tr>
                <td>{{ $lot->operation_date }}</td>
                <td>#{{ $lot->id }} · {{ $lot->name }}</td>
                <td>{{ $counts->total ?? 0 }}</td>
                <td>{{ $counts->pending ?? 0 }}</td>
                <td>{{ $counts->approved ?? 0 }}</td>
                <td>{{ $counts->cancelled ?? 0 }}</td>
                <td><a href="{{ route('operations.departures.index', $lot->id) }}">Ver salidas y planilla</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="ope-empty">Todavía no hay procesos preparados. <a href="{{ route('operations.dashboard') }}">Preparar un proceso</a>.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('operations::pager',['rows'=>$lots])
@endsection
