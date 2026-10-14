@extends('fleet::layout')
@section('title', 'Nueva mantención')
@push('styles')<style>.maintenance-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}.maintenance-form-grid label{margin:0}.maintenance-form-grid input,.maintenance-form-grid select,.maintenance-form-grid textarea{width:100%}.maintenance-form-grid .wide{grid-column:1/-1}textarea{font:inherit;border:1px solid #b7c9ca;border-radius:7px;padding:9px 11px}</style>@endpush
@section('content')
<a href="{{ route('fleet.vehicles.show', $vehicle) }}">← Ficha {{ $vehicle->plate }}</a>
<h1>Nueva mantención</h1><p class="muted">{{ $vehicle->plate }} · Propietaria {{ $owner->code }}. El registro quedará asociado a esta empresa; la flota operacional continúa consolidada.</p>
<form class="card" method="post" action="{{ route('fleet.maintenance.store') }}">@csrf<input type="hidden" name="vehicle_id" value="{{ $vehicle->id }}">
    @include('fleet::maintenance._planning_fields')
    <p class="muted">Para una ejecución externa, el proveedor será obligatorio al cerrar. @if($providers->isEmpty())Todavía no hay proveedores registrados para {{ $owner->code }}.@endif</p>
    <button type="submit">Guardar mantención</button>
</form>
@endsection
