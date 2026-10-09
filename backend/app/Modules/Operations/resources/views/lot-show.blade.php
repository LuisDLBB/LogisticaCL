@extends('operations::layout')
@section('title','Bultos e incidencias')
@section('content')
<h1>Proceso #{{ $lot->id }} · {{ $lot->name }}</h1><p class="intro">{{ $lot->operation_date }} · Maestro #{{ $lot->master_load_id }}</p>
<div class="ope-grid"><div class="card"><span class="ope-stat">{{ $count }}</span>Bultos incluidos</div><div class="card"><span class="ope-stat">{{ number_format((float)$weight,3,',','.') }} kg</span>Peso de Operaciones y respaldo Geolize</div><div class="card"><span class="ope-stat">{{ $issues->whereNull('resolved_at')->count() }}</span>Incidencias pendientes</div></div>
@if($issues->whereNull('resolved_at')->count())<p class="warning">El total de peso es provisional hasta resolver las incidencias. Las salidas quedan bloqueadas.</p>@endif
<div class="ope-actions"><a class="button" href="{{ route('operations.departures.index',$lot->id) }}">Programar salidas</a><a href="{{ route('operations.lots.index') }}">Volver a procesos</a></div>
<h2>Incidencias</h2>
@if(\App\Modules\Operations\Services\OperationAccess::supervisor(request()) && $issues->whereNull('resolved_at')->where('code','!=','empty_lot')->isNotEmpty())
<div class="ope-bulk-save no-print" data-resolutions-endpoint="{{ route('operations.issues.resolve-many',$lot->id) }}" data-csrf="{{ csrf_token() }}">
<button type="button" data-save-resolutions>Guardar resoluciones ingresadas</button>
<span class="note" data-save-status role="status">Las lecturas duplicadas conservarán la opción de mayor peso sin pedir un motivo. Las otras incidencias requieren motivo.</span>
</div>
@endif
@forelse($issues as $issue)<details class="card" @if(!$issue->resolved_at) open @endif><summary><span class="ope-badge {{ $issue->resolved_at ? '' : 'ope-error' }}">{{ $issue->resolved_at ? 'Resuelta' : 'Pendiente' }}</span> #{{ $issue->id }} · {{ $issue->message }}</summary>
@if($issue->code==='coverage_conflict')
<h3>Datos de Maestro Geolize para identificar la cobertura</h3>
@forelse($masterRowsByIssue->get($issue->id, collect()) as $masterRow)
@php($masterData=json_decode($masterRow->data,true) ?: [])
@php($originalValues=json_decode($masterRow->raw,true) ?: [])
<p class="note">Carga Maestro #{{ $lot->master_load_id }} · fila {{ $masterRow->line }}</p>
<div class="table-wrap"><table class="ope-table"><tbody>
@foreach(['tracking'=>'Código de paquete','commune'=>'Comuna de destino','merchant'=>'Comerciante','service'=>'Servicio','recipient'=>'Nombre del destinatario','address'=>'Dirección de entrega','geolize_guide'=>'Guía de despacho Geolize'] as $field=>$label)
<tr><th scope="row">{{ $label }}</th><td>{{ $masterData[$field] ?? '—' }}</td></tr>
@endforeach
</tbody></table></div>
<details><summary>Ver todos los valores originales de la fila Geolize</summary>
<div class="table-wrap"><table class="ope-table"><thead><tr><th>Columna del Excel</th><th>Valor original</th></tr></thead><tbody>
@foreach($originalValues as $index=>$value)<tr><th scope="row">{{ \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index+1) }}</th><td>{{ $value === null || $value === '' ? '—' : (is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE)) }}</td></tr>@endforeach
</tbody></table></div></details>
@foreach(json_decode($masterRow->errors,true) ?: [] as $error)<p class="warning">Fila Geolize: {{ $error }}</p>@endforeach
@empty<p class="warning">No se encontró una fila de este paquete en el Maestro seleccionado. Revisa la carga original antes de elegir una cobertura.</p>@endforelse
<p><a href="{{ route('operations.loads.show',$lot->master_load_id) }}">Ver todas las filas del Maestro Geolize</a></p>
@endif
@if($issue->resolved_at)<p>{{ $issue->resolution }} · Usuario #{{ $issue->resolved_by }} · {{ $issue->resolved_at }}</p>
@else
@php($context=json_decode($issue->context,true))
@if($issue->code==='coverage_conflict' && $suggestedCoverageByIssue->get($issue->id))<p class="note">Coincidencia exacta con la comuna de Geolize: cobertura #{{ $suggestedCoverageByIssue->get($issue->id)->id }} · {{ $suggestedCoverageByIssue->get($issue->id)->commune_name }}. Revisa esta ficha antes de guardar la resolución.</p>@endif
@if($issue->code==='reading_conflict' && $preferredReadingByIssue->get($issue->id))<p class="note">Está preseleccionada la lectura de mayor peso. Se guardará sin motivo; si eliges otra, explica el cambio.</p>@endif
@if(isset($context['readings']))<table class="ope-table"><thead><tr><th>Lectura</th><th>Fecha</th><th>Peso kg</th><th>Operario</th><th>Guía cliente</th></tr></thead><tbody>@foreach($context['readings'] as $index=>$reading)<tr><td>#{{ $context['row_ids'][$index] }}</td><td>{{ $reading['date'] }}</td><td>{{ $reading['weight'] }}</td><td>{{ $reading['operator'] }}</td><td>{{ $reading['customer_guide'] }}</td></tr>@endforeach</tbody></table>@endif
@if(\App\Modules\Operations\Services\OperationAccess::supervisor(request()) && $issue->code !== 'empty_lot')<form class="ope-form" method="POST" action="{{ route('operations.issues.resolve',[$lot->id,$issue->id]) }}" data-issue-resolution="{{ $issue->id }}">@csrf
<label>Resolución<select name="action">@if($issue->code==='reading_conflict')<option value="reading">Elegir lectura de Recepción</option>@endif @if($issue->code==='coverage_conflict')<option value="coverage">Elegir cobertura vigente</option>@endif<option value="exclude">Excluir justificadamente del proceso</option></select></label>
@if($issue->code==='reading_conflict')<label>Lectura correcta<select name="reading_id">@foreach($context['row_ids'] as $index=>$id)<option value="{{ $id }}" @selected((int)$preferredReadingByIssue->get($issue->id)===(int)$id)>Lectura #{{ $id }} · {{ $context['readings'][$index]['weight'] ?? '—' }} kg</option>@endforeach</select></label>@endif
@if($issue->code==='coverage_conflict')<label>Cobertura<select name="coverage_id"><option value="">Seleccionar</option>@foreach($coverages as $coverage)<option value="{{ $coverage->id }}">#{{ $coverage->id }} · {{ $coverage->commune_name }} · {{ $coverage->provider_name_source }} · {{ $coverage->trunk_name }} / {{ $coverage->post_name }}</option>@endforeach</select></label>@endif
<label>Motivo de la resolución<textarea name="reason" maxlength="1000" @if($issue->code!=='reading_conflict') minlength="10" required @endif></textarea></label><button>Guardar solo esta</button></form>
@else<p class="note">La resolución requiere un supervisor. Si no hay lecturas válidas, corrige el archivo y prepara un nuevo proceso.</p>@endif
@endif</details>@empty<p class="note">Sin incidencias. Puedes programar las salidas.</p>@endforelse
<h2>Bultos</h2><form class="ope-actions" method="GET"><input type="search" name="search" value="{{ request('search') }}" placeholder="Código del paquete"><button>Buscar</button></form>
<div class="card table-wrap"><table class="ope-table"><thead><tr><th>Código completo</th><th>Cliente / Servicio</th><th>Comuna</th><th>Peso kg</th><th>Operario</th><th>Guía cliente / Referencia</th><th>Estado</th></tr></thead><tbody>@foreach($packages as $package)<tr><td>{{ $package->tracking }}</td><td>{{ $package->merchant }} / {{ $package->service }}</td><td>{{ $package->commune }}<small> · Cobertura #{{ $package->coverage_id }}</small></td><td>{{ $package->weight ?? 'Pendiente' }}</td><td>{{ $package->operator }}</td><td>{{ $package->customer_guide ?: 'Sin guía cliente' }} / {{ $package->reference }}</td><td>{{ $package->excluded ? 'Excluido' : 'Incluido' }}</td></tr>@endforeach</tbody></table></div>@include('operations::pager',['rows'=>$packages])
@endsection
@push('scripts')
<script>
document.querySelector('[data-resolutions-endpoint]')?.querySelector('[data-save-resolutions]')?.addEventListener('click', async function () {
    const toolbar = this.closest('[data-resolutions-endpoint]');
    const status = toolbar.querySelector('[data-save-status]');
    const forms = [...document.querySelectorAll('[data-issue-resolution]')].filter(form => form.elements.action.value === 'reading' || form.elements.reason.value.trim() !== '');
    if (forms.length === 0) {
        status.textContent = 'No hay lecturas duplicadas pendientes. Escribe el motivo de otra incidencia antes de guardar.';
        return;
    }
    for (const form of forms) {
        form.closest('details').open = true;
        if (!form.reportValidity()) {
            form.scrollIntoView({behavior: 'smooth', block: 'center'});
            status.textContent = 'Revisa el motivo y los datos de la incidencia indicada.';
            return;
        }
    }
    const resolutions = forms.map(form => {
        const data = new FormData(form);
        return {
            issue_id: Number(form.dataset.issueResolution),
            action: data.get('action'),
            reason: String(data.get('reason')).trim(),
            reading_id: data.get('reading_id') || null,
            coverage_id: data.get('coverage_id') || null,
        };
    });
    this.disabled = true;
    status.textContent = `Guardando ${resolutions.length} resoluciones…`;
    try {
        const response = await fetch(toolbar.dataset.resolutionsEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': toolbar.dataset.csrf},
            body: JSON.stringify({resolutions}),
        });
        if (response.ok) {
            window.location.reload();
            return;
        }
        const result = await response.json().catch(() => ({}));
        status.textContent = Object.values(result.errors || {}).flat()[0] || (response.status === 419 ? 'La sesión venció. Actualiza la página y vuelve a intentar.' : 'No se guardó ninguna resolución. Revisa los datos e inténtalo de nuevo.');
    } catch (error) {
        status.textContent = 'No se pudo conectar. Tus datos siguen en pantalla; vuelve a intentar.';
    } finally {
        this.disabled = false;
    }
});
</script>
@endpush
