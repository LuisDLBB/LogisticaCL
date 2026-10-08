@extends('operations::layout')
@section('title','Agencias y rutas de guías')
@section('content')
<h1>Agencias y rutas de guías</h1><p class="intro">Las rutas se preparan automáticamente a partir de los bultos de Recepción, cruzados con Maestro Geolize. La cobertura de cada bulto determina su agencia, troncal y postas; no necesitas configurar guías aquí.</p>
<p class="note">Cada posta puede tener su propio origen. Si no está registrado, se usa el destino de la troncal. <a href="{{ route('operations.post-origins') }}">Revisar orígenes de postas</a>.</p>
@if(auth()->user()->isProviderPaymentsAdministrator())<p><a href="{{ route('provider-payments.maintainers.coberturas') }}">Abrir mantenedor de Coberturas</a></p>@endif
@php($supervisor=\App\Modules\Operations\Services\OperationAccess::supervisor(request()))
<details class="card" open>
    <summary>Relación de agencias, troncales y postas · {{ $agencyRoutes->count() }} agencias</summary>
    <p class="note">Cada ID de agencia se relaciona con el campo ID_ComunaMatrizAgencia de Coberturas. Esta relación define el recorrido y el transporte de las guías nuevas. <a href="{{ route('operations.transport') }}">Editar patentes y choferes</a>.</p>
    <div class="table-wrap"><table class="ope-table">
        <thead><tr><th>ID agencia</th><th>Agencia y dirección</th><th>Coberturas</th><th>Troncal</th><th>Posta 1</th><th>Posta 2</th></tr></thead>
        <tbody>@forelse($agencyRoutes as $agency)
            <tr id="agency-{{ $agency->id }}" style="scroll-margin-top:90px">
                <td>{{ $agency->agency_code }}</td>
                <td>{{ $agency->name }}<small> · {{ $agency->address }} · {{ $agency->commune }}</small>@if($supervisor)<details><summary>Corregir dirección de entrega</summary><form method="POST" action="{{ route('operations.agencies.update',$agency->id) }}" class="ope-form">@csrf @method('PUT')<label>Dirección<input name="address" value="{{ $agency->address }}" required maxlength="255"></label><label>Comuna<input name="commune" value="{{ $agency->commune }}" required maxlength="150"></label><button>Guardar dirección</button></form></details>@endif</td>
                <td>{{ number_format($coverageCounts->get($agency->agency_code, 0), 0, ',', '.') }}</td>
                <td>{{ $agency->trunk_code }} · {{ $agency->trunk_name }}</td>
                <td>{{ $agency->post_code }} · {{ $agency->post_name }}@if(! $agency->post_is_active) <small>Inactiva</small>@endif</td>
                <td>{{ $agency->second_post_code ? $agency->second_post_code.' · '.$agency->second_post_name : '—' }}</td>
            </tr>
        @empty<tr><td colspan="6" class="ope-empty">No hay agencias cargadas.</td></tr>@endforelse</tbody>
    </table></div>
</details>
<details class="card">
    <summary>Troncales de origen · {{ $trunks->count() }}</summary>
    <div class="table-wrap"><table class="ope-table">
        <thead><tr><th>ID</th><th>Troncal</th><th>Origen</th><th>Destino</th><th>Patente</th><th>Chofer</th></tr></thead>
        <tbody>@forelse($trunks as $trunk)
            <tr><td>{{ $trunk->trunk_code }}</td><td>{{ $trunk->name }}</td><td>{{ $trunk->origin_address }} · {{ $trunk->origin_commune }}</td><td>{{ $trunk->destination_address }} · {{ $trunk->destination_commune }}</td><td>{{ $trunk->plate ?: '—' }}</td><td>{{ $trunk->driver_name ?: '—' }}<small>{{ $trunk->driver_rut }}</small></td></tr>
        @empty<tr><td colspan="6" class="ope-empty">No hay troncales cargadas.</td></tr>@endforelse</tbody>
    </table></div>
</details>
<details class="card">
    <summary>Postas · {{ $posts->count() }}</summary>
    <div class="table-wrap"><table class="ope-table">
        <thead><tr><th>ID</th><th>Posta</th><th>Estado</th><th>Patente</th><th>Chofer</th></tr></thead>
        <tbody>@forelse($posts as $post)
            <tr><td>{{ $post->post_code }}</td><td>{{ $post->name }}</td><td>{{ $post->is_active ? 'Activa' : 'Inactiva' }}</td><td>{{ $post->plate ?: '—' }}</td><td>{{ $post->driver_name ?: '—' }}<small>{{ $post->driver_rut }}</small></td></tr>
        @empty<tr><td colspan="5" class="ope-empty">No hay postas cargadas.</td></tr>@endforelse</tbody>
    </table></div>
</details>
<h2>Ubicaciones usadas en guías</h2>
@forelse($locations as $location)<div class="card">{{ $location->name }} · {{ $location->address }} · {{ $location->commune }}</div>@empty<p class="note">Las ubicaciones se incorporarán al preparar un proceso con bultos recibidos.</p>@endforelse
<h2>Tramos preparados desde Recepción</h2>
@php($configuredAgencyLegs=$configurations->groupBy(fn($row) => ($row->agency_name ?? $row->name).'|'.$row->role))
<div class="card table-wrap"><table class="ope-table"><thead><tr><th>Agencia</th><th>Coberturas</th><th>Transporte del tramo</th><th>Tramo</th><th>Origen → Destino</th></tr></thead><tbody>
@forelse($configuredAgencyLegs as $leg)@php($configuration=$leg->first())<tr><td>{{ $configuration->agency_name ?? $configuration->name }}</td><td>{{ $leg->count() }}</td><td>{{ $configuration->role==='troncal' ? $configuration->trunk_name : ($configuration->role==='posta1' ? $configuration->first_post_name : $configuration->second_post_name) }}</td><td>{{ ['troncal'=>'Troncal','posta1'=>'Posta 1','posta2'=>'Posta 2','posta3'=>'Posta 3'][$configuration->role] ?? $configuration->role }}</td><td>{{ $configuration->origin_name }} → {{ $configuration->destination_name }}</td></tr>
@empty<tr><td colspan="5" class="ope-empty">Los tramos se mostrarán aquí cuando prepares un proceso con bultos de Recepción.</td></tr>@endforelse
</tbody></table></div>
@endsection
