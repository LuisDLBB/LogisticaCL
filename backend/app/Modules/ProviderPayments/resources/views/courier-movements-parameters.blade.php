<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inconsistencias del archivo · 4N</title>
<style>
body{font-family:system-ui,sans-serif;background:#f6f8f8;margin:0;color:#201e1f}main{max-width:1100px;margin:40px auto;padding:0 24px}a{color:#277d80}.card,details{background:white;border:1px solid #dce5e5;border-radius:12px;padding:22px;margin:18px 0}summary{cursor:pointer;font-weight:700;font-size:19px}summary span{font-size:14px;font-weight:400;color:#526467;margin-left:12px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;margin-top:18px}td,th{padding:12px;text-align:left;border-bottom:1px solid #e5eeee}th{background:#edf8f8}td:first-child{white-space:pre-wrap}.note{color:#596a6d}.warning{border-left:5px solid #db9f34;padding:16px;background:#fff6e4}button{background:#277d80;color:white;padding:12px 18px;border:0;border-radius:7px;cursor:pointer}select{padding:10px;max-width:100%}progress{width:100%;accent-color:#5db8bc}.empty{padding:16px;color:#277d80}button:disabled{opacity:.6}
</style>
</head>
<body><main>
<a href="{{ route('provider-payments.courier-movements.upload') }}">← Cargar otro archivo</a>
<h1>Revisión de inconsistencias</h1>
@if (! $snapshot)
<div class="card"><h2>Necesitamos validar el archivo nuevamente</h2><p>La validación anterior no conservaba los datos necesarios para esta revisión. Selecciona el archivo una vez más para obtener el detalle agrupado.</p></div>
@else
<div class="card">
<strong>{{ $snapshot['file'] }}</strong>
<p>{{ number_format($snapshot['records'], 0, ',', '.') }} registros analizados. Esta revisión todavía no guarda movimientos.</p>
<p class="note">Se muestra la última validación de esta sesión. Cada desplegable agrupa valores pendientes e indica qué corregir. Un registro puede aparecer en varios grupos; sus totales no deben sumarse.</p>
<form id="review-form" method="get">
<label for="tenant">Empresa propietaria del archivo</label>
<select id="tenant" name="tenant" required>
<option value="">Selecciona una empresa</option>
@foreach($tenants as $company)
<option value="{{ $company->id }}" @selected($tenant?->id === $company->id)>{{ $company->name }}</option>
@endforeach
</select>
<button type="submit">Volver a revisar maestros</button>
</form>
<div id="review-progress" hidden role="status"><p>Comparando datos con los maestros…</p><progress aria-label="Revisando parámetros"></progress></div>
@if (! $tenant)
<p class="warning">No hay empresa seleccionada{{ $tenants->isEmpty() ? ' o registrada para esta revisión' : '' }}. Los clientes y coberturas se muestran como pendientes de comprobar, sin mezclar datos de distintas empresas.</p>
@endif
@if($snapshot['missing_columns'])
<p class="warning">Columnas no identificadas: {{ implode(', ', $snapshot['missing_columns']) }}. Revisa los encabezados del archivo; los cruces asociados no pueden completarse.</p>
@endif
<p class="note">Las coincidencias de nombres y comunas son exactas. Los pesos quedan pendientes porque aún falta implementar su maestro de transformación. Los mantenedores actuales todavía no permiten editar estos datos desde esta pantalla.</p>
<p class="note"><strong>Cruce de clientes:</strong> Comerciante del archivo → Comerciante (Pila) del maestro de clientes → RUT y razón social.</p>
</div>
@foreach($groups as $group)
<details>
<summary>{{ $group['title'] }} <span>{{ count($group['items']) }} valores pendientes · {{ number_format($group['affected'], 0, ',', '.') }} registros afectados</span></summary>
@if(! $group['items'])
<p class="empty">Sin inconsistencias en los cruces comprobados de este grupo.</p>
@else
<div class="table-wrap"><table><thead><tr><th>Dato del archivo</th><th>Registros</th><th>Qué ingresar o corregir</th></tr></thead><tbody>
@foreach($group['items'] as $item)
<tr><td>@foreach($item['values'] as $value){{ $value === '' ? '(Vacío)' : $value }}@if(! $loop->last) → @endif @endforeach</td><td>{{ number_format($item['count'], 0, ',', '.') }}</td><td>{{ $item['action'] }}</td></tr>
@endforeach
</tbody></table></div>
@endif
</details>
@endforeach
@endif
</main><script>
document.getElementById('review-form')?.addEventListener('submit',function(){document.getElementById('review-progress').hidden=false;this.querySelector('button').disabled=true;});
</script></body></html>
