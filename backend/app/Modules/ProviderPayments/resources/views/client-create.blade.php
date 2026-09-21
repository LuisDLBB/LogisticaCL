@extends('provider-payments::layout')
@section('title', 'Crear cliente')
@push('styles')
<style>.form-grid{display:grid;gap:18px;max-width:760px}.form-grid label{display:grid;gap:7px;font-weight:750}.form-grid input,.form-grid select{width:100%;padding:12px;border:1px solid var(--line);border-radius:8px;background:#fff}.help{padding:16px;border-radius:9px;background:var(--turquoise-soft)}.required-note{color:var(--muted)}</style>
@endpush
@section('content')
<a class="back" href="{{ session()->has('courier_review') ? route('provider-payments.courier-movements.review-parameters') : route('provider-payments.maintainers.clientes') }}">← Volver</a>
<p class="eyebrow">Mantenedor de clientes</p><h1>Crear cliente</h1>
<p class="intro">Puedes copiar los datos de un cliente similar o ingresar todos sus antecedentes manualmente. El RUT y la razón social siempre deben ser nuevos.</p>
<div class="card"><form class="form-grid" method="post" action="{{ route('provider-payments.maintainers.clientes.store') }}">@csrf
<label>Comerciante (Pila)<input name="source_merchant_name" value="{{ old('source_merchant_name', $merchantName) }}" required maxlength="255"></label>
<label>RUT nuevo<input name="tax_id" value="{{ old('tax_id') }}" required maxlength="15" placeholder="12345678-9"></label>
<label>Razón social nueva<input name="legal_name" value="{{ old('legal_name') }}" required maxlength="255"></label>
<label>Cliente que se usará como plantilla<select id="template_id" name="template_id"><option value="">Sin plantilla: ingresar datos manualmente</option>@foreach($templates as $template)<option value="{{ $template->id }}" data-commercial-name="{{ $template->commercial_name }}" data-billing-company-code="{{ $template->billing_company_code }}" data-billing-address="{{ $template->billing_address }}" data-billing-commune-name="{{ $template->billing_commune_name }}" data-business-activity="{{ $template->business_activity }}" @selected((string) old('template_id') === (string) $template->id)>{{ $template->legal_name }} · {{ $template->tax_id }} · {{ $template->source_merchant_name }}</option>@endforeach</select></label>
<p class="help">Al elegir una plantilla se completarán los siguientes campos automáticamente. Sin plantilla, ingrésalos uno por uno.</p>
<label>Nombre comercial<input id="commercial_name" name="commercial_name" value="{{ old('commercial_name') }}" maxlength="160" required></label>
<label>Empresa facturadora<input id="billing_company_code" name="billing_company_code" value="{{ old('billing_company_code') }}" maxlength="20" placeholder="Ejemplo: 4N o PMCB"></label>
<label>Dirección de facturación<input id="billing_address" name="billing_address" value="{{ old('billing_address') }}" maxlength="255"></label>
<label>Comuna de facturación<input id="billing_commune_name" name="billing_commune_name" value="{{ old('billing_commune_name') }}" maxlength="100"></label>
<label>Giro<input id="business_activity" name="business_activity" value="{{ old('business_activity') }}"></label>
<p class="required-note">Son obligatorios Comerciante (Pila), RUT, razón social y nombre comercial.</p>
@if($errors->any())<p class="warning">{{ $errors->first() }}</p>@endif
<button type="submit">Crear nuevo cliente</button></form></div>
@endsection
@push('scripts')
<script>
const template = document.getElementById('template_id');
const fields = ['commercialName', 'billingCompanyCode', 'billingAddress', 'billingCommuneName', 'businessActivity'];
template.addEventListener('change', function () {
    const option = this.options[this.selectedIndex];
    fields.forEach(function (field) {
        const input = document.getElementById(field.replace(/[A-Z]/g, letter => `_${letter.toLowerCase()}`));
        input.value = option.value ? (option.dataset[field] || '') : '';
    });
});
</script>
@endpush
