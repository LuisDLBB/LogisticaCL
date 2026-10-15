@extends('fleet::layout')
@section('title', 'Kilometrajes y alertas')
@section('header-description', 'Lecturas semanales, alertas y calendario de mantenciones.')
@section('header-filters')
<div class="fleet-page-controls"><form method="get"><label>Semana del lunes<input type="date" name="semana" value="{{ $week->toDateString() }}" max="{{ today('America/Santiago')->toDateString() }}"></label><button>Ver semana</button></form></div>
@endsection
@section('header-navigation')
<nav class="km-links"><a href="#km-readings">Lecturas y alertas</a><a href="#km-maintenance">Toca mantención</a><a href="#km-workshop">En taller</a><a href="#km-next-calendar">Próxima semana</a><a href="#km-calendar">Calendario y presupuestos</a></nav>
@endsection
@push('styles')
<style>
.km-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.km-head h1{font-size:22px;margin:0}.km-head form{display:flex;align-items:center;gap:8px}.km-head label{font-size:12px}.km-links{display:flex;gap:15px;font-size:13px;margin:14px 0}.km-table-wrap{overflow:auto}.km-table{width:100%;border-collapse:collapse;font-size:12px}.km-table th,.km-table td{padding:8px 7px;border-bottom:1px solid #e1e8e9;text-align:left;vertical-align:top}.km-table th{background:#f1f6f7;font-size:11px;white-space:nowrap}.km-table input[type=number]{width:105px;padding:6px;font-size:12px}.km-table input[type=text]{width:150px;padding:6px;font-size:12px}.km-table small{display:block;font-size:10px;color:#63767d;margin-top:4px}.km-table tr.red{background:#fff0f2}.km-table tr.yellow{background:#fff9e7}.km-pill{display:inline-block;border-radius:6px;padding:5px 7px;font-size:11px;font-weight:700}.km-pill.green{background:#e7f7ef;color:#128052}.km-pill.yellow{background:#ffedb2;color:#8b6100}.km-pill.red{background:#f8dce1;color:#9d243e}.km-import{display:grid;grid-template-columns:1fr 1fr;gap:15px}.km-import textarea{width:100%;font:inherit;font-size:12px;border:1px solid #b7c9ca;border-radius:7px;padding:8px}.km-section h2{font-size:18px;margin:0 0 12px}.km-section{scroll-margin-top:20px}.km-help{font-size:12px;color:#63767d}.km-calendar{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}.km-calendar article{padding:12px;border:1px solid #dce7e9;border-radius:9px}.km-calendar article p{font-size:12px;margin:6px 0}.km-total{font-size:14px;font-weight:700}.km-draft{width:100%;font:inherit;font-size:13px;border:1px solid #b7c9ca;padding:10px;border-radius:8px}@media(max-width:650px){.km-import{grid-template-columns:1fr}.km-head form{flex-wrap:wrap}}
.km-trend-anchor{display:inline-flex;align-items:center;gap:5px}.km-trend-trigger{padding:2px 5px!important;border:1px solid #c2deda!important;border-radius:5px!important;background:#ecf8f5!important;color:#087e6c!important;font-size:14px!important;line-height:1.2!important}.km-trend-panel{position:fixed;z-index:1000;width:390px;max-width:calc(100vw - 20px);max-height:calc(100vh - 20px);overflow:auto;padding:16px;border:1px solid #c9dfdc;border-radius:14px;background:#fff;box-shadow:0 10px 40px #163b3c30;color:#18373c}.km-trend-panel[hidden]{display:none!important}.km-trend-close{position:absolute;right:9px;top:9px;padding:2px 8px!important;background:#edf5f5!important;color:#45656b!important;font-size:18px!important}.km-trend-panel h3{font-size:16px;margin:0 30px 4px 0}.km-trend-panel p{font-size:12px;line-height:1.5;margin:8px 0}.km-trend-panel .km-trend-sub{font-size:11px;color:#657b7d;margin:0}.km-trend-metrics{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin:12px 0 9px}.km-trend-metrics>div{background:#f2f7f7;border-radius:8px;padding:9px}.km-trend-metrics span{display:block;font-size:10px;color:#657b7d}.km-trend-metrics b{display:block;font-size:17px;margin-top:4px}.km-trend-delta{padding:8px 10px;border-radius:8px;background:#e9f8f2;color:#097550;font-size:12px;font-weight:700}.km-trend-delta.review{background:#fff2df;color:#986100}.km-trend-chart{width:100%;display:block;margin-top:10px}.km-trend-empty{padding:20px 8px;text-align:center;border:1px dashed #d1e1e1;border-radius:9px;color:#657b7d}.km-trend-legend{font-size:10px;color:#657b7d}.km-trend-legend span{display:inline-block;margin-right:8px}.km-trend-legend i{display:inline-block;width:7px;height:7px;border-radius:50%;background:#0c987e;margin-right:4px}.km-trend-legend .review i{background:#ce6d27}

.km-board{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:14px;align-items:start}.km-lane{--line:#cbded6;--tint:#f4faf6;padding:12px;background:var(--tint);border:1px solid var(--line);border-radius:13px;min-width:0;min-height:180px}.km-lane.maintenance{--line:#ead8a8;--tint:#fffaf0}.km-lane.workshop{--line:#edcdd3;--tint:#fff5f6}.km-lane h2{font-size:15px;display:flex;align-items:center;justify-content:space-between;margin:0 0 12px;padding-bottom:10px;border-bottom:1px solid var(--line)}.km-lane h2 b{font-size:12px;padding:3px 7px;background:#fff;border-radius:6px}.km-vehicle-list{display:grid;gap:9px}.km-vehicle-card{padding:12px;background:#fff;border:1px solid #dce7e9;border-left:3px solid #19885d;border-radius:10px;min-width:0}.km-vehicle-card.yellow{border-left-color:#b57b08}.km-vehicle-card.red{border-left-color:#cc354f}.km-card-title{display:flex;justify-content:space-between;align-items:center}.km-card-title strong{font-size:16px}.km-card-title>small{font-size:10px;color:#53717c}.km-card-model{font-size:11px;color:#63767d;margin:3px 0 10px}.km-card-data{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px}.km-card-data span{display:block;color:#63767d;font-size:10px}.km-card-data strong{display:block;font-size:12px;margin-top:3px}.km-card-reading{display:block;font-size:11px;margin:9px 0}.km-card-reading input{display:block;width:100%;padding:6px 8px;min-height:32px;margin-top:4px;font-size:13px}.km-card-hint{display:block;font-size:10px;color:#63767d;margin:5px 0}.km-card-status{display:flex;align-items:center;flex-wrap:wrap;gap:5px;margin:8px 0}.km-card-status small{font-size:11px}.km-plan{font-size:12px;padding:7px 10px;margin-top:4px}.km-lane-empty{border:1px dashed var(--line);padding:24px 10px;text-align:center;border-radius:8px;color:#63767d;font-size:12px}.km-board-actions{display:flex;align-items:center;gap:12px;margin:12px 0 20px}.km-board-actions span{font-size:12px;color:#63767d}@media(max-width:1000px){.km-board{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:650px){.km-board{grid-template-columns:1fr}.km-board-actions{flex-wrap:wrap}}
</style>
@endpush
@section('content')
@if($rows->contains('can_edit', true))
<details class="card"><summary>Importar desde Excel / CSV o pegar tabla</summary>
<form method="post" action="{{ route('fleet.kilometers.preview') }}" enctype="multipart/form-data">@csrf<input type="hidden" name="semana" value="{{ $week->toDateString() }}"><div class="km-import"><label>Archivo Excel o CSV<input type="file" name="archivo" accept=".xlsx,.csv,.txt"><small>Hasta 5 MB. Primera fila con nombres de columnas.</small></label><label>O pega celdas de Excel<textarea name="pegado" rows="4" placeholder="Patente&#9;KM Actual&#9;obs"></textarea></label></div><p class="km-help">Columnas a cargar: Patente, KM Actual y obs. Última mantención e intervalo se toman del sistema y no se reemplazan desde Excel. La carga prepara una vista previa para la semana seleccionada; revisa antes de guardar.</p><button>Revisar carga</button></form></details>
@endif
<form method="post" action="{{ route('fleet.kilometers.store') }}">@csrf<input type="hidden" name="semana" value="{{ $week->toDateString() }}">
<div class="km-board">
<section class="km-lane readings km-section" id="km-readings"><h2>Pendientes de kilometraje <b>{{ $captureRows->count() }}</b></h2>
@include('fleet::vehicles._kilometer_readings', ['displayRows' => $captureRows, 'editable' => true, 'planning' => false])</section>
<section class="km-lane maintenance km-section" id="km-maintenance"><h2>Toca mantención <b>{{ $maintenanceRows->count() }}</b></h2><p class="km-help">Calendario para la semana del {{ $planningWeek->format('d/m/Y') }}. Alerta por margen de 1.500 km o por recorrido previsto.</p>
@include('fleet::vehicles._kilometer_readings', ['displayRows' => $maintenanceRows, 'editable' => true, 'planning' => true])</section>
<section class="km-lane workshop km-section" id="km-workshop"><h2>En taller <b>{{ $workshopRows->count() }}</b></h2><p class="km-help">En mantención o reparación. No disponibles para retiros.</p>
@include('fleet::vehicles._kilometer_readings', ['displayRows' => $workshopRows, 'editable' => true, 'planning' => false])</section>
</div>
@if($captureRows->concat($maintenanceRows)->concat($workshopRows)->contains('can_edit', true))<div class="km-board-actions"><button>Guardar lecturas semanales</button><span>Las alertas se recalculan al guardar.</span></div>@endif
</form>
<details class="card"><summary>Lecturas ingresadas sin necesidad de mantención · {{ $completedRows->count() }}</summary><p class="km-help">Se conservan en el historial. Volverán a captura al seleccionar una nueva semana.</p>
@include('fleet::vehicles._kilometer_readings', ['displayRows' => $completedRows, 'editable' => false])</details>
<section class="card km-section" id="km-next-calendar"><h2>Calendario de la semana siguiente · {{ $planningWeek->format('d/m') }} al {{ $planningWeek->copy()->addDays(6)->format('d/m/Y') }}</h2><div class="km-calendar">
@forelse($nextCalendar as $maintenance)<article><strong>{{ $maintenance->vehicle->plate }}</strong><p>{{ $maintenance->scheduled_at->timezone('America/Santiago')->format('d/m H:i') }}</p><p>{{ $maintenance->maintenance_type }}</p><p><a href="{{ route('fleet.maintenance.show', $maintenance) }}">Ver mantención</a></p></article>@empty<p class="km-help">Aún no hay mantenciones programadas para la semana siguiente. Usa «Programar» en los vehículos de «Toca mantención» para definir fecha, taller y presupuesto.</p>@endforelse
</div></section>
<section class="card km-section" id="km-calendar"><h2>Calendario y presupuestos · {{ $week->format('d/m') }} al {{ $week->copy()->addDays(6)->format('d/m/Y') }}</h2><div class="km-calendar">
@forelse($calendar as $maintenance)<article><strong>{{ $maintenance->vehicle->plate }}</strong><p>{{ $maintenance->scheduled_at?->timezone('America/Santiago')->format('d/m H:i') ?? 'En curso, sin fecha prevista' }}</p><p>{{ $maintenance->maintenance_type }}</p><p>{{ $maintenance->provider?->operational_name ?: ($maintenance->provider?->legal_name ?: 'Proveedor por confirmar') }}</p><span class="km-pill {{ $maintenance->status === 'in_progress' ? 'red' : 'yellow' }}">{{ \App\Fleet\MaintenanceService::STATUSES[$maintenance->status] }}</span><p><a href="{{ route('fleet.maintenance.show', $maintenance) }}">Ver mantención</a></p></article>@empty<p class="km-help">No hay mantenciones programadas para esta semana.</p>@endforelse
</div>
@php($budget = $calendar->concat($nextCalendar)->concat($pending)->unique('id'))
<h3>Tabla de presupuesto estimado</h3><div class="km-table-wrap"><table class="km-table" id="km-budget"><thead><tr><th>Patente</th><th>Fecha prevista</th><th>Trabajo</th><th>Taller / concesionaria</th><th>Monto estimado</th><th>Estado</th></tr></thead><tbody>@forelse($budget as $maintenance)<tr><td>{{ $maintenance->vehicle->plate }}</td><td>{{ $maintenance->scheduled_at?->timezone('America/Santiago')->format('d/m/Y H:i') ?? 'Por programar' }}</td><td>{{ $maintenance->maintenance_type }}</td><td>{{ $maintenance->provider?->operational_name ?: ($maintenance->provider?->legal_name ?: 'Por confirmar') }}</td><td>{{ $maintenance->estimated_cost !== null ? '$'.number_format($maintenance->estimated_cost, 0, ',', '.') : 'Por cotizar' }}</td><td>{{ \App\Fleet\MaintenanceService::STATUSES[$maintenance->status] }}</td></tr>@empty<tr><td colspan="6">No hay presupuestos pendientes o programados.</td></tr>@endforelse</tbody></table></div><p class="km-total">Total estimado informado: ${{ number_format($budget->sum('estimated_cost'), 0, ',', '.') }} <small>· {{ $budget->whereNull('estimated_cost')->count() }} registros por cotizar</small></p>
<details><summary>Preparar texto para correo semanal</summary><p class="km-help">Revisa el texto y copia la tabla para adjuntarlos al correo.</p><textarea class="km-draft" id="km-mail-draft" rows="5">Estimados:
Comparto el calendario de mantenciones y reparaciones de flota desde el {{ $week->format('d/m/Y') }} hasta el {{ $planningWeek->copy()->addDays(6)->format('d/m/Y') }}.
Se registran {{ $calendar->count() }} trabajos programados o en curso esta semana, {{ $nextCalendar->count() }} para la semana siguiente y {{ $pending->count() }} pendientes de fecha. El presupuesto estimado informado es de ${{ number_format($budget->sum('estimated_cost'), 0, ',', '.') }}; {{ $budget->whereNull('estimated_cost')->count() }} registros están pendientes de cotización.
Favor revisar las fechas, disponibilidad y presupuestos indicados antes de confirmar la programación.
Saludos,
{{ auth()->user()->name }}
Operaciones · 4N Logística</textarea><button type="button" id="km-copy-mail">Copiar texto y tabla</button><span id="km-copy-status" role="status"></span></details></section>
@foreach($rows as $row)
<template id="km-trend-template-{{ $row['vehicle']->id }}">@include('fleet::vehicles._kilometer_trend', ['trend' => $trends->get($row['vehicle']->id), 'vehicle' => $row['vehicle']])</template>
@endforeach
<div id="km-trend-panel" class="km-trend-panel" role="dialog" aria-labelledby="km-trend-title" hidden><button type="button" class="km-trend-close" aria-label="Cerrar comparación">×</button><div id="km-trend-content"></div></div>
<script>
(() => {
    const panel = document.getElementById('km-trend-panel');
    document.body.appendChild(panel);
    let anchor = null, activeId = null, pinned = false, closeTimer = null, restoringFocus = false;
    const cancelClose = () => { clearTimeout(closeTimer); closeTimer = null; };
    function closeTrend(returnFocus = false) {
        cancelClose(); panel.hidden = true; pinned = false;
        document.querySelectorAll('[data-km-trend]').forEach(button => button.setAttribute('aria-expanded', 'false'));
        if (returnFocus) { restoringFocus = true; anchor?.focus(); restoringFocus = false; } anchor = null; activeId = null;
    }
    function positionTrend() {
        if (!anchor || panel.hidden) return;
        const box = anchor.getBoundingClientRect(), width = panel.offsetWidth, height = panel.offsetHeight;
        if (box.bottom < 0 || box.top > window.innerHeight || box.right < 0 || box.left > window.innerWidth) { closeTrend(); return; }
        const left = Math.max(10, Math.min(box.left, window.innerWidth - width - 10));
        let top = box.bottom + 8;
        if (top + height > window.innerHeight - 10) top = box.top - height - 8;
        top = Math.max(10, Math.min(top, window.innerHeight - height - 10));
        panel.style.left = `${left}px`; panel.style.top = `${top}px`;
    }
    function showTrend(trigger, id, pin = false) {
        cancelClose();
        if (pinned && !pin && activeId !== id) return;
        const template = document.getElementById(`km-trend-template-${id}`);
        if (!template) return;
        if (activeId !== id || panel.hidden) document.getElementById('km-trend-content').replaceChildren(template.content.cloneNode(true));
        anchor = trigger; activeId = id; pinned = pinned || pin; panel.hidden = false;
        document.querySelectorAll('[data-km-trend]').forEach(button => button.setAttribute('aria-expanded', String(button.dataset.kmTrend === id)));
        positionTrend();
        if (pin) panel.querySelector('.km-trend-close').focus();
    }
    function scheduleClose() { if (!pinned) { cancelClose(); closeTimer = setTimeout(() => { if (!panel.matches(':hover') && !panel.contains(document.activeElement)) closeTrend(); }, 240); } }
    document.querySelectorAll('.km-trend-anchor').forEach(wrap => {
        const link = wrap.querySelector('[data-km-hover]'), button = wrap.querySelector('[data-km-trend]');
        wrap.addEventListener('pointerenter', event => { if (event.pointerType === 'mouse') showTrend(link, link.dataset.kmHover); });
        wrap.addEventListener('pointerleave', scheduleClose);
        wrap.addEventListener('focusin', event => { if (!restoringFocus) showTrend(event.target, link.dataset.kmHover); });
        wrap.addEventListener('focusout', scheduleClose);
        link.addEventListener('click', () => closeTrend());
        button.addEventListener('click', () => { if (pinned && activeId === button.dataset.kmTrend) closeTrend(); else showTrend(button, button.dataset.kmTrend, true); });
    });
    panel.addEventListener('pointerenter', cancelClose); panel.addEventListener('pointerleave', scheduleClose);
    panel.addEventListener('focusin', cancelClose); panel.addEventListener('focusout', scheduleClose);
    panel.querySelector('.km-trend-close').addEventListener('click', () => closeTrend(true));
    panel.addEventListener('click', event => { if (event.target.closest('[data-ret-modal]')) closeTrend(); });
    document.addEventListener('pointerdown', event => { if (!panel.hidden && !panel.contains(event.target) && !event.target.closest('.km-trend-anchor')) closeTrend(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && !panel.hidden) { event.stopImmediatePropagation(); closeTrend(true); } }, true);
    window.addEventListener('resize', positionTrend); window.addEventListener('scroll', positionTrend, true);
    document.querySelectorAll('#km-readings tbody tr, #km-maintenance tbody tr').forEach(row => {
        const base = row.querySelector('[data-maintenance-baseline]'), interval = row.querySelector('[data-maintenance-interval]'), current = row.querySelector('[name$="[odometer_km]"]');
        if (!base || !interval || !current) return;
        const refresh = () => { const due = base.dataset.maintenanceBaseline !== '' && Number(interval.dataset.maintenanceInterval) > 0 ? Number(base.dataset.maintenanceBaseline) + Number(interval.dataset.maintenanceInterval) : null; row.querySelector('[data-next-km]').textContent = due === null ? 'Sin definir' : due.toLocaleString('es-CL'); row.querySelector('[data-remaining-km]').textContent = due === null || !current.value || Number(current.value) <= 0 || Number(current.value) < Number(base.dataset.maintenanceBaseline) ? 'Por verificar' : (due - Number(current.value)).toLocaleString('es-CL'); };
        current.addEventListener('input', refresh);
    });
    document.getElementById('km-copy-mail').addEventListener('click', async () => { const table = Array.from(document.querySelectorAll('#km-budget tr')).map(row => Array.from(row.cells).map(cell => cell.innerText).join(String.fromCharCode(9))).join(String.fromCharCode(10)); try { await navigator.clipboard.writeText(document.getElementById('km-mail-draft').value + String.fromCharCode(10,10) + table); document.getElementById('km-copy-status').textContent = 'Copiado.'; } catch { document.getElementById('km-copy-status').textContent = 'Selecciona y copia el texto y la tabla manualmente.'; } });
})();
</script>
@endsection
