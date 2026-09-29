@extends('fleet::layout')
@section('title', 'Vehículo '.$vehicle->plate)
@push('styles')
<style>
    .vehicle-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}.vehicle-grid .card{margin:0}.vehicle-facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:16px}.vehicle-facts div{border-bottom:1px solid #e5eeee;padding-bottom:10px}.vehicle-facts dt{color:#60757a;font-size:13px}.vehicle-facts dd{font-weight:700;margin:4px 0 0}.vehicle-heading{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.vehicle-heading h1{margin:0}.vehicle-pill{border-radius:99px;padding:6px 10px;background:#e0f6ed;color:#145940;font-size:13px;font-weight:700}.vehicle-pill.off{background:#fce9e4;color:#883a2d}.vehicle-pill.warn{background:#fff2d7;color:#765312}.vehicle-pill.overdue{background:#fbd5d1;color:#9b211a}.vehicle-empty{border:1px dashed #b7c9ca;border-radius:9px;padding:14px;color:#60757a;background:#fbfdfd}.vehicle-docs{list-style:none;padding:0}.vehicle-docs li{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #e5eeee}
</style>
@endpush
@section('content')
<a href="{{ route('fleet.page.operations-fleet') }}">← Flota</a>
<div class="vehicle-heading"><h1>{{ $vehicle->plate }}</h1><span class="vehicle-pill {{ $operational ? '' : 'off' }}">{{ $vehicle->operational_status }}</span><span class="vehicle-pill {{ $operational ? '' : 'off' }}">{{ $operational ? 'Candidato operacional' : 'Fuera del pool' }}</span></div>
<p class="muted">{{ $vehicle->vehicle_type }} · {{ trim($vehicle->brand.' '.$vehicle->model) }} · Código {{ $vehicle->internal_code }}</p>

<div class="vehicle-grid">
    <section class="card"><h2>Propiedad y operación</h2>
        <dl class="vehicle-facts">
            <div><dt>Empresa propietaria</dt><dd>{{ $owner?->name ?? 'Por verificar' }}</dd></div>
            <div><dt>RUT de la empresa</dt><dd>{{ $vehicle->rut_empresa ?: 'Sin registro' }}</dd></div>
            <div><dt>Empresa del servicio seleccionada</dt><dd>{{ $serviceTenant->name }}</dd></div>
            <div><dt>Tipo de propiedad</dt><dd>{{ $vehicle->ownership_type ?: 'Sin registro' }}</dd></div>
        </dl>
        @if($owner && $owner->id !== $vehicle->tenant_id)<p class="fleet-sub muted">El RUT identifica a {{ $owner->code }} como propietaria; el tenant técnico del registro sigue siendo {{ $vehicle->tenant?->code }}. No se ha modificado.</p>@endif
    </section>
    <section class="card"><h2>Identificación y capacidad</h2>
        <dl class="vehicle-facts">
            <div><dt>Año</dt><dd>{{ $vehicle->manufacture_year ?: 'Sin registro' }}</dd></div>
            <div><dt>Color</dt><dd>{{ $vehicle->color ?: 'Sin registro' }}</dd></div>
            <div><dt>VIN</dt><dd>{{ $vehicle->vin ?: 'Sin registro' }}</dd></div>
            <div><dt>Peso máximo</dt><dd>{{ $vehicle->max_weight_kg !== null ? number_format($vehicle->max_weight_kg, 0, ',', '.').' kg' : 'Sin registro' }}</dd></div>
            <div><dt>Pallets máximos</dt><dd>{{ $vehicle->max_pallets ?? 'Sin registro' }}</dd></div>
            <div><dt>Volumen</dt><dd>{{ $vehicle->capacity_m3 !== null ? $vehicle->capacity_m3.' m³' : 'Sin registro' }}</dd></div>
        </dl>
    </section>
</div>

<section class="card"><h2>Kilometraje y mantención</h2>
    <dl class="vehicle-facts"><div><dt>Kilometraje actual</dt><dd>{{ $vehicle->odometer_km !== null ? number_format($vehicle->odometer_km, 0, ',', '.').' km' : 'Sin registro' }}</dd></div><div><dt>Próxima mantención</dt><dd>{{ $vehicle->next_maintenance_at?->format('d/m/Y') ?? 'Sin registro' }}</dd></div></dl>
    @if($inMaintenance)<p><span class="vehicle-pill off">En mantención · no disponible para Programación</span></p>@elseif($plannedMaintenance)<p><span class="vehicle-pill warn">Mantención pendiente/agendada · revisar fecha al programar</span></p>@endif
</section>

<section class="card"><h2>Documentos y vencimientos</h2>
    @php($documentUrl = filter_var($vehicle->document_link, FILTER_VALIDATE_URL) && parse_url($vehicle->document_link, PHP_URL_SCHEME) === 'https' ? $vehicle->document_link : null)
    @if($documentUrl)<p><a href="{{ $documentUrl }}" target="_blank" rel="noopener noreferrer">Abrir documentación existente ↗</a></p>@else<p class="muted">Sin enlace documental registrado.</p>@endif
    <ul class="vehicle-docs">
        @foreach(['Revisión técnica' => $vehicle->technical_inspection_expires_at, 'Permiso de circulación' => $vehicle->circulation_permit_expires_at, 'Seguro' => $vehicle->insurance_expires_at, 'Certificado de gas' => $vehicle->gas_certificate_expires_at] as $label => $expiresAt)
            <li><span>{{ $label }}</span><span>{{ $expiresAt?->format('d/m/Y') ?? 'Sin registro' }} @if($expiresAt && $expiresAt->lt(today()))<span class="vehicle-pill warn">Fecha vencida</span>@endif</span></li>
        @endforeach
    </ul>
    <p class="muted">Las alertas se basan en fechas registradas; no certifican la vigencia del documento.</p>
</section>

@if($vehicle->notes)<section class="card"><h2>Notas existentes</h2><p>{{ $vehicle->notes }}</p></section>@endif

<section class="card"><h2>Historial de mantenciones</h2>
    @if($canReadMaintenance)
        @if($canCreateMaintenance)<p><a class="button" href="{{ route('fleet.maintenance.create', ['vehicle_id' => $vehicle->id]) }}">Nueva mantención</a></p>@endif
        @if($maintenances->isEmpty())<div class="vehicle-empty">Aún no hay mantenciones registradas para este vehículo.</div>
        @else<div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Tipo</th><th>Estado</th><th>Kilometraje</th><th>Proveedor</th><th>Costo real</th><th>Alerta</th><th></th></tr></thead><tbody>
        @foreach($maintenances as $maintenance)
            @php($setting = $maintenanceSettings->get($maintenance->tenant_id))
            @php($alert = ($maintenance->status !== 'cancelled' && ($maintenance->status !== 'closed' || in_array($maintenance->id, $latestClosedIds, true))) ? $maintenanceService->alert($maintenance, $vehicle->odometer_km, $setting?->warning_days ?? 30, $setting?->warning_km ?? 1000) : null)
            <tr><td>{{ $maintenance->scheduled_at?->format('d/m/Y') ?? 'Sin agendar' }}</td><td>{{ $maintenance->maintenance_type }}<br><small>{{ $maintenance->tenant->code }}</small></td><td>{{ \App\Fleet\MaintenanceService::STATUSES[$maintenance->status] }}</td><td>{{ $maintenance->closed_odometer_km ?? $maintenance->reported_odometer_km ?? 'Sin lectura' }}</td><td>{{ $maintenance->provider?->operational_name ?: ($maintenance->provider?->legal_name ?? '—') }}</td><td>{{ $maintenance->actual_cost !== null ? '$'.number_format($maintenance->actual_cost, 0, ',', '.') : '—' }}</td><td>@if($alert)<span class="vehicle-pill {{ $alert['level'] === 'overdue' ? 'overdue' : ($alert['level'] === 'upcoming' ? 'warn' : '') }}">{{ $alert['label'] }}</span>@else—@endif</td><td><a href="{{ route('fleet.maintenance.show', $maintenance) }}">Detalle</a></td></tr>
        @endforeach</tbody></table></div>@endif
    @else<p class="muted">Sin permiso para consultar mantenciones.</p>@endif
</section>

<div class="vehicle-grid">
    @foreach(['Programación', 'Combustible', 'Correos', 'TAG', 'Incidencias y costos'] as $section)
        <section class="card"><h2>{{ $section }}</h2><div class="vehicle-empty">Sin registros en este módulo. Próximamente.</div></section>
    @endforeach
</div>
@endsection
