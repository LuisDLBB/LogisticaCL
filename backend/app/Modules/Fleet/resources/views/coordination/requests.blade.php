@extends('fleet::layout')
@section('title', 'Solicitudes')
@section('content')
<h1>Solicitudes</h1>
<p class="muted">Cada RET conserva la fecha y los antecedentes originales aunque cambien los maestros.</p>
<p><a class="button" href="{{ route('fleet.requests.create') }}">Nueva solicitud</a> <a class="button" href="{{ route('fleet.page.coordination-fixed-pickups') }}">Retiros fijos</a> @if(app(\App\Fleet\FleetAccess::class)->allows(auth()->user(), app(\App\Fleet\FleetAccess::class)->tenant(request()), 'coordination.reprogramming.approve', 3))<a class="button" href="{{ route('fleet.reprogramming.index') }}">Reprogramaciones pendientes</a>@endif</p>
<form method="get" class="card">
    <div class="grid">
        <div><label>RET</label><input type="text" name="ret" value="{{ $filters['ret'] ?? '' }}"></div>
        <div><label>Fecha vigente</label><input type="date" name="fecha" value="{{ $filters['fecha'] ?? '' }}"></div>
        <div><label>Cliente</label><select name="client_id"><option value="">Todos</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(($filters['client_id'] ?? null)==$client->id)>{{ $client->commercial_name }}</option>@endforeach</select></div>
        <div><label>Punto</label><select name="branch_id"><option value="">Todos</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(($filters['branch_id'] ?? null)==$branch->id)>{{ $branch->name }} · {{ $branch->client?->commercial_name }}</option>@endforeach</select></div>
        <div><label>Estado</label><select name="estado"><option value="">Todos</option>@foreach(['requested'=>'Solicitado','scheduled'=>'Agendado','reprogramming_pending'=>'Reprogramar · pendiente','rescheduled'=>'Reagendado','retired'=>'Retirado'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['estado'] ?? null)===$value)>{{ $label }}</option>@endforeach</select></div>
    </div>
    <p><button type="submit">Filtrar</button></p>
</form>
<div class="card table-wrap"><table>
    <thead><tr><th>RET</th><th>Fecha original</th><th>Fecha vigente</th><th>Cliente</th><th>Punto</th><th>Ventana</th><th>Bultos</th><th>Estado</th></tr></thead>
    <tbody>@forelse($rows as $row)<tr>
        <td><a href="{{ route('fleet.requests.show', $row) }}">{{ $row->ret_code }}</a></td>
        <td>{{ $row->requested_date_original->format('d-m-Y') }}</td>
        <td>{{ $row->service_date->format('d-m-Y') }}</td>
        <td>{{ $row->client_name_snapshot }}</td><td>{{ $row->point_name_snapshot }}</td>
        <td>{{ $row->shift }} · {{ substr($row->window_start,0,5) }}–{{ substr($row->window_end,0,5) }}</td>
        <td>{{ $row->packages }}</td><td>{{ $row->status }}</td>
    </tr>@empty<tr><td colspan="8">Aún no hay solicitudes para estos filtros.</td></tr>@endforelse</tbody>
</table></div>
{{ $rows->links() }}
@endsection
