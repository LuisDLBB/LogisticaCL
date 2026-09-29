@extends('provider-payments::layout')
@section('title', 'Crear combinación de servicio')
@push('styles')
<style>.form-grid{display:grid;gap:18px}.form-grid[hidden]{display:none}.form-grid label{display:grid;gap:7px;font-weight:750}.form-grid input,.form-grid select{width:100%;padding:12px;border:1px solid var(--line);border-radius:8px;background:#fff}.help{padding:16px;border-radius:9px;background:var(--turquoise-soft)}.preview{padding:15px;border:1px solid var(--line);border-radius:8px}.preview strong{display:block;margin-bottom:4px}.sheet{overflow:auto;border:1px solid var(--line);border-radius:8px}.sheet table{min-width:1050px}.sheet th{position:sticky;top:0;background:#eaf8f8}.sheet td{padding:5px}.sheet input{min-width:130px;padding:8px;border:1px solid #b8caca;border-radius:3px}.sheet .fixed{background:#f3f6f6;color:#40545b}.sheet-title{margin:0}.confirm[disabled]{opacity:.55;cursor:not-allowed}</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.courier-movements.review-parameters') }}">← Volver a inconsistencias</a>
<p class="eyebrow">Mantenedor de Llave Centro Costo</p><h1>Crear combinación de servicio</h1>
<p class="intro">{{ $templates->isEmpty() ? 'No hay otro servicio configurado para este cliente. Puedes crear la relación y, si conoces el proveedor, agregar su llave de pago.' : 'Selecciona un servicio ya configurado para este cliente. Se copiarán todas sus llaves de Centro de Costo para el servicio nuevo.' }}</p>
<div class="card"><form id="combination_form" class="form-grid" method="post" action="{{ route('provider-payments.maintainers.llave-centro-costos.store') }}">@csrf
<label>Comerciante (Pila)<input name="merchant_name" value="{{ old('merchant_name', $merchantName) }}" readonly required></label>
<label>Servicio<input name="service_name" value="{{ old('service_name', $serviceName) }}" readonly required></label>
@if($templates->isEmpty())
    @if(! $targetClient)
        <p class="warning">Primero debes registrar un cliente activo para «{{ $merchantName }}». <a href="{{ route('provider-payments.maintainers.clientes', ['merchant' => $merchantName]) }}">Ir a Clientes</a>.</p>
    @elseif($targetService && ! $targetService->is_active)
        <p class="warning">Este servicio está inactivo. Revísalo en el <a href="{{ route('provider-payments.maintainers.servicios') }}">catálogo de servicios</a> antes de continuar.</p>
    @else
        <p class="help">@if(! $targetService) Se creará «{{ $serviceName }}» en el catálogo y se asociará a {{ $merchantName }}. @else Se asociará este servicio a {{ $merchantName }}. @endif Si aún no sabes qué proveedor realizará el servicio, puedes dejarlo pendiente y crear su llave después.</p>
        <label>Proveedor para la llave de pago (opcional)<select id="new_key_provider" name="provider_id"><option value="">Sin proveedor por ahora</option>@foreach($providers as $provider)<option value="{{ $provider->id }}" @selected((string) old('provider_id') === (string) $provider->id)>{{ $provider->legal_name }} · {{ $provider->tax_id }}</option>@endforeach</select></label>
        <div id="new_key_fields" class="form-grid" hidden>
            <label>Agencia<input name="agent_name" value="{{ old('agent_name') }}" placeholder="Nombre operacional del proveedor"></label>
            <label>Centro de costo<select name="cost_center_code"><option value="">Sin centro de costo</option>@foreach($costCenters as $center)<option value="{{ $center->cost_center_code }}" @selected((string) old('cost_center_code') === (string) $center->cost_center_code)>{{ $center->cost_center_code }} · {{ $center->dispatch_guide_detail }}</option>@endforeach</select></label>
            <label>Condición de pago<select name="payment_status"><option value="REVISAR" @selected(old('payment_status', 'REVISAR') === 'REVISAR')>REVISAR</option><option value="SI" @selected(old('payment_status') === 'SI')>SI</option><option value="NO" @selected(old('payment_status') === 'NO')>NO</option></select></label>
        </div>
        <button id="confirm_button" class="confirm" type="submit">Crear servicio y asociarlo al cliente</button>
    @endif
@else
<label>Servicio de {{ $merchantName }} que se usará como plantilla<select id="template_id" name="template_id" required><option value="">Selecciona un servicio de este cliente</option>@foreach($templates as $template)<option value="{{ $template->template_id }}" data-record-count="{{ $template->record_count }}" @selected((string) old('template_id') === (string) $template->template_id)>{{ $template->service_name }} · {{ $template->record_count }} registros</option>@endforeach</select></label>
@if(! $targetService)<p class="help">«{{ $serviceName }}» se creará automáticamente en Servicios con el siguiente código correlativo.</p>@endif
<div class="preview"><strong>Registros que se copiarán</strong><span id="records_preview">Selecciona una combinación</span></div>
<h2 class="sheet-title">Vista previa editable</h2>
<div id="sheet_empty" class="help">Selecciona una combinación para revisar sus registros antes de confirmar.</div>
<div id="sheet" class="sheet" hidden><table><thead><tr><th>Proveedor RUT</th><th>Agencia</th><th>RUT Cliente</th><th>Comerciante</th><th>Servicio nuevo</th><th>Centro de costo</th><th>Pagar</th></tr></thead><tbody id="sheet_body"></tbody></table></div>
<p class="help">Se copiarán todos los registros del servicio elegido, conservando sus proveedores, agencias, condiciones de pago y centros de costo. Solo se reemplazará el servicio por «{{ $serviceName }}». Puedes revisar y ajustar cada fila antes de crearla.</p>
<button id="confirm_button" class="confirm" type="submit" disabled>Confirmar y crear todos los registros</button>
@endif
@if($errors->any())<p class="warning">{{ $errors->first() }}</p>@endif
</form></div>
@endsection
@push('scripts')
<script>
@if($templates->isEmpty())
const newKeyProvider = document.getElementById('new_key_provider');
function toggleNewKeyFields() {
    const hasProvider = newKeyProvider.value !== '';
    document.getElementById('new_key_fields').hidden = !hasProvider;
    document.getElementById('confirm_button').textContent = hasProvider ? 'Crear servicio, asociación y llave' : 'Crear servicio y asociarlo al cliente';
}
newKeyProvider?.addEventListener('change', toggleNewKeyFields);
if (newKeyProvider) toggleNewKeyFields();
@else
const keyTemplate = document.getElementById('template_id');
const templateRows = @json($templateRows);
const costCenters = @json($costCenters);
const targetMerchant = @json($merchantName);
const targetService = @json($serviceName);
const targetClientRut = @json($targetClientRut);
const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character]));
const costCenterOptions = selected => `<option value="">Sin centro de costo</option>` + costCenters.map(center => `<option value="${center.cost_center_code}" ${String(center.cost_center_code) === String(selected) ? 'selected' : ''}>${center.cost_center_code} · ${escapeHtml(center.dispatch_guide_detail)}</option>`).join('');
function showKeyTemplate() {
    const option = keyTemplate.options[keyTemplate.selectedIndex];
    document.getElementById('records_preview').textContent = option.value ? `${option.dataset.recordCount} registros completos` : 'Selecciona una combinación';
    const rows = templateRows[option.value] || [];
    document.getElementById('sheet_body').innerHTML = rows.map((row, index) => `<tr>
        <td><input type="hidden" name="rows[${index}][source_id]" value="${row.source_id}"><input name="rows[${index}][provider_tax_id]" value="${escapeHtml(row.provider_tax_id)}"></td>
        <td><input name="rows[${index}][agent_name]" value="${escapeHtml(row.agent_name)}"></td>
        <td class="fixed">${escapeHtml(targetClientRut)}</td><td class="fixed">${escapeHtml(targetMerchant)}</td><td class="fixed">${escapeHtml(targetService)}</td>
        <td><select name="rows[${index}][cost_center_code]">${costCenterOptions(row.cost_center_code)}</select></td>
        <td><input name="rows[${index}][payment_status]" value="${escapeHtml(row.payment_status)}" required></td>
    </tr>`).join('');
    document.getElementById('sheet').hidden = rows.length === 0;
    document.getElementById('sheet_empty').hidden = rows.length > 0;
    document.getElementById('confirm_button').disabled = rows.length === 0;
}
keyTemplate.addEventListener('change', showKeyTemplate);
showKeyTemplate();
@endif
(() => {
    const form = document.getElementById('combination_form');
    const draftKey = 'service-combination-draft:' + @json(($reviewBatchId ?? '').'|'.$merchantName.'|'.$serviceName);
    const fields = () => [...form.querySelectorAll('input[name]:not([type=hidden]):not([readonly]), select[name]')];
    function saveDraft() {
        try {
            const values = {};
            fields().forEach(field => { values[field.name] = field.value; });
            sessionStorage.setItem(draftKey, JSON.stringify(values));
        } catch (error) { /* El formulario sigue funcionando sin almacenamiento local. */ }
    }
    try {
        const values = JSON.parse(sessionStorage.getItem(draftKey) || 'null');
        if (values) {
            if (typeof keyTemplate !== 'undefined' && values.template_id) {
                keyTemplate.value = values.template_id;
                showKeyTemplate();
            }
            fields().forEach(field => {
                if (Object.hasOwn(values, field.name)) field.value = values[field.name];
            });
            if (typeof newKeyProvider !== 'undefined' && newKeyProvider) toggleNewKeyFields();
        }
    } catch (error) { /* Se conservan los valores originales del formulario. */ }
    form.addEventListener('input', saveDraft);
    form.addEventListener('change', saveDraft);
    window.addEventListener('pagehide', saveDraft);
})();
</script>
@endpush
