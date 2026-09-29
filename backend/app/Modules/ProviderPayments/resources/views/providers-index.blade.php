@extends('provider-payments::layout')
@section('title', 'Mantenedor: Proveedores')
@push('styles')
@include('provider-payments::partials.master-styles')
<style>
.provider-dashboard{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin:0 0 22px}.provider-metric{padding:18px}.provider-metric strong{display:block;margin-top:6px;color:var(--turquoise-dark);font-size:30px}.provider-metric.operator{border-top:4px solid var(--turquoise-dark)}.form-section{grid-column:1/-1;margin:4px 0 0;padding-top:14px;border-top:1px solid var(--line);font-size:16px}
.oc-filename-section{margin-top:15px;padding-top:12px;border-top:1px solid var(--line)}.oc-filename-section h3{font-size:16px;margin:0 0 8px}.oc-filename-section p{font-size:13px;margin:0 0 10px}.oc-filename-form{display:grid;grid-template-columns:minmax(95px,120px) minmax(170px,1fr) minmax(160px,1fr) auto;align-items:end;gap:8px;margin:8px 0}.oc-filename-form label{font-size:12px}.oc-filename-form input,.oc-filename-form select{width:100%}@media(max-width:760px){.oc-filename-form{grid-template-columns:repeat(2,minmax(0,1fr))}.oc-filename-form button{grid-column:1/-1}}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a>
<p class="eyebrow">Gestión operacional</p>
<h1>Mantenedor: Proveedores</h1>
@if($returnPeriod)<p><a class="back" href="{{ route('provider-payments.courier-movements.rutas-cv', ['periodo' => $returnPeriod]) }}">← Volver a Rutas CV {{ $returnPeriod }}</a></p>@endif
<p class="intro">Crea proveedores y actualiza sus datos operativos, correo electrónico y cuenta bancaria. El RUT queda protegido porque participa en las llaves y cruces del sistema.</p>
@if(session('status'))<div class="status">{{ session('status') }}</div>@endif
@php
    $operatorCounts = $providers
        ->groupBy(fn ($provider) => filled($provider->operator_type) ? trim($provider->operator_type) : 'Sin tipo operador')
        ->map->count()
        ->sortDesc();
@endphp
<section class="provider-dashboard" aria-label="Resumen de proveedores por tipo de operador">
    <div class="card provider-metric"><span class="note">Total de proveedores</span><strong>{{ number_format($providers->count(), 0, ',', '.') }}</strong></div>
    @foreach($operatorCounts as $operatorType => $operatorCount)
        <div class="card provider-metric operator"><span class="note">{{ $operatorType }}</span><strong>{{ number_format($operatorCount, 0, ',', '.') }}</strong></div>
    @endforeach
</section>
<div class="master-grid">
    <section class="card">
        <h2>Nuevo proveedor</h2>
        <form class="form-grid" method="post" action="{{ route('provider-payments.maintainers.proveedores.store') }}">
            @csrf
            @if($returnPeriod)<input type="hidden" name="return_period" value="{{ $returnPeriod }}">@endif
            <label>RUT<input name="tax_id" value="{{ old('tax_id') }}" required placeholder="12345678-5"></label>
            <label>Razón social<input name="legal_name" value="{{ old('legal_name') }}" required></label>
            <label>Nombre operacional<input name="operational_name" value="{{ old('operational_name') }}"></label>
            <label>Tipo operador<input name="operator_type" value="{{ old('operator_type', 'Courier') }}" required></label>
            <label>Tipo documento<input name="tax_document_type" value="{{ old('tax_document_type') }}"></label>
            <label>Comuna<input name="commercial_commune_name" value="{{ old('commercial_commune_name') }}"></label>
            <label class="wide">Dirección comercial<input name="commercial_address" value="{{ old('commercial_address') }}"></label>
            <label>Contacto<input name="contact_name" value="{{ old('contact_name') }}"></label>
            <label>Teléfono<input name="contact_phone" value="{{ old('contact_phone') }}"></label>
            <label>Correo electrónico principal<input type="email" name="contact_email" value="{{ old('contact_email') }}" autocomplete="email" placeholder="nombre@empresa.cl"></label>
            <label>Correo electrónico secundario<input type="email" name="contact_email_secondary" value="{{ old('contact_email_secondary') }}" placeholder="otro@empresa.cl"></label>
            <label>Condición de pago<input name="payment_terms" value="{{ old('payment_terms') }}" placeholder="Ej.: 45 días o contado"></label>
            <label>Condición de pago PMCB (si difiere)<input name="payment_terms_pmcb" value="{{ old('payment_terms_pmcb') }}" placeholder="Si se deja vacío, usa la condición general"></label>
            <h3 class="form-section">Cuenta bancaria del proveedor</h3>
            <label>Titular de la cuenta<input name="account_holder_name" value="{{ old('account_holder_name') }}" placeholder="Razón social del proveedor si se deja vacío"></label>
            <label>RUT del titular<input name="account_holder_tax_id" value="{{ old('account_holder_tax_id') }}" placeholder="RUT del proveedor si se deja vacío"></label>
            <label>Banco<select name="bank_name"><option value="">Sin cuenta bancaria</option>@foreach($banks as $bank)<option value="{{ $bank->banco }}" @selected(old('bank_name') === $bank->banco)>{{ $bank->banco }}</option>@endforeach</select></label>
            <label>Tipo de cuenta<select name="account_type"><option value="">Selecciona un tipo</option>@foreach($accountTypes as $accountType)<option value="{{ $accountType->tipo_cuenta }}" @selected(old('account_type') === $accountType->tipo_cuenta)>{{ $accountType->tipo_cuenta }}</option>@endforeach</select></label>
            <label class="wide">Número de cuenta<input name="account_number" value="{{ old('account_number') }}"></label>
            @if($errors->any())<p class="warning wide">{{ $errors->first() }}</p>@endif
            <button class="wide" type="submit">Crear proveedor</button>
        </form>
    </section>
    <section>
        <h2>Proveedores creados <span class="note">({{ $providers->count() }})</span></h2>
        @forelse($providers as $provider)
            @php($bankAccount = $provider->bankAccounts->sortByDesc('is_primary')->first())
            <details class="record" data-operator="{{ $provider->operator_type }}">
                <summary>{{ $provider->legal_name }} · {{ $provider->tax_id }} <span class="badge {{ $provider->is_active ? '' : 'off' }}">{{ $provider->is_active ? 'Activo' : 'Inactivo' }}</span><br><span class="record-meta">{{ $provider->operational_name ?: 'Sin nombre operacional' }} · {{ $provider->operator_type }} · {{ $provider->contact_email ?: 'Sin correo electrónico' }}@if($provider->contact_email_secondary) · {{ $provider->contact_email_secondary }}@endif @if($provider->payment_terms) · Pago: {{ $provider->payment_terms }}@endif @if($provider->payment_terms_pmcb) · PMCB: {{ $provider->payment_terms_pmcb }}@endif @if($bankAccount) · {{ $bankAccount->bank_name }} · {{ $bankAccount->maskedAccountNumber() }}@endif</span></summary>
                <form class="form-grid" method="post" action="{{ route('provider-payments.maintainers.proveedores.update', $provider) }}">
                    @csrf @method('PUT')
                    <div class="protected wide"><strong>RUT protegido:</strong> {{ $provider->tax_id }}</div>
                    <label>Razón social<input name="legal_name" value="{{ $provider->legal_name }}" required></label>
                    <label>Nombre operacional<input name="operational_name" value="{{ $provider->operational_name }}"></label>
                    <label>Tipo operador<input name="operator_type" value="{{ $provider->operator_type }}" required></label>
                    <label>Tipo documento<input name="tax_document_type" value="{{ $provider->tax_document_type }}"></label>
                    <label class="wide">Dirección<input name="commercial_address" value="{{ $provider->commercial_address }}"></label>
                    <label>Comuna<input name="commercial_commune_name" value="{{ $provider->commercial_commune_name }}"></label>
                    <label>Contacto<input name="contact_name" value="{{ $provider->contact_name }}"></label>
                    <label>Teléfono<input name="contact_phone" value="{{ $provider->contact_phone }}"></label>
                    <label>Correo electrónico principal<input type="email" name="contact_email" value="{{ $provider->contact_email }}" autocomplete="email" placeholder="nombre@empresa.cl"></label>
                    <label>Correo electrónico secundario<input type="email" name="contact_email_secondary" value="{{ $provider->contact_email_secondary }}" placeholder="otro@empresa.cl"></label>
                    <label>Condición de pago<input name="payment_terms" value="{{ $provider->payment_terms }}" placeholder="Ej.: 45 días o contado"></label>
                    <label>Condición de pago PMCB (si difiere)<input name="payment_terms_pmcb" value="{{ $provider->payment_terms_pmcb }}" placeholder="Si se deja vacío, usa la condición general"></label>
                    <label>Estado<select name="is_active"><option value="1" @selected($provider->is_active)>Activo</option><option value="0" @selected(!$provider->is_active)>Inactivo</option></select></label>
                    <h3 class="form-section">Cuenta bancaria del proveedor</h3>
                    <label>Titular de la cuenta<input name="account_holder_name" value="{{ $bankAccount?->account_holder_name }}"></label>
                    <label>RUT del titular<input name="account_holder_tax_id" value="{{ $bankAccount?->account_holder_tax_id }}"></label>
                    <label>Banco<select name="bank_name"><option value="">Sin cuenta bancaria</option>@foreach($banks as $bank)<option value="{{ $bank->banco }}" @selected($bankAccount?->bank_name === $bank->banco)>{{ $bank->banco }}</option>@endforeach</select></label>
                    <label>Tipo de cuenta<select name="account_type"><option value="">Selecciona un tipo</option>@foreach($accountTypes as $accountType)<option value="{{ $accountType->tipo_cuenta }}" @selected($bankAccount?->account_type === $accountType->tipo_cuenta)>{{ $accountType->tipo_cuenta }}</option>@endforeach</select></label>
                    <label class="wide">Número de cuenta<input name="account_number" value="" placeholder="{{ $bankAccount ? $bankAccount->maskedAccountNumber().' · dejar vacío para conservar' : 'Ingresa el número de cuenta' }}"></label>
                    <button class="wide" type="submit">Guardar cambios seguros</button>
                </form>
                <section class="oc-filename-section">
                    <h3>Nombres de archivo para órdenes de compra</h3>
                    <p class="note">Se elige por empresa mandante y agrupación del servicio. El PDF y Excel usan el mismo nombre.</p>
                    @foreach($provider->ocFilenames->sortBy(['company_code', 'service_scope']) as $filename)
                        <form class="oc-filename-form" method="post" action="{{ route('provider-payments.maintainers.proveedores.oc-filename', $provider) }}">
                            @csrf
                            <label>Empresa mandante<input value="{{ $filename->company_code }}" readonly><input type="hidden" name="company_code" value="{{ $filename->company_code }}"></label>
                            <label>Agrupación<input value="{{ $filename->service_scope }}" readonly><input type="hidden" name="service_scope" value="{{ $filename->service_scope }}"></label>
                            <label>Nombre de archivo<input name="file_stem" value="{{ $filename->file_stem }}" maxlength="100" pattern="[A-Za-z0-9_-]+" required></label>
                            <button type="submit">Guardar</button>
                        </form>
                    @endforeach
                    <form class="oc-filename-form" method="post" action="{{ route('provider-payments.maintainers.proveedores.oc-filename', $provider) }}">
                        @csrf
                        <label>Empresa mandante<select name="company_code" required><option value="4N">4N</option><option value="PMCB">PMCB</option></select></label>
                        <label>Agrupación<select name="service_scope" required><option value="General">General</option><option value="Troncal Norte">Troncal Norte</option><option value="Troncal V">Troncal V</option><option value="Servicios hasta 14/08/2026">Servicios hasta 14/08/2026</option><option value="Servicios hasta 17/09/2026">Servicios hasta 17/09/2026</option></select></label>
                        <label>Nuevo nombre de archivo<input name="file_stem" maxlength="100" pattern="[A-Za-z0-9_-]+" required placeholder="Ej.: DSG_Troncal_Norte"></label>
                        <button type="submit">Agregar</button>
                    </form>
                </section>
            </details>
        @empty
            <div class="card empty">No hay proveedores creados.</div>
        @endforelse
    </section>
</div>
@endsection
