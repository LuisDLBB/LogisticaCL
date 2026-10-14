@extends('fleet::layout')
@section('title', 'Vehículo '.$vehicle->plate)
@push('styles')
<style>
.vehicle-sheet{max-width:1400px;margin:auto}.vehicle-sheet-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin:8px 0 14px}.vehicle-heading{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.vehicle-heading h1{font-size:24px;margin:0}.vehicle-sheet-header p{font-size:12px;color:#60757a;margin:4px 0 0}.vehicle-owner{font-size:12px;font-weight:700;padding:5px 8px;background:#eef4f5;border-radius:6px}.vehicle-columns{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(0,1fr);gap:14px;align-items:start}.vehicle-column{display:grid;gap:12px;min-width:0}.vehicle-sheet .card{margin:0;padding:14px;border-radius:12px}.vehicle-sheet h2{font-size:16px;margin:0 0 10px}.vehicle-sheet h3{font-size:13px;margin:14px 0 8px}.vehicle-sheet .muted{font-size:12px;margin:6px 0}.vehicle-pill{display:inline-block;border-radius:7px;padding:4px 7px;background:#e0f6ed;color:#145940;font-size:11px;font-weight:700}.vehicle-pill.off,.vehicle-pill.overdue{background:#fce6e8;color:#a72c39}.vehicle-pill.warn{background:#fff2d7;color:#765312}.vehicle-pill.unknown{background:#eef3f4;color:#60757a}.vehicle-panel-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px}.vehicle-panel-heading h2{margin:0}.vehicle-panel-heading a{font-size:12px}.vehicle-cost-total{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;margin-bottom:10px;border-radius:8px;background:#eff8f6;font-size:12px}.vehicle-cost-total strong{font-size:19px;color:#12634c}.vehicle-sheet table{font-size:12px;min-width:0;width:100%}.vehicle-sheet th{font-size:11px;padding:7px 8px;white-space:nowrap}.vehicle-sheet td{padding:9px 8px;vertical-align:top}.vehicle-sheet td small{display:block;font-size:11px;color:#60757a;margin-top:3px;line-height:1.35}.vehicle-history-date{width:85px;white-space:nowrap}.vehicle-history-cost{text-align:right;white-space:nowrap}.vehicle-history-work{min-width:145px}.vehicle-history-work a{font-weight:700}.vehicle-summary{margin-top:4px;font-size:11px;line-height:1.4;overflow-wrap:anywhere}.vehicle-docs{list-style:none;padding:0;margin:0}.vehicle-docs li{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;border-bottom:1px solid #edf1f2;padding:9px 0;font-size:12px;align-items:center}.vehicle-docs li>span:last-child{text-align:right}.vehicle-docs li strong{display:block;font-size:12px;margin-bottom:3px}.vehicle-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 12px;margin:0}.vehicle-facts>div{min-width:0;border-bottom:1px solid #edf1f2;padding:7px 0}.vehicle-facts dt{color:#60757a;font-size:11px}.vehicle-facts dd{font-size:12px;font-weight:700;margin:3px 0 0;overflow-wrap:anywhere}.vehicle-other-modules summary{font-size:13px;font-weight:700;cursor:pointer}.vehicle-other-modules ul{font-size:12px;line-height:1.8;margin:8px 0 0;padding-left:18px}.vehicle-availability td:last-child{overflow-wrap:anywhere}.vehicle-back{font-size:12px}.ret-embedded .vehicle-back{display:none}.ret-embedded main>.notice{padding:8px 12px;font-size:12px;margin-bottom:10px}@media(max-width:850px){.vehicle-columns{grid-template-columns:1fr}.vehicle-sheet .card{padding:12px}.vehicle-history-work{min-width:125px}}@media(max-width:480px){.vehicle-sheet table{font-size:11px}.vehicle-sheet td,.vehicle-sheet th{padding:7px 5px}.vehicle-history-date{width:65px}.vehicle-history-work{min-width:105px}.vehicle-facts{gap:6px}.vehicle-heading h1{font-size:22px}}
.vehicle-sheet{max-width:1600px}.vehicle-overview{display:grid;grid-template-columns:1.1fr 1.1fr .8fr;gap:12px;align-items:start;margin-bottom:14px}.vehicle-columns{grid-template-columns:1.2fr 1fr 1fr;gap:12px}.vehicle-notes{font-size:12px;line-height:1.6;margin:0;white-space:pre-wrap;overflow-wrap:anywhere;max-height:180px;overflow:auto}.vehicle-history-scroll{max-height:420px;overflow:auto}.vehicle-sheet .vehicle-history-work{min-width:100px}.vehicle-sheet .vehicle-history-date{width:auto}.vehicle-sheet td,.vehicle-sheet th{padding:7px 6px}.vehicle-sheet-header{position:sticky;top:0;z-index:2;background:white;padding:8px 0;border-bottom:1px solid #e2ebed}.vehicle-overview .vehicle-facts{gap:5px 10px}.vehicle-overview .vehicle-facts>div{padding:5px 0}.vehicle-sheet .vehicle-docs li{padding:7px 0}@media(max-width:1150px){.vehicle-columns{grid-template-columns:1.15fr 1fr}.vehicle-activity-column{grid-column:1/-1;grid-template-columns:repeat(3,minmax(0,1fr));align-items:start}.vehicle-overview{grid-template-columns:repeat(2,minmax(0,1fr))}.vehicle-overview>section:last-child{grid-column:1/-1}}@media(max-width:750px){.vehicle-columns,.vehicle-overview,.vehicle-activity-column{grid-template-columns:1fr}.vehicle-activity-column,.vehicle-overview>section:last-child{grid-column:auto}.vehicle-sheet-header{position:static}}

.vehicle-columns{grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(0,1.35fr)}.vehicle-activity-column{grid-column:auto;grid-template-columns:1fr}.vehicle-notes{max-height:100px}.vehicle-repair-summary{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.vehicle-repair-summary h2{font-size:12px;margin:0 5px 0 0}.vehicle-repair-stat{display:flex;align-items:center;gap:7px;padding:6px 9px;border:1px solid #d9e8e9;background:#f1f8f8;border-radius:8px;font-size:11px}.vehicle-repair-stat strong{font-size:17px;color:#007c80}.vehicle-repair-stat small{display:block;color:#60757a;font-size:10px}.vehicle-repair-help{flex-basis:100%;font-size:10px;color:#60757a;margin:0}.vehicle-sheet-header{position:static}@media(max-width:1150px){.vehicle-columns{grid-template-columns:repeat(2,minmax(0,1fr))}.vehicle-maintenance-column{grid-column:1/-1}.vehicle-activity-column{grid-column:auto;grid-template-columns:1fr}}@media(max-width:750px){.vehicle-columns{grid-template-columns:1fr}.vehicle-maintenance-column{grid-column:auto}}
</style>
@endpush
@section('content')
@php($today = today('America/Santiago'))
@php($closedCosts = $maintenances->where('status', 'closed')->whereNotNull('actual_cost'))
@php($nextDates = collect([$vehicle->next_maintenance_at])->merge($maintenances->whereIn('id', $latestClosedIds)->pluck('next_due_at'))->filter()->sortBy(fn ($date) => $date->timestamp))
@php($nextDate = $nextDates->first())
@php($nextKm = $maintenanceReference['baseline'] !== null && $maintenanceReference['interval'] !== null ? $maintenanceReference['baseline'] + $maintenanceReference['interval'] : null)
<div class="vehicle-sheet">
<a class="vehicle-back" href="{{ route('fleet.page.operations-fleet') }}">← Flota</a>
<header class="vehicle-sheet-header">
    <div><div class="vehicle-heading"><h1>{{ $vehicle->plate }}</h1><span class="vehicle-owner">{{ $owner?->code ?? 'Por verificar' }}</span><span class="vehicle-pill {{ $operational ? '' : 'off' }}">{{ $vehicle->operational_status }}</span></div><p>{{ trim($vehicle->brand.' '.$vehicle->model) }} · {{ $vehicle->vehicle_type }}</p></div>
    @if($inMaintenance)<span class="vehicle-pill off">En mantención · no disponible para Programación</span>@elseif($plannedMaintenance)<span class="vehicle-pill warn">Mantención pendiente/agendada</span>@endif

@if($canReadMaintenance)
<div class="vehicle-repair-summary" aria-label="Resumen de reparaciones">
<h2>Reparaciones cerradas</h2>
@foreach($repairSummary['years'] as $year)
<div class="vehicle-repair-stat"><span>{{ $year['year'] }}<small>{{ $year['has_history'] ? 'Historial registrado' : 'Sin historial registrado' }}</small></span><strong>{{ $year['count'] }}</strong></div>
@endforeach
@foreach($repairSummary['months'] as $month)
<div class="vehicle-repair-stat"><span>{{ $month['label'] }}<small>{{ $month['year'] }}</small></span><strong>{{ $month['count'] }}</strong></div>
@endforeach
<p class="vehicle-repair-help">Tipo «Reparación». No incluye mantenciones periódicas ni trabajos pendientes. El historial no distingue reparaciones mayores.</p>
</div>
@endif
</header>
<div class="vehicle-columns">
<div class="vehicle-column vehicle-identification-column">
<section class="card"><h2>Identificación y capacidad</h2><dl class="vehicle-facts">
    <div><dt>Año / color</dt><dd>{{ $vehicle->manufacture_year ?: 'Sin registro' }} · {{ $vehicle->color ?: 'Sin registro' }}</dd></div><div><dt>Código interno</dt><dd>{{ $vehicle->internal_code ?: 'Sin registro' }}</dd></div><div><dt>VIN</dt><dd>{{ $vehicle->vin ?: 'Sin registro' }}</dd></div><div><dt>Carga útil máxima</dt><dd>{{ $vehicle->payload_capacity_kg ? number_format($vehicle->payload_capacity_kg, 0, ',', '.').' kg' : 'Sin registro' }}</dd></div><div><dt>Pallets máximos</dt><dd>{{ $vehicle->max_pallets ?? 'Sin registro' }}</dd></div><div><dt>Volumen</dt><dd>{{ ($vehicle->capacity_m3 ?: ($vehicle->max_volume_liters ? $vehicle->max_volume_liters / 1000 : null)) ? number_format($vehicle->capacity_m3 ?: $vehicle->max_volume_liters / 1000, 1, ',', '.').' m³' : 'Sin registro' }}</dd></div>
</dl><details class="vehicle-other-modules" style="margin-top:12px"><summary>Propiedad y datos adicionales</summary><dl class="vehicle-facts"><div><dt>Empresa propietaria</dt><dd>{{ $owner?->name ?? 'Por verificar' }}</dd></div><div><dt>RUT de la empresa</dt><dd>{{ $vehicle->rut_empresa ?: 'Sin registro' }}</dd></div><div><dt>Empresa del servicio seleccionada</dt><dd>{{ $serviceTenant->name }}</dd></div><div><dt>Tipo de propiedad</dt><dd>{{ $vehicle->ownership_type ?: 'Sin registro' }}</dd></div><div><dt>Peso bruto registrado</dt><dd>{{ $vehicle->max_weight_kg !== null ? number_format($vehicle->max_weight_kg, 0, ',', '.').' kg' : 'Sin registro' }}</dd></div></dl></details><h3>Notas</h3><p class="vehicle-notes">{{ $vehicle->notes ?: 'Sin notas registradas.' }}</p></section>
<section class="card"><h2>Kilometraje y próxima mantención</h2><dl class="vehicle-facts"><div><dt>KM última mantención</dt><dd>{{ $maintenanceReference['baseline'] !== null ? number_format($maintenanceReference['baseline'], 0, ',', '.').' km' : 'Sin registro' }}</dd></div><div><dt>Intervalo recomendado</dt><dd>{{ $maintenanceReference['interval'] !== null ? number_format($maintenanceReference['interval'], 0, ',', '.').' km' : 'Pendiente de parametrizar' }}</dd></div><div><dt>Kilometraje actual</dt><dd>{{ $vehicle->odometer_km !== null ? number_format($vehicle->odometer_km, 0, ',', '.').' km' : 'Sin registro' }}</dd></div><div><dt>Fecha próxima</dt><dd>{{ $nextDate?->format('d/m/Y') ?? 'Sin registro' }}</dd></div><div><dt>Próxima por kilometraje</dt><dd>{{ $nextKm !== null ? number_format($nextKm, 0, ',', '.').' km' : 'Sin registro' }}</dd></div></dl><p class="muted">{{ $maintenanceReference['baseline_source'] }}</p></section>
<section class="card"><details><summary>Historial de kilometrajes · {{ $weeklyReadings->count() }}</summary><div class="table-wrap vehicle-history-scroll"><table><thead><tr><th>Semana</th><th>KM / observación</th><th>Responsable</th></tr></thead><tbody>@forelse($weeklyReadings as $reading)<tr><td>{{ \Carbon\Carbon::parse($reading->week_start)->format('d/m/Y') }}</td><td>{{ number_format($reading->odometer_km, 0, ',', '.') }} km<small>{{ $reading->accepted ? 'Lectura válida' : implode(' · ', json_decode($reading->issues ?? '[]', true)) }}</small><small>{{ $reading->notes }}</small></td><td>{{ $reading->actor_name }}</td></tr>@empty<tr><td colspan="3">Sin lecturas semanales.</td></tr>@endforelse</tbody></table></div></details></section>
</div>
<div class="vehicle-column vehicle-activity-column">
<section class="card"><h2>Documentos y vencimientos</h2>
@if($canUploadDocuments)
<details style="margin:10px 0;padding:10px;background:#f0f8f8;border-radius:8px"><summary style="cursor:pointer;font-weight:bold;color:#007981">Adjuntar documento · actualizar fechas</summary>
<form method="post" action="{{ route('fleet.vehicles.documents.store', $vehicle) }}" enctype="multipart/form-data" style="margin-top:10px" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Leyendo documento…'">
@csrf
<label for="vehicle-document">PDF del vehículo</label><input id="vehicle-document" name="document" type="file" accept="application/pdf,.pdf" required style="width:100%;margin:7px 0">
<small style="display:block;margin-bottom:9px">Revisión técnica, gases y homologación: al guardar, se actualizan las fechas que puedan leerse y validarse para esta patente. Otros PDF quedan visibles para revisión. Los documentos anteriores se conservan. Hasta 10 MB.</small>
<button type="submit" class="btn">Guardar documento y actualizar fechas</button>
@error('document')<p role="alert">{{ $message }}</p>@enderror
</form></details>
@endif

    <ul class="vehicle-docs">
    @foreach(['technical_inspection_expires_at' => 'Revisión técnica', 'circulation_permit_expires_at' => 'Permiso de circulación', 'insurance_expires_at' => 'Seguro', 'gas_certificate_expires_at' => 'Certificado de gases'] as $expiryField => $label)
        @php($expiresAt = $vehicle->$expiryField)
        @php($verifiedExpiry = $vehicleDocuments->map(fn ($document) => $document->vehicle_details['verified_expirations'][$expiryField] ?? null)->filter(fn ($expiry) => $expiry && $expiry['date'] === $expiresAt?->toDateString())->first())
        @php($needsVerification = in_array($expiryField, ['technical_inspection_expires_at', 'gas_certificate_expires_at'], true) && $expiresAt && ! $verifiedExpiry)
        @php($expirationState = ! $expiresAt ? 'unknown' : ($expiresAt->lt($today) ? 'overdue' : ($expiresAt->lte($today->copy()->addDays(30)) ? 'warn' : '')))
        <li><span>{{ $label }}{{ ($verifiedExpiry['type'] ?? '') === 'homologation' ? ' · homologación' : '' }}</span><span><strong>{{ $verifiedExpiry['display'] ?? $expiresAt?->format('d/m/Y') ?? 'Sin registro' }}</strong><span class="vehicle-pill {{ $needsVerification ? 'unknown' : $expirationState }}">{{ $needsVerification ? 'Por verificar con certificado' : ['unknown' => 'Sin registro', 'overdue' => 'Vencido', 'warn' => 'Por vencer', '' => 'Vigente'][$expirationState] }}</span></span></li>
    @endforeach
    </ul>
    @php($documentUrl = filter_var($vehicle->document_link, FILTER_VALIDATE_URL) && parse_url($vehicle->document_link, PHP_URL_SCHEME) === 'https' ? $vehicle->document_link : null)
    @if($documentUrl)<p class="muted"><a href="{{ $documentUrl }}" target="_blank" rel="noopener noreferrer">Abrir documentación existente ↗</a></p>@endif
<h3>Pólizas y archivos PDF</h3>
    @include('fleet::vehicles._documents', ['documents' => $vehicleDocuments, 'compact' => true])
</section>
<section class="card"><h2>Conductores y servicios</h2>
<p class="muted">Últimas asignaciones de servicios registradas en {{ $serviceTenant->code }}.</p>
@if($canReadDriverHistory)
<div class="vehicle-history-scroll"><table><thead><tr><th>Fecha</th><th>Conductor</th><th>Servicio</th></tr></thead><tbody>
@forelse($driverAssignments as $assignment)
<tr><td class="vehicle-history-date">{{ $assignment->service_date?->format('d/m/Y') ?? 'Sin fecha' }}</td><td>{{ $assignment->driver?->name ?? 'Sin conductor' }}</td><td>#{{ $assignment->id }}<small>{{ ['requested' => 'Solicitado', 'scheduled' => 'Agendado', 'retired' => 'Retirado', 'cancelled' => 'Anulado', 'rescheduled' => 'Reagendado'][$assignment->status] ?? 'Registrado' }}</small></td></tr>
@empty<tr><td colspan="3">Sin asignaciones de conductor registradas.</td></tr>@endforelse
</tbody></table></div>
@else<p class="muted">Sin permiso para consultar asignaciones de conductores.</p>@endif
</section>
<section class="card"><h2>Historial de disponibilidad</h2>
    @if($vehicle->availability_reason)<p class="muted"><strong>Motivo actual:</strong> {{ $vehicle->availability_reason }}</p>@endif
    @if($statusEvents->isEmpty())<p class="muted">Sin cambios de disponibilidad registrados.</p>
    @else<div class="table-wrap vehicle-history-scroll"><table class="vehicle-availability"><thead><tr><th>Fecha</th><th>Estado / motivo</th><th>Responsable</th></tr></thead><tbody>@foreach($statusEvents as $event)<tr><td class="vehicle-history-date">{{ \Carbon\Carbon::parse($event->created_at)->timezone('America/Santiago')->format('d/m/Y') }}<small>{{ \Carbon\Carbon::parse($event->created_at)->timezone('America/Santiago')->format('H:i') }}</small></td><td><strong>{{ json_decode($event->after, true)['operational_status'] ?? '' }}</strong><div class="vehicle-summary">{{ $event->reason }}</div></td><td>{{ $event->actor_name ?? 'Usuario no disponible' }}</td></tr>@endforeach</tbody></table></div>@endif
</section>
</div>
<div class="vehicle-column vehicle-maintenance-column">
<section class="card">
    <div class="vehicle-panel-heading"><h2>Historial de mantenciones y reparaciones</h2>@if($canCreateMaintenance)<a data-ret-modal href="{{ route('fleet.maintenance.create', ['vehicle_id' => $vehicle->id]) }}">+ Nueva mantención</a>@endif</div>
    @if($canReadMaintenance)
        <div class="vehicle-cost-total"><span>Total gastado registrado<small style="display:block;color:#60757a">Cierres con costo real · {{ $closedCosts->count() }} registros</small></span><strong>${{ number_format($closedCosts->sum('actual_cost'), 0, ',', '.') }}</strong></div>
        @if($maintenances->where('status', 'closed')->whereNull('actual_cost')->isNotEmpty())<p class="muted">Hay trabajos cerrados sin costo informado; no están incluidos en el total.</p>@endif
        @if($maintenances->isEmpty())<p class="muted">Aún no hay mantenciones registradas para este vehículo.</p>
        @else<div class="table-wrap vehicle-history-scroll"><table><thead><tr><th>Fecha</th><th>Trabajo / resumen</th><th>Taller / costo</th></tr></thead><tbody>
            @foreach($maintenances->sortByDesc(fn ($item) => ($item->closed_at ?? $item->started_at ?? $item->scheduled_at ?? $item->historicalImport?->requested_at ?? $item->created_at)?->timestamp) as $maintenance)
            @php($setting = $maintenanceSettings->get($maintenance->tenant_id))
            @php($alert = ($maintenance->status !== 'cancelled' && ($maintenance->status !== 'closed' || in_array($maintenance->id, $latestClosedIds, true))) ? $maintenanceService->alert($maintenance, $vehicle->odometer_km, $setting?->warning_days ?? 30, $setting?->warning_km ?? 1000) : null)
            <tr><td class="vehicle-history-date">{{ ($maintenance->closed_at ?? $maintenance->started_at ?? $maintenance->scheduled_at)?->timezone('America/Santiago')->format('d/m/Y') ?? $maintenance->historicalImport?->requested_at?->format('d/m/Y') ?? $maintenance->created_at?->timezone('America/Santiago')->format('d/m/Y') ?? 'Sin fecha' }}<small>{{ $maintenance->closed_at ? 'Cierre' : ($maintenance->started_at ? 'Ingreso' : ($maintenance->scheduled_at ? 'Agenda' : 'Solicitud')) }}</small></td>
                <td class="vehicle-history-work"><a href="{{ route('fleet.maintenance.show', $maintenance) }}">{{ $maintenance->maintenance_type }}</a><div class="vehicle-summary">{{ \Illuminate\Support\Str::limit($maintenance->closing_notes ?: $maintenance->notes ?: 'Sin detalle adicional', 120) }}</div><small>{{ \App\Fleet\MaintenanceService::STATUSES[$maintenance->status] }} · {{ $maintenance->tenant->code }} · {{ $maintenance->closed_odometer_km ?? $maintenance->reported_odometer_km ?? '—' }} km</small>@if($alert)<span class="vehicle-pill {{ $alert['level'] === 'overdue' ? 'overdue' : ($alert['level'] === 'upcoming' ? 'warn' : '') }}">{{ $alert['label'] }}</span>@endif</td>
                <td class="vehicle-history-cost">{{ $maintenance->actual_cost !== null ? '$'.number_format($maintenance->actual_cost, 0, ',', '.') : 'Sin costo real' }}<small>{{ $maintenance->provider?->operational_name ?: ($maintenance->provider?->legal_name ?? ($maintenance->historicalImport?->source_data['Taller/Concesionaria'] ?? 'Taller sin registro')) }}</small>@if($maintenance->quotation_number)<small>Cotización: {{ $maintenance->quotation_number }}</small>@endif@if(! $maintenance->historicalImport && $maintenance->document_number)<small>Factura / respaldo: {{ $maintenance->document_number }}</small>@endif@if($maintenance->historicalImport)<small>{{ $maintenance->document_type }} {{ $maintenance->document_number ? '· '.$maintenance->document_number : '' }}</small><small>Facturada a {{ $maintenance->historicalImport->billed_company ?: 'Sin registro' }}</small>@endif@if($maintenance->actual_cost === null && $maintenance->estimated_cost !== null)<small>Presup.: ${{ number_format($maintenance->estimated_cost, 0, ',', '.') }}</small>@endif</td></tr>
            @endforeach
        </tbody></table></div>@endif
    @else<p class="muted">Sin permiso para consultar mantenciones.</p>@endif
</section>
</div>
</div>
</div>
@endsection
