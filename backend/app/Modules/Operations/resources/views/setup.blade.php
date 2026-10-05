@extends('operations::layout')
@section('title','Agencias y configuración de guías')
@section('content')
<h1>Agencias y configuración de guías</h1><p class="intro">Las coberturas se comparten con el sistema existente. Configura sus ubicaciones y recorrido de primera milla.</p>
@if(auth()->user()->isProviderPaymentsAdministrator())<p><a href="{{ route('provider-payments.maintainers.coberturas') }}">Abrir mantenedor de Coberturas</a></p>@endif
@php($supervisor=\App\Modules\Operations\Services\OperationAccess::supervisor(request()))
<details class="card" open>
    <summary>Relación de agencias, troncales y postas · {{ $agencyRoutes->count() }} agencias</summary>
    <p class="note">Cada ID de agencia se relaciona con el campo ID_ComunaMatrizAgencia de Coberturas. Posta 1 y Posta 2 apuntan al mismo catálogo de postas. Esta relación aún no genera guías automáticamente.</p>
    <div class="table-wrap"><table class="ope-table">
        <thead><tr><th>ID agencia</th><th>Agencia y dirección</th><th>Coberturas</th><th>Troncal</th><th>Posta 1</th><th>Posta 2</th></tr></thead>
        <tbody>@forelse($agencyRoutes as $agency)
            <tr>
                <td>{{ $agency->agency_code }}</td>
                <td>{{ $agency->name }}<small> · {{ $agency->address }} · {{ $agency->commune }}</small></td>
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
@if($supervisor)<details class="card" open><summary>Nueva ubicación de origen, agencia o transferencia</summary><form method="POST" action="{{ route('operations.locations.store') }}" class="ope-form">@csrf
<label>Nombre<input name="name" value="{{ old('name') }}" required maxlength="160"></label><label>Proveedor / Agencia<select name="provider_id"><option value="">Centro de distribución / sin proveedor</option>@foreach($providers as $provider)<option value="{{ $provider->id }}">{{ $provider->operational_name ?: $provider->legal_name }}</option>@endforeach</select></label><label>Dirección<input name="address" value="{{ old('address') }}" required maxlength="255"></label><label>Comuna<input name="commune" value="{{ old('commune') }}" required maxlength="150"></label><button>Guardar ubicación</button></form></details>@endif
<h2>Ubicaciones</h2>@forelse($locations as $location)<details class="card"><summary>{{ $location->name }} · {{ $location->address }} · {{ $location->commune }}</summary>@if($supervisor)<form method="POST" action="{{ route('operations.locations.store') }}" class="ope-form">@csrf<input type="hidden" name="id" value="{{ $location->id }}"><label>Nombre<input name="name" value="{{ $location->name }}" required maxlength="160"></label><label>Proveedor<select name="provider_id"><option value="">Sin proveedor</option>@foreach($providers as $provider)<option value="{{ $provider->id }}" @selected($location->provider_id===$provider->id)>{{ $provider->operational_name ?: $provider->legal_name }}</option>@endforeach</select></label><label>Dirección<input name="address" value="{{ $location->address }}" required maxlength="255"></label><label>Comuna<input name="commune" value="{{ $location->commune }}" required maxlength="150"></label><button>Guardar cambios</button></form>@endif</details>@empty<p class="note">Registra el centro de origen y las agencias de destino.</p>@endforelse
@if($supervisor)<details class="card" open><summary>Configurar Troncal / Posta 1 / Posta 2</summary><p class="note">Seleccionar una cobertura y tramo existente crea una nueva versión de su configuración. Las salidas anteriores conservan sus datos. Cada posta debe comenzar en el destino del tramo anterior.</p>
<form method="POST" action="{{ route('operations.configurations.store') }}" class="ope-form">@csrf
<label>Cobertura existente<select name="coverage_id" required><option value="">Seleccionar cobertura</option>@foreach($coverages as $coverage)<option value="{{ $coverage->id }}" @selected(old('coverage_id',$selected?->coverage_id)==$coverage->id)>#{{ $coverage->id }} · {{ $coverage->commune_name }} · {{ $coverage->provider_name_source }} · {{ $coverage->trunk_name }} / {{ $coverage->post_name }}</option>@endforeach</select></label>
<label>Tramo<select name="role"><option value="troncal" @selected(old('role',$selected?->role)==='troncal')>Troncal</option><option value="posta1" @selected(old('role',$selected?->role)==='posta1')>Posta 1</option><option value="posta2" @selected(old('role',$selected?->role)==='posta2')>Posta 2</option></select></label><label>Nombre agencia / destino del tramo<input name="name" value="{{ old('name',$selected?->name) }}" required maxlength="160"></label>
@foreach(['origin_id'=>'Origen predefinido','destination_id'=>'Destino predefinido'] as $field=>$label)<label>{{ $label }}<select name="{{ $field }}" required><option value="">Seleccionar ubicación</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(old($field,$selected?->$field)==$location->id)>{{ $location->name }} · {{ $location->address }} · {{ $location->commune }}</option>@endforeach</select></label>@endforeach
<label class="check"><input type="checkbox" name="requires_customer_guide" value="1" @checked(old('requires_customer_guide',$selected?->requires_customer_guide))> Exigir guía del cliente</label>
<label class="ope-full">Glosa configurable<textarea name="template" maxlength="500" required>{{ old('template',$selected?->template ?? '{cliente} / {servicio}: {bultos} bultos, {peso} kg') }}</textarea><small class="note">Campos disponibles: {cliente}, {servicio}, {bultos}, {peso}, {guia_cliente}, {referencia}.</small></label><button>Guardar configuración</button></form></details>@endif
<h2>Tramos configurados</h2><div class="card table-wrap"><table class="ope-table"><thead><tr><th>Cobertura</th><th>Troncal / Posta</th><th>Tramo</th><th>Agencia</th><th>Origen → Destino</th><th>Guía cliente</th><th>Versión</th><th></th></tr></thead><tbody>@forelse($configurations as $configuration)<tr><td>#{{ $configuration->coverage_id }} {{ $configuration->commune_name }}</td><td>{{ $configuration->trunk_name }} / {{ $configuration->post_name }}</td><td>{{ ['troncal'=>'Troncal','posta1'=>'Posta 1','posta2'=>'Posta 2'][$configuration->role] }}</td><td>{{ $configuration->name }}</td><td>{{ $configuration->origin_name }} → {{ $configuration->destination_name }}</td><td>{{ $configuration->requires_customer_guide ? 'Obligatoria' : 'Opcional' }}</td><td>{{ $configuration->version }}</td><td>@if($supervisor)<a href="{{ route('operations.setup',['configuration'=>$configuration->id]) }}">Editar</a>@endif</td></tr>@empty<tr><td colspan="8" class="ope-empty">Configura el Troncal y las postas que utiliza cada agencia.</td></tr>@endforelse</tbody></table></div>
@endsection
