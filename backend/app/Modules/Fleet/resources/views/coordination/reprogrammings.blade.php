@extends('fleet::layout')
@section('title', 'Reprogramaciones pendientes')
@section('content')
<h1>Reprogramaciones pendientes · Postventa</h1>
<p>Postventa registra la respuesta del cliente antes de aprobar o rechazar.</p>
@forelse($rows as $row)
<div class="card">
    <h2><a href="{{ route('fleet.requests.show', $row->serviceRequest) }}">{{ $row->serviceRequest->ret_code }}</a> · {{ $row->serviceRequest->client_name_snapshot }}</h2>
    <p><strong>Punto:</strong> {{ $row->serviceRequest->point_name_snapshot }}<br><strong>Vigente:</strong> {{ $row->previous_date->format('d-m-Y') }} · {{ substr($row->previous_window_start,0,5) }}–{{ substr($row->previous_window_end,0,5) }}<br><strong>Propuesta:</strong> {{ $row->proposed_date->format('d-m-Y') }} · {{ substr($row->proposed_window_start,0,5) }}–{{ substr($row->proposed_window_end,0,5) }}<br><strong>Motivo:</strong> {{ $row->reason }}<br><strong>Solicitante:</strong> usuario #{{ $row->requested_by_user_id }}</p>
    <form method="post" action="{{ route('fleet.reprogramming.decide', $row) }}">
        @csrf
        <label>Respuesta del cliente</label><textarea name="customer_response" rows="2" required></textarea>
        <label>Observación de Postventa (opcional)</label><textarea name="decision_notes" rows="2"></textarea>
        <p><button name="decision" value="approved">Aprobar</button> <button name="decision" value="rejected">Rechazar</button></p>
    </form>
</div>
@empty<div class="card">No hay reprogramaciones pendientes.</div>@endforelse
@endsection
