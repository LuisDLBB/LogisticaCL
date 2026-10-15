@extends('fleet::layout')
@section('title', 'Parámetros de mantenciones')
@section('content')
<h1>Parámetros de mantenciones</h1><p class="muted">Empresa activa: {{ $tenant->name }}. Los cambios se aplican a las alertas de sus mantenciones y quedan auditados.</p>
<form class="card" method="post" action="{{ route('fleet.maintenance.settings.update') }}">@csrf @method('PUT')
    <label>Días de anticipación para amarillo<input type="number" name="warning_days" min="0" max="365" required value="{{ old('warning_days', $settings?->warning_days ?? 30) }}"></label>
    <label>Kilómetros de anticipación para amarillo<input type="number" name="warning_km" min="0" max="100000" required value="{{ old('warning_km', $settings?->warning_km ?? 1000) }}"></label>
    <p class="muted">Rojo se muestra cuando la fecha venció o el kilometraje alcanzó el límite. Sin lectura actual se muestra «Sin lectura».</p>
    <button type="submit">Guardar umbrales</button>
</form>
<form class="card" method="post" action="{{ route('fleet.documents.settings.update') }}">@csrf @method('PUT')
<h2>Papeles por vencer · avisos automáticos</h2><p>Empresa: {{ $tenant->name }}. Revisión técnica, gases y permiso de circulación. Un correo por documento, vencimiento y destinatario. Los vendidos y robados quedan excluidos.</p>
<input type="hidden" name="enabled" value="0"><label><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $documentAlertSettings?->enabled ?? false))> Activar correos automáticos</label>
<label>Días antes del vencimiento<input type="number" name="warning_days" min="1" max="60" required value="{{ old('warning_days', $documentAlertSettings?->warning_days ?? 7) }}"></label>
<label>Correos destinatarios<textarea name="emails" rows="2" placeholder="operaciones@empresa.cl; supervisor@empresa.cl">{{ old('emails', implode('; ', json_decode($documentAlertSettings?->recipients ?? '[]', true) ?? [])) }}</textarea></label>
<p class="muted">La revisión está programada cada hora y requiere activar el programador del servidor. Al renovar el documento se utiliza el nuevo vencimiento. Revisión técnica y gases requieren fecha comprobada con certificado.</p>
@if(!$documentMailReady)<p role="status">Envío pendiente: falta configurar el servicio de correo del servidor. Puedes guardar los destinatarios; todavía no se enviarán correos reales.</p>@endif
<button>Guardar avisos de documentos</button></form>
<section class="card"><h2>KM recomendado por patente</h2><p class="muted">Estos intervalos se muestran como solo lectura en el control semanal. Un campo vacío queda pendiente de definir. Cada cambio registra responsable, fecha y motivo.</p><div style="overflow:auto"><table style="width:100%;font-size:12px"><thead><tr><th>Patente / propietaria</th><th>Última mantención registrada</th><th>Intervalo vigente (KM)</th><th>Motivo del cambio</th><th></th></tr></thead><tbody>
@foreach($parameterVehicles as $entry)
@php($vehicle = $entry['vehicle'])
@php($reference = $references->get($vehicle->id))
<tr><td><strong>{{ $vehicle->plate }}</strong><small style="display:block">{{ $entry['owner']->code }}</small></td><td>{{ $reference['baseline'] !== null ? number_format($reference['baseline'], 0, ',', '.') . ' km' : 'Sin mantención registrada' }}<small style="display:block">{{ $reference['baseline_source'] }}</small></td><td><input form="interval-form-{{ $vehicle->id }}" aria-label="Intervalo de {{ $vehicle->plate }}" type="number" name="interval_km" min="1" max="1000000" value="{{ $reference['interval'] }}" style="width:105px"><small style="display:block">{{ $reference['parameter_from'] ? 'Vigente desde '.\Carbon\Carbon::parse($reference['parameter_from'])->format('d/m/Y') : 'Pendiente' }}</small></td><td><input form="interval-form-{{ $vehicle->id }}" type="text" name="reason" maxlength="2000" required aria-label="Motivo de cambio {{ $vehicle->plate }}" placeholder="Recomendación del fabricante / taller"></td><td><form method="post" id="interval-form-{{ $vehicle->id }}" action="{{ route('fleet.maintenance.interval.update', $vehicle) }}">@csrf @method('PUT')<input type="hidden" name="expected_id" value="{{ $reference['parameter_id'] }}"><button>Guardar</button></form></td></tr>
@endforeach
</tbody></table></div></section>
<section class="card"><details><summary>Historial de intervalos por patente</summary><div style="overflow:auto"><table style="width:100%;font-size:12px"><thead><tr><th>Patente</th><th>Vigencia desde</th><th>Intervalo KM</th><th>Origen / motivo</th><th>Registrado por</th></tr></thead><tbody>@forelse($parameterHistory as $parameter)<tr><td>{{ $parameter->plate }}</td><td>{{ \Carbon\Carbon::parse($parameter->effective_from)->format('d/m/Y') }}</td><td>{{ $parameter->interval_km !== null ? number_format($parameter->interval_km, 0, ',', '.') : 'Pendiente de definir' }}</td><td>{{ $parameter->reason }}</td><td>{{ $parameter->actor_name }}</td></tr>@empty<tr><td colspan="5">Sin parámetros por patente.</td></tr>@endforelse</tbody></table></div></details></section>
@endsection
