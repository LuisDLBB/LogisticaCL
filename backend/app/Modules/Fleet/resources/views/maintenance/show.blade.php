@extends('fleet::layout')
@section('title', 'Mantención '.$maintenance->vehicle->plate)
@push('styles')
<style>
    .maintenance-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}.maintenance-form-grid label{margin:0}.maintenance-form-grid input,.maintenance-form-grid select,.maintenance-form-grid textarea{width:100%}.maintenance-form-grid .wide{grid-column:1/-1}textarea{font:inherit;border:1px solid #b7c9ca;border-radius:7px;padding:9px 11px;max-width:100%}.maintenance-alert{display:inline-block;border-radius:99px;padding:5px 10px;font-size:12px;font-weight:700;background:#eef3f3;color:#49666a}.maintenance-alert.upcoming{background:#fff0b8;color:#674700}.maintenance-alert.overdue{background:#fbd5d1;color:#9b211a}.maintenance-facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.maintenance-facts div{border-bottom:1px solid #e5eeee;padding:8px 0}.maintenance-facts dt{font-size:13px;color:#60757a}.maintenance-facts dd{margin:3px 0;font-weight:700}.maintenance-event{border-left:3px solid #b7c9ca;padding:8px 12px;margin:10px 0}.maintenance-event small{color:#60757a}
</style>
@endpush
@section('content')
<a href="{{ route('fleet.page.operations-maintenance') }}">← Mantenciones</a> · <a href="{{ route('fleet.vehicles.show', $maintenance->vehicle) }}">Ficha {{ $maintenance->vehicle->plate }}</a>
<h1>Mantención · {{ $maintenance->vehicle->plate }}</h1>
<p>{{ $maintenance->maintenance_type }} · {{ $maintenance->execution_type === 'internal' ? 'Interna' : 'Externa' }} · <strong>{{ \App\Fleet\MaintenanceService::STATUSES[$maintenance->status] }}</strong> · Propietaria {{ $maintenance->tenant->code }}</p>
<span class="maintenance-alert {{ $alert['level'] }}">{{ $alert['label'] }}</span>@if($alert['missing_reading']) <span class="muted">Kilometraje actual: Sin lectura</span>@endif

<section class="card"><h2>Datos y planificación</h2><dl class="maintenance-facts">
    <div><dt>Fecha prevista</dt><dd>{{ $maintenance->scheduled_at?->format('d/m/Y H:i') ?? 'Sin agendar' }}</dd></div>
    <div><dt>Kilometraje informado</dt><dd>{{ $maintenance->reported_odometer_km !== null ? number_format($maintenance->reported_odometer_km, 0, ',', '.').' km' : 'Sin lectura' }}</dd></div>
    <div><dt>Proveedor</dt><dd>{{ $maintenance->provider?->operational_name ?: ($maintenance->provider?->legal_name ?? ($maintenance->execution_type === 'internal' ? 'Ejecución interna' : 'Sin asignar')) }}</dd></div>
    <div><dt>Responsable</dt><dd>{{ $maintenance->responsible?->name ?? 'Sin asignar' }}</dd></div>
    <div><dt>Costo estimado</dt><dd>{{ $maintenance->estimated_cost !== null ? '$'.number_format($maintenance->estimated_cost, 0, ',', '.') : 'Sin estimación' }}</dd></div>
    <div><dt>Próxima fecha</dt><dd>{{ $maintenance->next_due_at?->format('d/m/Y') ?? 'Sin definir' }}</dd></div>
    <div><dt>Próximo kilometraje</dt><dd>{{ $maintenance->next_due_km !== null ? number_format($maintenance->next_due_km, 0, ',', '.').' km' : 'Sin definir' }}</dd></div>
    <div><dt>Inicio real</dt><dd>{{ $maintenance->started_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
</dl>@if($maintenance->notes)<p><strong>Observaciones:</strong> {{ $maintenance->notes }}</p>@endif</section>

@if($maintenance->status === 'closed')
<section class="card"><h2>Cierre</h2><dl class="maintenance-facts">
    <div><dt>Fecha real</dt><dd>{{ $maintenance->closed_at?->format('d/m/Y H:i') }}</dd></div>
    <div><dt>Kilometraje de cierre</dt><dd>{{ number_format($maintenance->closed_odometer_km, 0, ',', '.') }} km</dd></div>
    <div><dt>Costo real</dt><dd>${{ number_format($maintenance->actual_cost, 0, ',', '.') }}</dd></div>
    <div><dt>Comprobante</dt><dd>{{ $maintenance->document_number ?: 'Sin documento' }}</dd></div>
</dl>@if($maintenance->closing_notes)<p><strong>Observaciones de cierre:</strong> {{ $maintenance->closing_notes }}</p>@endif</section>
@endif

@if($canEdit && !in_array($maintenance->status, ['closed', 'cancelled']))
<details class="card"><summary><strong>Editar planificación y estado</strong></summary>
    <form method="post" action="{{ route('fleet.maintenance.update', $maintenance) }}">@csrf @method('PUT')
        @include('fleet::maintenance._planning_fields')
        <p class="muted">La cancelación requiere motivo y conserva todo el historial.</p><button type="submit">Guardar cambios</button>
    </form>
</details>
@endif

@if($maintenance->status === 'in_progress' && $canClose)
<section class="card"><h2>Cerrar mantención</h2><p class="muted">Una ejecución externa requiere proveedor activo. Si hay factura, boleta u OT, indica su número. Sin documento, deja una observación.</p>
    <form method="post" action="{{ route('fleet.maintenance.close', $maintenance) }}">@csrf
        @include('fleet::maintenance._closing_fields')
        <button type="submit">Cerrar mantención</button>
    </form>
</section>
@endif

@if($maintenance->status === 'closed' && $canCorrect)
<details class="card"><summary><strong>Corregir cierre con auditoría</strong></summary><p class="muted">El dato anterior quedará guardado en el historial. Una lectura menor no reducirá automáticamente el odómetro actual.</p>
    <form method="post" action="{{ route('fleet.maintenance.correct', $maintenance) }}">@csrf
        @include('fleet::maintenance._closing_fields')
        <label>Motivo obligatorio de corrección<textarea name="correction_reason" required rows="2">{{ old('correction_reason') }}</textarea></label>
        <button type="submit">Guardar corrección</button>
    </form>
</details>
@endif

<section class="card"><h2>Documentos y respaldos</h2>
    @forelse($maintenance->documents as $document)<p><a href="{{ route('fleet.maintenance.documents.download', [$maintenance, $document]) }}">{{ $document->original_name }}</a> <span class="muted">· {{ $document->kind }} · {{ $document->created_at?->format('d/m/Y H:i') }}</span></p>@empty<p class="muted">Sin respaldos adjuntos.</p>@endforelse
    @if($canEdit && !in_array($maintenance->status, ['closed', 'cancelled']) || $maintenance->status === 'closed' && $canCorrect)
    <form method="post" action="{{ route('fleet.maintenance.documents.upload', $maintenance) }}" enctype="multipart/form-data">@csrf
        <label>Tipo de respaldo<select name="kind"><option value="invoice">Factura</option><option value="receipt">Boleta</option><option value="work_order">Orden de trabajo</option><option value="report">Informe</option><option value="photo">Fotografía</option><option value="other">Otro</option></select></label>
        <label>Archivo (PDF o imagen, máximo 10 MB)<input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp" required></label>
        <button type="submit">Adjuntar respaldo</button>
    </form>
    @endif
</section>

<section class="card"><h2>Historial de eventos</h2>
    @forelse($maintenance->events->sortByDesc('id') as $event)<div class="maintenance-event"><strong>{{ ['created' => 'Creación', 'updated' => 'Actualización', 'cancelled' => 'Cancelación', 'closed' => 'Cierre', 'corrected' => 'Corrección', 'odometer' => 'Lectura de kilometraje', 'reported_odometer' => 'Kilometraje informado', 'document_uploaded' => 'Respaldo adjuntado'][$event->event_type] ?? $event->event_type }}</strong> · {{ $event->created_at?->format('d/m/Y H:i') }} · {{ $event->actor?->name ?? 'Usuario no disponible' }}
        @if($event->odometer_km !== null)<br>{{ number_format($event->odometer_km, 0, ',', '.') }} km @endif
        @if($event->previous_status !== $event->new_status)<br><small>{{ \App\Fleet\MaintenanceService::STATUSES[$event->previous_status] ?? '—' }} → {{ \App\Fleet\MaintenanceService::STATUSES[$event->new_status] ?? '—' }}</small>@endif
        @if($event->details['reason'] ?? null)<br><small>Motivo: {{ $event->details['reason'] }}</small>@endif
    </div>@empty<p class="muted">Sin eventos.</p>@endforelse
</section>
@endsection
