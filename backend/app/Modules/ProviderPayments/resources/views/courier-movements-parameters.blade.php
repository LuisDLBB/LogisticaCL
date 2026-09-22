@extends('provider-payments::layout')
@section('title', 'Revisión de inconsistencias')
@push('styles')
<style>
    .review-group{padding:0;margin:14px 0;overflow:hidden}.review-group summary{padding:20px 22px;cursor:pointer;font-size:17px;font-weight:800;list-style:none}.review-group summary::-webkit-details-marker{display:none}.review-group summary::before{display:inline-block;margin-right:10px;content:'▶';color:var(--turquoise-dark);font-size:12px;transition:.2s}.review-group[open] summary::before{transform:rotate(90deg)}.review-group summary span{margin-left:10px;color:var(--muted);font-size:13px;font-weight:500}.review-group .table-wrap{border-top:1px solid var(--line)}.empty{padding:18px 22px;color:var(--turquoise-dark)}.tenant-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:20px 0}.tenant-row label{font-weight:700}.tenant-row select{padding:10px;border:1px solid var(--line);border-radius:7px;background:#fff}.comment{width:100%;min-width:220px;min-height:58px;padding:8px;border:1px solid var(--line);border-radius:7px}.row-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.coverage-footer{display:flex;gap:12px;align-items:center;padding:16px 20px;background:#f7fbfb}.success{padding:14px;border-left:4px solid var(--turquoise-dark);background:var(--turquoise-soft)}
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
            <label>Nombre del proceso<input id="process_name" name="process_name" value="{{ sprintf('%04d%02d-%s', $snapshot['suggested_year'] ?? now()->year, $snapshot['suggested_month'] ?? now()->month, $snapshot['process_suffix'] ?? 'Variable') }}" maxlength="100" @readonly(in_array(($snapshot['process_type'] ?? 'variables'), ['lanas', 'retornos'], true))></label>
        </div>
        <label><input type="checkbox" name="replace_duplicates" value="1"> Reemplazar seguimientos que ya existan</label>
        <p class="note">Si no marcas la opción, los seguimientos existentes se conservarán y serán omitidos.</p>
        <button type="submit">Cargar datos finales</button>
    </form>
    <div id="import-progress" hidden role="status"><p>Cargando movimientos. No cierres esta pantalla…</p><progress></progress></div>
    @elseif($readyToImport)
    <p class="warning">Los parámetros están completos, pero esta validación no conserva el archivo original. Selecciónalo nuevamente para habilitar la carga final.</p>
    @endif
</div>
@foreach($groups as $group)
<details class="review-group"><summary>{{ $group['title'] }} <span>{{ count($group['items']) }} valores pendientes · {{ number_format($group['affected'], 0, ',', '.') }} registros afectados</span></summary>
@if(! $group['items'])<p class="empty">Sin inconsistencias en los cruces comprobados de este grupo.</p>@else
@if($group['key'] === 'coverages')<form method="post" action="{{ route('provider-payments.courier-movements.exclude-coverages') }}">@csrf @endif
<div class="table-wrap"><table><thead><tr><th>Dato del archivo</th>@if($group['key'] === 'coverages')<th>Dirección</th>@endif<th>Registros</th><th>Qué ingresar o corregir</th>@if($group['key'] === 'coverages')<th>Decisión y respaldo</th>@endif</tr></thead><tbody>
@foreach($group['items'] as $item) @php($sourceKey = implode(' → ', $item['values']))
<tr><td>{{ ($item['values'][0] ?? '') === '' ? '(Vacío)' : $item['values'][0] }}</td>@if($group['key'] === 'coverages')<td>{{ ($item['values'][1] ?? '') === '' ? '(Sin dirección)' : $item['values'][1] }}</td>@endif<td>{{ number_format($item['count'], 0, ',', '.') }}</td><td>{{ $item['action'] }}@if($group['key'] === 'clients')<div><a href="{{ route('provider-payments.maintainers.clientes', ['merchant' => $sourceKey]) }}">Crear cliente usando uno existente</a></div>@elseif($group['key'] === 'services')<div><a href="{{ route('provider-payments.maintainers.llave-centro-costos', ['merchant' => $item['values'][0], 'service' => $item['values'][1]]) }}">Crear combinación usando Llave Centro Costo</a></div>@elseif($group['key'] === 'coverages')<div><a href="{{ route('provider-payments.maintainers.coberturas', ['commune' => $item['values'][0] ?? '']) }}">Crear cobertura usando una existente</a></div>@endif</td>
@if($group['key'] === 'coverages')<td><input type="hidden" name="coverage_errors[{{ $loop->index }}][source_key]" value="{{ $sourceKey }}"><label>Comuna correcta<input list="coverage-communes" name="coverage_errors[{{ $loop->index }}][corrected_commune]" value="{{ $coverageCorrections[$sourceKey] ?? '' }}" placeholder="Buscar comuna existente"></label><div class="row-actions"><label><input type="checkbox" name="coverage_errors[{{ $loop->index }}][exclude]" value="1" @checked(in_array($sourceKey, $excludedCoverages, true))> No cargar</label></div><textarea class="comment" name="coverage_errors[{{ $loop->index }}][comment]" placeholder="Comentario de respaldo">{{ $coverageComments[$sourceKey] ?? '' }}</textarea></td>@endif</tr>
@endforeach</tbody></table></div>
@if($group['key'] === 'coverages')<datalist id="coverage-communes">@foreach($coverageOptions as $commune)<option value="{{ $commune }}"></option>@endforeach</datalist><div class="coverage-footer"><button type="submit">Guardar correcciones y decisiones</button><span class="note">Los comentarios se incluirán en la base de errores descargable.</span></div></form>@endif
@endif</details>
@endforeach
@endif
@endsection
@push('scripts')<script>document.getElementById('review-form')?.addEventListener('submit',function(){document.getElementById('review-progress').hidden=false;this.querySelector('button').disabled=true;});const year=document.getElementById('process_year'),month=document.getElementById('process_month'),processName=document.getElementById('process_name'),processSuffix=@json($snapshot['process_suffix'] ?? 'Variable');function defaultProcessName(){if(year&&month&&processName){processName.value=`${year.value}${String(month.value).padStart(2,'0')}-${processSuffix}`;}}year?.addEventListener('change',defaultProcessName);month?.addEventListener('change',defaultProcessName);document.getElementById('import-form')?.addEventListener('submit',function(event){if(!confirm(`¿Confirmas la carga del proceso ${processName.value} a movimientos_courier?`)){event.preventDefault();return;}document.getElementById('import-progress').hidden=false;this.querySelector('button').disabled=true;});</script>@endpush
