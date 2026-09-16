@extends('provider-payments::layout')
@section('title', 'Revisión de inconsistencias')
@push('styles')
<style>
    .review-group{padding:0;margin:14px 0;overflow:hidden}.review-group summary{padding:20px 22px;cursor:pointer;font-size:17px;font-weight:800;list-style:none}.review-group summary::-webkit-details-marker{display:none}.review-group summary::before{display:inline-block;margin-right:10px;content:'▶';color:var(--turquoise-dark);font-size:12px;transition:.2s}.review-group[open] summary::before{transform:rotate(90deg)}.review-group summary span{margin-left:10px;color:var(--muted);font-size:13px;font-weight:500}.review-group .table-wrap{border-top:1px solid var(--line)}.empty{padding:18px 22px;color:var(--turquoise-dark)}.tenant-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:20px 0}.tenant-row label{font-weight:700}.tenant-row select{padding:10px;border:1px solid var(--line);border-radius:7px;background:#fff}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.courier-movements.upload') }}">← Cargar otro archivo</a>
<p class="eyebrow">Pago a proveedores</p><h1>Revisión de inconsistencias</h1><p class="intro">Comprueba los datos pendientes antes de incorporar los movimientos.</p>
@if (! $snapshot)
<div class="card"><h2>Necesitamos validar el archivo nuevamente</h2><p>La validación anterior no conservaba los datos necesarios para esta revisión. Selecciona el archivo una vez más para obtener el detalle agrupado.</p></div>
@else
<div class="card">
    <strong>{{ $snapshot['file'] }}</strong><p>{{ number_format($snapshot['records'], 0, ',', '.') }} registros analizados. Esta revisión todavía no guarda movimientos.</p>
    <p class="note">Cada desplegable agrupa valores pendientes e indica qué corregir. Un registro puede aparecer en varios grupos; sus totales no deben sumarse.</p>
    <form id="review-form" class="tenant-row" method="get"><label for="tenant">Empresa propietaria</label><select id="tenant" name="tenant" required><option value="">Selecciona una empresa</option>@foreach($tenants as $company)<option value="{{ $company->id }}" @selected($tenant?->id === $company->id)>{{ $company->name }}</option>@endforeach</select><button type="submit">Volver a revisar maestros</button></form>
    <div id="review-progress" hidden role="status"><p>Comparando datos con los maestros…</p><progress aria-label="Revisando parámetros"></progress></div>
    @if (! $tenant)<p class="warning">No hay empresa seleccionada{{ $tenants->isEmpty() ? ' o registrada para esta revisión' : '' }}. Los clientes y coberturas se muestran como pendientes de comprobar, sin mezclar datos de distintas empresas.</p>@endif
    @if($snapshot['missing_columns'])<p class="warning">Columnas no identificadas: {{ implode(', ', $snapshot['missing_columns']) }}. Revisa los encabezados del archivo.</p>@endif
    <p class="note">Las coincidencias de nombres y comunas son exactas. Los pesos quedan pendientes porque aún falta implementar su maestro de transformación.</p>
    <p class="note"><strong>Cruce de clientes:</strong> Comerciante del archivo → Comerciante (Pila) del maestro de clientes → RUT y razón social.</p>
</div>
@foreach($groups as $group)
<details class="review-group"><summary>{{ $group['title'] }} <span>{{ count($group['items']) }} valores pendientes · {{ number_format($group['affected'], 0, ',', '.') }} registros afectados</span></summary>
@if(! $group['items'])<p class="empty">Sin inconsistencias en los cruces comprobados de este grupo.</p>@else
<div class="table-wrap"><table><thead><tr><th>Dato del archivo</th><th>Registros</th><th>Qué ingresar o corregir</th></tr></thead><tbody>@foreach($group['items'] as $item)<tr><td>@foreach($item['values'] as $value){{ $value === '' ? '(Vacío)' : $value }}@if(! $loop->last) → @endif @endforeach</td><td>{{ number_format($item['count'], 0, ',', '.') }}</td><td>{{ $item['action'] }}</td></tr>@endforeach</tbody></table></div>
@endif</details>
@endforeach
@endif
@endsection
@push('scripts')<script>document.getElementById('review-form')?.addEventListener('submit',function(){document.getElementById('review-progress').hidden=false;this.querySelector('button').disabled=true;});</script>@endpush
