@extends('provider-payments::layout')
@section('title', 'Crear combinación de servicio')
@push('styles')
<style>.form-grid{display:grid;gap:18px}.form-grid label{display:grid;gap:7px;font-weight:750}.form-grid input,.form-grid select{width:100%;padding:12px;border:1px solid var(--line);border-radius:8px;background:#fff}.help{padding:16px;border-radius:9px;background:var(--turquoise-soft)}.preview{padding:15px;border:1px solid var(--line);border-radius:8px}.preview strong{display:block;margin-bottom:4px}.sheet{overflow:auto;border:1px solid var(--line);border-radius:8px}.sheet table{min-width:1050px}.sheet th{position:sticky;top:0;background:#eaf8f8}.sheet td{padding:5px}.sheet input{min-width:130px;padding:8px;border:1px solid #b8caca;border-radius:3px}.sheet .fixed{background:#f3f6f6;color:#40545b}.sheet-title{margin:0}.confirm[disabled]{opacity:.55;cursor:not-allowed}</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.courier-movements.review-parameters') }}">← Volver a inconsistencias</a>
<p class="eyebrow">Mantenedor de Llave Centro Costo</p><h1>Crear combinación de servicio</h1>
<p class="intro">Selecciona una combinación agrupada por Comerciante y Servicio. Se copiarán todos sus registros de Llave Centro Costo para el cliente nuevo.</p>
<div class="card"><form class="form-grid" method="post" action="{{ route('provider-payments.maintainers.llave-centro-costos.store') }}">@csrf
<label>Comerciante (Pila)<input name="merchant_name" value="{{ old('merchant_name', $merchantName) }}" readonly required></label>
<label>Servicio<input name="service_name" value="{{ old('service_name', $serviceName) }}" readonly required></label>
<label>Combinación que se usará como plantilla<select id="template_id" name="template_id" required><option value="">Selecciona Comerciante + Servicio</option>@foreach($templates as $template)<option value="{{ $template->template_id }}" data-record-count="{{ $template->record_count }}" @selected((string) old('template_id') === (string) $template->template_id)>{{ $template->merchant_name }} · {{ $template->service_name }} · {{ $template->record_count }} registros</option>@endforeach</select></label>
<div class="preview"><strong>Registros que se copiarán</strong><span id="records_preview">Selecciona una combinación</span></div>
<h2 class="sheet-title">Vista previa editable</h2>
<div id="sheet_empty" class="help">Selecciona una combinación para revisar sus registros antes de confirmar.</div>
<div id="sheet" class="sheet" hidden><table><thead><tr><th>Proveedor RUT</th><th>Agencia</th><th>RUT Cliente nuevo</th><th>Comerciante nuevo</th><th>Servicio</th><th>Centro de costo</th><th>Pagar</th></tr></thead><tbody id="sheet_body"></tbody></table></div>
<p class="help">Se replicarán todos los registros coincidentes, conservando sus proveedores, agencias, condiciones de pago y centros de costo. Solo se reemplazarán el RUT Cliente, Comerciante y la relación con el cliente nuevo.</p>
@if($errors->any())<p class="warning">{{ $errors->first() }}</p>@endif
<button id="confirm_button" class="confirm" type="submit" disabled>Confirmar y crear todos los registros</button></form></div>
@endsection
@push('scripts')
<script>
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
</script>
@endpush
