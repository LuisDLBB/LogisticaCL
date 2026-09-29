@extends('provider-payments::layout')
@section('title', 'Revisión de inconsistencias')
@push('styles')
<style>
    .review-group{padding:0;margin:14px 0;overflow:hidden}.review-group summary{padding:20px 22px;cursor:pointer;font-size:17px;font-weight:800;list-style:none}.review-group summary::-webkit-details-marker{display:none}.review-group summary::before{display:inline-block;margin-right:10px;content:'▶';color:var(--turquoise-dark);font-size:12px;transition:.2s}.review-group[open] summary::before{transform:rotate(90deg)}.review-group summary span{margin-left:10px;color:var(--muted);font-size:13px;font-weight:500}.review-group .table-wrap{border-top:1px solid var(--line)}.empty{padding:18px 22px;color:var(--turquoise-dark)}.tenant-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:20px 0}.tenant-row label{font-weight:700}.tenant-row select{padding:10px;border:1px solid var(--line);border-radius:7px;background:#fff}.comment{width:100%;min-width:220px;min-height:58px;padding:8px;border:1px solid var(--line);border-radius:7px}.row-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.coverage-footer{display:flex;gap:12px;align-items:center;padding:16px 20px;background:#f7fbfb}.success{padding:14px;border-left:4px solid var(--turquoise-dark);background:var(--turquoise-soft)}.weight-review-filters{display:flex;align-items:end;gap:12px;flex-wrap:wrap;padding:12px 16px}.weight-review-filters label{display:grid;gap:5px;font-size:12px;font-weight:750}.weight-review-filters input{width:125px;padding:8px;border:1px solid var(--line);border-radius:7px}.weight-review-table{max-height:60vh;overflow:auto}.weight-review-table th{position:sticky;top:0;z-index:2}.weight-review-table input[type=number]{width:105px;padding:7px;border:1px solid var(--line);border-radius:6px}.weight-review-table tr[hidden]{display:none}
</style>
@endpush
@section('content')
@php($uploadRoute = match($snapshot['process_type'] ?? 'variables') {'lanas' => 'provider-payments.courier-movements.lanas', 'retornos' => 'provider-payments.courier-movements.retornos', default => 'provider-payments.courier-movements.upload'})
<a class="back" href="{{ route($uploadRoute) }}">← Cargar otro archivo</a>
<p class="eyebrow">Pago a proveedores</p><h1>Revisión de inconsistencias</h1><p class="intro">Comprueba los datos pendientes antes de incorporar los movimientos.</p>
@if(session('status'))<p class="success">{{ session('status') }}</p>@endif
@if($errors->any())<p class="warning">{{ $errors->first() }}</p>@endif
@if (! $snapshot)
<div class="card"><h2>Necesitamos validar el archivo nuevamente</h2><p>La validación anterior no conservaba los datos necesarios para esta revisión. Selecciona el archivo una vez más para obtener el detalle agrupado.</p></div>
@else
<div class="card">
    <strong>{{ $snapshot['file'] }}</strong><p>{{ number_format($snapshot['records'], 0, ',', '.') }} registros analizados. Esta revisión todavía no guarda movimientos.</p>
    @if(($snapshot['discarded_unlocated_route_pickups'] ?? 0) > 0)<p class="note">{{ number_format($snapshot['discarded_unlocated_route_pickups'], 0, ',', '.') }} registros de «Retiro en ruta» sin dirección ni comuna destino se retiraron de esta revisión y no se cargarán.</p>@endif
    <p class="note">Cada desplegable agrupa valores pendientes e indica qué corregir. Un registro puede aparecer en varios grupos; sus totales no deben sumarse.</p>
    <form id="review-form" class="tenant-row" method="get"><strong>Empresa de prueba:</strong><span>{{ $tenant?->name ?? '4 Nortes' }}</span><button type="submit">Volver a revisar maestros</button></form>
    <div id="review-progress" hidden role="status"><p>Comparando datos con los maestros…</p><progress aria-label="Revisando parámetros"></progress></div>
    @if (! $tenant)<p class="warning">Falta registrar 4 Nortes como empresa de prueba. Los cruces no pueden comprobarse hasta completar ese registro.</p>@endif
    @if($snapshot['missing_columns'])<p class="warning">Columnas no identificadas: {{ implode(', ', $snapshot['missing_columns']) }}. Revisa los encabezados del archivo.</p>@endif
    <p class="note">Las comunas se comparan sin distinguir mayúsculas, tildes ni espacios, pero el sistema conserva el texto original. Los pesos se completan mediante el maestro de transformación de 4N.</p>
    <p class="note"><strong>Cruce de clientes:</strong> Comerciante del archivo → Comerciante (Pila) del maestro de clientes → RUT y razón social.</p>
    <a class="button" href="{{ route('provider-payments.courier-movements.errors.download') }}">Descargar base de errores CSV</a>
    @if($readyToImport && ! empty($snapshot['stored_path']))
    <form id="import-form" method="post" action="{{ route('provider-payments.courier-movements.store') }}" style="margin-top:18px">@csrf
        <div class="tenant-row">
            <label>Año del proceso<select id="process_year" name="process_year">@for($year = now()->year + 1; $year >= 2020; $year--)<option value="{{ $year }}" @selected($year === ($snapshot['suggested_year'] ?? now()->year))>{{ $year }}</option>@endfor</select></label>
            <label>Mes del proceso<select id="process_month" name="process_month">@foreach([1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'] as $month => $monthName)<option value="{{ $month }}" @selected($month === ($snapshot['suggested_month'] ?? now()->month))>{{ $monthName }}</option>@endforeach</select></label>
            <label>Nombre del proceso<input id="process_name" name="process_name" value="{{ sprintf('%04d%02d-%s', $snapshot['suggested_year'] ?? now()->year, $snapshot['suggested_month'] ?? now()->month, $snapshot['process_suffix'] ?? 'Variable') }}" maxlength="100" @readonly(in_array(($snapshot['process_type'] ?? 'variables'), ['lanas', 'retornos', 'consolidado'], true))></label>
        </div>
        @if(($snapshot['process_type'] ?? '') === 'consolidado')<p class="note">Un archivo separará cuatro procesos del período: Variable, Lanas, Retornos y Peumo. Se cargarán solo las filas correspondientes a cada uno.</p>@endif
        <label><input type="checkbox" name="replace_duplicates" value="1"> Reemplazar también duplicados que aún no están en Pagos Movimientos Courier</label>
        <p class="note">Los seguimientos ya pagados en Maestro Pagos se bloquean. Los que están en Pagos Movimientos Courier se actualizan automáticamente si su período sigue abierto; los demás duplicados se reemplazan solo al marcar esta opción.</p>
        <button type="submit">{{ ($snapshot['process_type'] ?? '') === 'consolidado' ? 'Cargar Variable, Lanas, Retornos y Peumo' : 'Cargar datos finales' }}</button>
    </form>
    <div id="import-progress" hidden role="status"><p>Cargando movimientos. No cierres esta pantalla…</p><progress></progress></div>
    @elseif($readyToImport)
    <p class="warning">Los parámetros están completos, pero esta validación no conserva el archivo original. Selecciónalo nuevamente para habilitar la carga final.</p>
    @endif
</div>
@foreach($groups as $group)
<details class="review-group"><summary>{{ $group['title'] }} <span>{{ count($group['items']) }} valores pendientes · {{ number_format($group['affected'], 0, ',', '.') }} registros afectados</span></summary>
@if(! $group['items'])<p class="empty">Sin inconsistencias en los cruces comprobados de este grupo.</p>@else
@if($group['key'] === 'weights')
<form method="post" action="{{ route('provider-payments.courier-movements.transform-weights') }}">@csrf
<div class="weight-review-filters"><label>Peso entero mayor que<input id="weight-greater" type="number" min="0" step="1" placeholder="Ej.: 10"></label><label>Peso entero menor que<input id="weight-less" type="number" min="0" step="1" placeholder="Ej.: 50"></label><span id="weight-visible-count" class="note"></span></div>
<p class="note" style="padding:0 16px">Se elimina la parte decimal sin redondear: 36.00 kg → 36; 3.02 kg → 3. Puedes editar el peso transformado antes de guardarlo. Los filtros solo cambian lo visible.</p>
<div class="table-wrap weight-review-table"><table><thead><tr><th>Peso del archivo</th><th>Peso entero</th><th>Registros</th><th>Peso transformado editable</th></tr></thead><tbody>
@foreach($group['items'] as $item) @php($integerWeight = \App\Models\WeightTransformation::integerPart($item['values'][0]))
<tr data-weight-review-row data-integer-weight="{{ $integerWeight ?? '' }}"><td>{{ $item['values'][0] }}</td><td>{{ $integerWeight === null ? 'Revisar formato' : $integerWeight }}</td><td>{{ number_format($item['count'], 0, ',', '.') }}</td><td>@if($integerWeight !== null)<input type="hidden" name="weights[{{ $loop->index }}][source_weight]" value="{{ $item['values'][0] }}"><input type="number" name="weights[{{ $loop->index }}][transformed_weight]" min="1" step="1" value="{{ max(1, $integerWeight) }}" required>@else<span class="warning">Corrige el valor de origen antes de cargarlo.</span>@endif</td></tr>
@endforeach
</tbody></table></div><div class="coverage-footer"><button type="submit">Guardar y transformar pesos</button><span class="note">Se guardan todas las filas numéricas, incluso las ocultas por el filtro.</span></div></form>
@else
@if($group['key'] === 'coverages')<form method="post" action="{{ route('provider-payments.courier-movements.exclude-coverages') }}">@csrf
@elseif($group['key'] === 'services')<form method="post" action="{{ route('provider-payments.courier-movements.exclude-services') }}">@csrf
@endif
<div class="table-wrap"><table><thead><tr><th>Dato del archivo</th>@if($group['key'] === 'coverages')<th>Dirección</th>@endif<th>Registros</th><th>Qué ingresar o corregir</th>@if(in_array($group['key'], ['coverages', 'services'], true))<th>Decisión y respaldo</th>@endif</tr></thead><tbody>
@foreach($group['items'] as $item) @php($sourceKey = implode(' → ', $item['values']))
<tr><td>{{ ($item['values'][0] ?? '') === '' ? '(Vacío)' : $item['values'][0] }}</td>@if($group['key'] === 'coverages')<td>{{ ($item['values'][1] ?? '') === '' ? '(Sin dirección)' : $item['values'][1] }}</td>@endif<td>{{ number_format($item['count'], 0, ',', '.') }}</td><td>{{ $item['action'] }}@if($group['key'] === 'clients')<div><a href="{{ route('provider-payments.maintainers.clientes', ['merchant' => $sourceKey]) }}">Crear cliente usando uno existente</a></div>@elseif($group['key'] === 'services')<div><a href="{{ route('provider-payments.maintainers.llave-centro-costos', ['merchant' => $item['values'][0], 'service' => $item['values'][1]]) }}">Crear combinación usando Llave Centro Costo</a></div>@elseif($group['key'] === 'coverages')<div><a href="{{ route('provider-payments.maintainers.coberturas', ['commune' => $item['values'][0] ?? '']) }}">Crear cobertura usando una existente</a></div>@endif</td>
@if($group['key'] === 'coverages')<td><input type="hidden" name="coverage_errors[{{ $loop->index }}][source_key]" value="{{ $sourceKey }}"><label>Comuna correcta<input list="coverage-communes" name="coverage_errors[{{ $loop->index }}][corrected_commune]" value="{{ $coverageCorrections[$sourceKey] ?? '' }}" placeholder="Buscar comuna existente"></label><div class="row-actions"><label><input type="checkbox" name="coverage_errors[{{ $loop->index }}][exclude]" value="1" @checked(in_array($sourceKey, $excludedCoverages, true))> No cargar</label></div><textarea class="comment" name="coverage_errors[{{ $loop->index }}][comment]" placeholder="Comentario de respaldo">{{ $coverageComments[$sourceKey] ?? '' }}</textarea></td>
@elseif($group['key'] === 'services')<td><input type="hidden" name="service_errors[{{ $loop->index }}][source_key]" value="{{ $sourceKey }}"><div class="row-actions"><label><input type="checkbox" name="service_errors[{{ $loop->index }}][exclude]" value="1" @checked(in_array($sourceKey, $excludedServices, true))> No cargar</label></div><textarea class="comment" name="service_errors[{{ $loop->index }}][comment]" placeholder="Comentario de respaldo">{{ $serviceComments[$sourceKey] ?? '' }}</textarea></td>@endif</tr>
@endforeach</tbody></table></div>
@if($group['key'] === 'coverages')<datalist id="coverage-communes">@foreach($coverageOptions as $commune)<option value="{{ $commune }}"></option>@endforeach</datalist><div class="coverage-footer"><button type="submit">Guardar correcciones y decisiones</button><span class="note">«No cargar» se guarda al marcarlo. Los comentarios se incluirán en la base de errores descargable.</span></div></form>@endif
@if($group['key'] === 'services')<div class="coverage-footer"><button type="submit">Guardar servicios que no se cargarán</button><span class="note">«No cargar» se guarda al marcarlo. Los movimientos de esas combinaciones quedarán fuera de la carga.</span></div></form>@endif
@endif
@endif</details>
@endforeach
@endif
@endsection
@push('scripts')<script>document.getElementById('review-form')?.addEventListener('submit',function(){document.getElementById('review-progress').hidden=false;this.querySelector('button').disabled=true;});const year=document.getElementById('process_year'),month=document.getElementById('process_month'),processName=document.getElementById('process_name'),processSuffix=@json($snapshot['process_suffix'] ?? 'Variable'),combined=@json(($snapshot['process_type'] ?? '') === 'consolidado');function defaultProcessName(){if(year&&month&&processName){processName.value=`${year.value}${String(month.value).padStart(2,'0')}-${processSuffix}`;}}year?.addEventListener('change',defaultProcessName);month?.addEventListener('change',defaultProcessName);document.getElementById('import-form')?.addEventListener('submit',function(event){const message=combined?`¿Confirmas la carga de Variable, Lanas, Retornos y Peumo del período ${processName.value.slice(0,6)}?`:`¿Confirmas la carga del proceso ${processName.value} a movimientos_courier?`;if(!confirm(message)){event.preventDefault();return;}document.getElementById('import-progress').hidden=false;this.querySelector('button').disabled=true;});</script>@endpush
@push('scripts')<script>(() => {const rows=[...document.querySelectorAll('[data-weight-review-row]')],greater=document.getElementById('weight-greater'),less=document.getElementById('weight-less'),counter=document.getElementById('weight-visible-count');if(!rows.length)return;function filter(){let visible=0;rows.forEach(row=>{const weight=Number(row.dataset.integerWeight),known=row.dataset.integerWeight!=='';row.hidden=known&&((greater.value!==''&&weight<=Number(greater.value))||(less.value!==''&&weight>=Number(less.value)));if(!row.hidden)visible++;});counter.textContent=`${visible} de ${rows.length} pesos visibles`;};greater.addEventListener('input',filter);less.addEventListener('input',filter);filter();})();</script>@endpush
@if(! empty($snapshot['batch_id']))
@push('scripts')
<script>
(() => {
    const draftKey = 'courier-review-draft:' + @json($snapshot['batch_id'] ?? '');
    const fields = () => [...document.querySelectorAll('input[name]:not([type=hidden]), select[name], textarea[name]')];
    const sections = () => [...document.querySelectorAll('.review-group')];
    const sourceKey = field => field.closest('tr')?.querySelector('input[name$="[source_key]"], input[name$="[source_weight]"]')?.value ?? null;
    const draftFieldKey = field => sourceKey(field) === null ? field.name : `${field.name.replace(/\[\d+\]/, '[]')}|${sourceKey(field)}`;
    function saveDraft() {
        try {
            const values = {};
            fields().forEach(field => {
                values[draftFieldKey(field)] = { value: field.type === 'checkbox' ? field.checked : field.value, sourceKey: sourceKey(field) };
            });
            sessionStorage.setItem(draftKey, JSON.stringify({ values, open: sections().map(section => section.open) }));
        } catch (error) { /* El formulario sigue funcionando sin almacenamiento local. */ }
    }
    try {
        const draft = JSON.parse(sessionStorage.getItem(draftKey) || 'null');
        if (draft?.values) {
            fields().forEach(field => {
                const saved = draft.values[draftFieldKey(field)] ?? draft.values[field.name];
                if (saved && saved.sourceKey === sourceKey(field)) {
                    if (field.type === 'checkbox') field.checked = saved.value;
                    else field.value = saved.value;
                }
            });
            sections().forEach((section, index) => { section.open = draft.open?.[index] ?? section.open; });
        }
    } catch (error) { /* Se muestran los valores guardados en el servidor. */ }
    document.addEventListener('input', saveDraft);
    document.addEventListener('change', event => {
        saveDraft();
        if (event.target.matches('input[name$="[exclude]"]') && event.target.checked) {
            const form = event.target.closest('form');
            form?.querySelectorAll('input[name$="[corrected_commune]"]').forEach(field => { field.disabled = true; });
            const button = form?.querySelector('button[type="submit"]');
            if (button) button.textContent = 'Guardando decisión…';
            form?.requestSubmit();
        }
    });
    window.addEventListener('pagehide', saveDraft);
})();
</script>
@endpush
@endif
