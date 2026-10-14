@extends('fleet::layout')
@section('title', 'Alertas de mantenciones')
@section('content')
<h1>Alertas de mantenciones</h1><p class="muted">Empresa activa: {{ $tenant->name }}. Los cambios se aplican a las alertas de sus mantenciones y quedan auditados.</p>
<form class="card" method="post" action="{{ route('fleet.maintenance.settings.update') }}">@csrf @method('PUT')
    <label>Días de anticipación para amarillo<input type="number" name="warning_days" min="0" max="365" required value="{{ old('warning_days', $settings?->warning_days ?? 30) }}"></label>
    <label>Kilómetros de anticipación para amarillo<input type="number" name="warning_km" min="0" max="100000" required value="{{ old('warning_km', $settings?->warning_km ?? 1000) }}"></label>
    <p class="muted">Rojo se muestra cuando la fecha venció o el kilometraje alcanzó el límite. Sin lectura actual se muestra «Sin lectura».</p>
    <button type="submit">Guardar umbrales</button>
</form>
@endsection
