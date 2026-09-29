@extends('provider-payments::layout')
@section('title', 'Resumen')
@push('styles')<style>.filter{display:flex;align-items:end;gap:12px;margin:18px 0;padding:14px 16px}.filter label{display:grid;gap:5px;font-size:13px;font-weight:750}.filter select{min-width:210px;padding:8px;border:1px solid var(--line);border-radius:7px;background:#fff}.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0}.metric{padding:16px}.metric strong{display:block;margin-top:7px;color:#071b1d;font-size:26px}.metric.featured{background:var(--navy);color:#d2eeee}.service-counts{display:grid;gap:7px;margin-top:9px}.service-count{display:flex;justify-content:space-between;gap:12px;color:#fff;font-size:16px;font-weight:800}.service-count b{font-variant-numeric:tabular-nums}.process-manager{margin:0 0 20px;padding:16px}.process-manager h2{margin:0 0 6px}.process-delete-list{display:grid;gap:9px;margin-top:14px}.process-delete-row{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:10px;border:1px solid var(--line);border-radius:8px}.process-delete-row label{display:grid;gap:4px;font-size:12px;font-weight:700}.process-delete-row input{padding:8px;border:1px solid var(--line);border-radius:7px}.process-delete-row button{background:#b42318}.summary-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.box{overflow:hidden}.box h2{margin:0;padding:14px 16px;border-bottom:1px solid var(--line);font-size:16px}.empty{padding:18px;color:var(--muted)}@media(max-width:1050px){.metrics{grid-template-columns:1fr 1fr}}@media(max-width:900px){.filter{align-items:stretch;flex-direction:column}.metrics,.summary-grid{grid-template-columns:1fr}.process-delete-row{align-items:stretch;flex-direction:column}}</style>@endpush
@push('styles')<style>.pending-processes{margin:0 0 16px;padding:14px 16px;border:1px solid #e5d79b;border-left:4px solid #a78328;border-radius:9px;background:#fff9df}.pending-processes h2{margin:0 0 5px;font-size:16px}.pending-processes p{margin:0 0 10px;color:#584b2d}.pending-processes-list{display:flex;flex-wrap:wrap;gap:6px 14px;margin:0 0 12px;padding:0;list-style:none;font-size:13px}.pending-processes-list strong{font-variant-numeric:tabular-nums}</style>@endpush
@push('styles')
<style>
.process-checklist{position:fixed;right:22px;bottom:22px;z-index:40;width:min(270px,calc(100vw - 28px));border:1px solid #dfd295;border-radius:3px;background:#fff8ca;box-shadow:0 12px 28px #091e2238;color:#263840;font-family:'Segoe Print','Segoe Script',cursive}
.process-checklist-handle{display:flex;align-items:center;gap:8px;padding:10px 10px 8px 14px;cursor:grab;touch-action:none;user-select:none}
.process-checklist-handle:active{cursor:grabbing}.process-checklist-handle:focus-visible{outline:2px solid var(--turquoise-dark);outline-offset:-2px}
.process-checklist-grip{color:#877d52;font-weight:800;letter-spacing:-3px}.process-checklist-handle h2{flex:1;margin:0;font-size:13px;line-height:1.25;white-space:nowrap}
.process-checklist-toggle{display:grid;place-items:center;width:26px;height:26px;padding:0;border:0;border-radius:4px;background:#eadf9f;color:#263840;font-size:18px;cursor:pointer}
.process-checklist-body{padding:0 12px 10px}.process-checklist-list{margin:0;padding:0;list-style:none;background:repeating-linear-gradient(to bottom,transparent 0,transparent 35px,#d9d29e 36px,transparent 37px)}
.process-checklist-list li{display:grid;grid-template-columns:32px 1fr;align-items:center;min-height:37px;font-size:15px;font-weight:600}
.process-checklist-mark{color:#9d9a82;font-size:22px;line-height:1}.process-checklist-list li.is-worked .process-checklist-mark{color:#176da0;font-weight:900}
.process-checklist-caption{display:block;margin-top:9px;color:#756f50;font-size:10px;line-height:1.3}
@media(max-width:600px){.process-checklist{right:12px;bottom:12px}}
</style>
@endpush
@section('content')
<p class="eyebrow">Gestión operacional</p><h1>Resumen de pago a proveedores</h1><p class="intro">Estado general de los movimientos Courier y preparación de pagos.</p>
@if(session('status'))<div class="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="warning">{{ $errors->first() }}</div>@endif
<form class="card filter" method="get">
    <label>Año y mes del proceso<select name="period" onchange="this.form.submit()">@forelse($periods as $period)<option value="{{ $period }}" @selected($period === $selectedPeriod)>{{ substr($period,0,4) }}-{{ substr($period,4,2) }}</option>@empty<option value="">Sin procesos cargados</option>@endforelse</select></label>
    <noscript><button type="submit">Filtrar</button></noscript>
    @if($selectedPeriod !== '')
        <span class="note" role="status"><strong>Período {{ $selectedPeriod }}: {{ $monthClosed ? 'Cerrado definitivamente' : 'En ejecución' }}</strong>
            @if($closure) · {{ number_format($closure->registros,0,',','.') }} pagos en Maestro_Pagos @endif
        </span>
    @endif
</form>
@if(!$monthClosed && $pendingProcessCounts->isNotEmpty())
<section class="pending-processes" role="status" aria-label="Procesos cargados pendientes de preparar pago">
    <h2>Período {{ $selectedPeriod }}: movimientos cargados, pagos pendientes de preparar</h2>
    <p>{{ number_format($pendingProcessCounts->sum('total'), 0, ',', '.') }} movimientos ya están en el sistema y aún no tienen registro de pago. Estos movimientos todavía no suman montos en los gráficos ni se marcan como trabajados en el cierre.</p>
    <ul class="pending-processes-list">@foreach($pendingProcessCounts as $process)<li>{{ $selectedPeriod }}-{{ $process->service_name }}: <strong>{{ number_format($process->total, 0, ',', '.') }}</strong> pendientes</li>@endforeach</ul>
    <a class="button" href="{{ route('provider-payments.courier-movements.compile.work', ['period' => $selectedPeriod]) }}">Trabajar registros del período</a>
</section>
@endif
@if($selectedPeriod !== '')
<section id="process-checklist" class="process-checklist" aria-label="Lista de procesos del período {{ $selectedPeriod }}">
    <div class="process-checklist-handle" tabindex="0" aria-label="Arrastrar lista de procesos">
        <span class="process-checklist-grip" aria-hidden="true">⋮⋮</span>
        <h2>Cierre Mes - {{ $selectedPeriod }}</h2>
        <button class="process-checklist-toggle" type="button" aria-expanded="true" aria-controls="process-checklist-body" aria-label="Contraer lista">−</button>
    </div>
    <div id="process-checklist-body" class="process-checklist-body">
        <ul class="process-checklist-list">
            @foreach($processChecklist as $item)
                <li class="{{ $item['worked'] ? 'is-worked' : '' }}" aria-label="{{ $item['label'] }}: {{ $item['worked'] ? 'trabajado' : 'pendiente' }}">
                    <span class="process-checklist-mark" aria-hidden="true">{{ $item['worked'] ? '✓' : '○' }}</span>
                    <span>{{ $item['label'] }}</span>
                </li>
            @endforeach
        </ul>
        <small class="process-checklist-caption">Los procesos se marcan al registrar pagos; el cierre definitivo se marca al guardarlos en Maestro_Pagos.</small>
    </div>
</section>
@include('provider-payments::payment-overview', ['period' => $selectedPeriod, 'paymentDashboard' => $paymentDashboard, 'processAmounts' => $processAmounts])
@endif
<section class="metrics"><a class="card metric" href="{{ route('provider-payments.movements.index', ['period' => $selectedPeriod]) }}" style="text-decoration:none"><span class="note">Registros cargados · Ver planilla</span><strong>{{ number_format($recordCount,0,',','.') }}</strong></a><article class="card metric"><span class="note">Clientes con movimientos</span><strong>{{ count($merchantCounts) }}</strong></article><article class="card metric"><span class="note">Estados registrados</span><strong>{{ count($statusCounts) }}</strong></article><article class="card metric featured"><span>Registros por servicio en pagos</span><div class="service-counts">@forelse($serviceCounts as $service)<a class="service-count" href="{{ route('provider-payments.courier-movements.compile.work', ['period' => $selectedPeriod, 'process' => $service->nombre_proceso]) }}" style="text-decoration:none"><span>{{ $service->service_name ?: 'Sin servicio' }}</span><b>{{ number_format($service->total,0,',','.') }}</b></a>@empty<div class="service-count"><span>Sin registros de pago</span></div>@endforelse</div></article></section>
@if($monthClosed)
<section class="card process-manager"><h2>Período {{ $selectedPeriod }} cerrado definitivamente</h2><p class="note">Los pagos de este mes están conservados en Maestro_Pagos y sus procesos no se pueden modificar ni eliminar.</p></section>
@else
<section class="card process-manager"><h2>Eliminar procesos cargados</h2><p class="note">En Especiales se eliminan los movimientos nuevos y se restauran los pagos previos de los movimientos coincidentes. Para otros procesos se eliminan sus movimientos de origen y pagos vinculados; si tienen Especiales asociados, primero debes revertir Especiales. Requiere clave maestra.</p><div class="process-delete-list">@forelse($sourceProcessCounts as $sourceProcess)@php($fullProcessName = $selectedPeriod.'-'.$sourceProcess->service_name)<form class="process-delete-row" method="post" action="{{ route('provider-payments.movements.processes.destroy') }}" onsubmit="if (!confirm('¿Procesar {{ $fullProcessName }}? Se eliminarán sus movimientos nuevos y los pagos vinculados; si es Especiales, los pagos anteriores se restaurarán. Esta acción no se puede deshacer.')) return false; const button = this.querySelector('button[type=submit]'); button.disabled = true; button.textContent = 'Eliminando…'; return true;">@csrf @method('DELETE')<input type="hidden" name="process_name" value="{{ $fullProcessName }}"><span><strong>{{ $fullProcessName }}</strong> · Pagos: {{ number_format($paymentCountsByProcess[$sourceProcess->service_name] ?? 0,0,',','.') }} · Origen: {{ number_format($sourceProcess->total,0,',','.') }}</span><label>Clave maestra <input type="password" name="password" required autocomplete="off"></label><button type="submit">Eliminar proceso</button></form>@empty<p class="empty">No hay procesos para eliminar en este período.</p>@endforelse</div></section>
@endif
<section class="summary-grid"><article class="box"><h2>Registros por cliente</h2>@forelse($merchantCounts as $row)@if($loop->first)<table><thead><tr><th>Comerciante</th><th>Registros</th></tr></thead><tbody>@endif<tr><td>{{ $row->merchant_name ?: 'Sin comerciante' }}</td><td>{{ number_format($row->total,0,',','.') }}</td></tr>@if($loop->last)</tbody></table>@endif @empty<p class="empty">Aún no hay movimientos Courier cargados.</p>@endforelse</article>
<article class="box"><h2>Registros por estado</h2>@forelse($statusCounts as $row)@if($loop->first)<table><thead><tr><th>Estado</th><th>Registros</th></tr></thead><tbody>@endif<tr><td>{{ $row->status ?: 'Sin estado' }}</td><td>{{ number_format($row->total,0,',','.') }}</td></tr>@if($loop->last)</tbody></table>@endif @empty<p class="empty">Aún no hay movimientos Courier cargados.</p>@endforelse</article></section>
@endsection
@push('scripts')
<script>
(() => {
    const checklist = document.getElementById('process-checklist');
    if (!checklist) return;

    const handle = checklist.querySelector('.process-checklist-handle');
    const toggle = checklist.querySelector('.process-checklist-toggle');
    const body = checklist.querySelector('.process-checklist-body');
    const storageKey = 'provider-payments.checklist-position';
    const clamp = (value, minimum, maximum) => Math.min(Math.max(value, minimum), maximum);
    const place = (left, top) => {
        checklist.style.left = `${clamp(left, 8, Math.max(8, window.innerWidth - checklist.offsetWidth - 8))}px`;
        checklist.style.top = `${clamp(top, 8, Math.max(8, window.innerHeight - checklist.offsetHeight - 8))}px`;
        checklist.style.right = 'auto';
        checklist.style.bottom = 'auto';
    };

    try {
        const saved = JSON.parse(localStorage.getItem(storageKey));
        if (Number.isFinite(saved?.left) && Number.isFinite(saved?.top)) place(saved.left, saved.top);
    } catch (_) {}

    let drag = null;
    handle.addEventListener('pointerdown', (event) => {
        if (event.target.closest('button') || (event.pointerType === 'mouse' && event.button !== 0)) return;
        const rect = checklist.getBoundingClientRect();
        drag = {x: event.clientX, y: event.clientY, left: rect.left, top: rect.top};
        handle.setPointerCapture(event.pointerId);
        event.preventDefault();
    });
    handle.addEventListener('pointermove', (event) => {
        if (!drag) return;
        place(drag.left + event.clientX - drag.x, drag.top + event.clientY - drag.y);
    });
    const savePosition = () => {
        const rect = checklist.getBoundingClientRect();
        try { localStorage.setItem(storageKey, JSON.stringify({left: rect.left, top: rect.top})); } catch (_) {}
    };
    const finishDrag = () => {
        if (!drag) return;
        drag = null;
        savePosition();
    };
    handle.addEventListener('pointerup', finishDrag);
    handle.addEventListener('pointercancel', finishDrag);
    handle.addEventListener('keydown', (event) => {
        if (event.target.closest('button')) return;
        const delta = {ArrowLeft: [-16, 0], ArrowRight: [16, 0], ArrowUp: [0, -16], ArrowDown: [0, 16]}[event.key];
        if (!delta) return;
        const rect = checklist.getBoundingClientRect();
        place(rect.left + delta[0], rect.top + delta[1]);
        savePosition();
        event.preventDefault();
    });
    toggle.addEventListener('click', () => {
        body.hidden = !body.hidden;
        toggle.setAttribute('aria-expanded', String(!body.hidden));
        toggle.setAttribute('aria-label', body.hidden ? 'Expandir lista' : 'Contraer lista');
        toggle.textContent = body.hidden ? '+' : '−';
        if (checklist.style.left) place(parseFloat(checklist.style.left), parseFloat(checklist.style.top));
    });
    window.addEventListener('resize', () => {
        if (checklist.style.left) place(parseFloat(checklist.style.left), parseFloat(checklist.style.top));
    });
})();
</script>
@endpush
