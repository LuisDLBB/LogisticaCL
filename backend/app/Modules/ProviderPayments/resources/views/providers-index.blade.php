@extends('provider-payments::layout')
@section('title', 'Mantenedor: Proveedores')
@push('styles')
@include('provider-payments::partials.master-styles')
<style>
.provider-dashboard{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin:0 0 22px}.provider-metric{padding:18px}.provider-metric strong{display:block;margin-top:6px;color:var(--turquoise-dark);font-size:30px}.provider-metric.operator{border-top:4px solid var(--turquoise-dark)}.form-section{grid-column:1/-1;margin:4px 0 0;padding-top:14px;border-top:1px solid var(--line);font-size:16px}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a>
<p class="eyebrow">Gestión operacional</p>
<h1>Mantenedor: Proveedores</h1>
<p class="intro">Crea proveedores y actualiza sus datos operativos y bancarios. El RUT queda protegido porque participa en las llaves y cruces del sistema.</p>
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
            <label>RUT<input name="tax_id" value="{{ old('tax_id') }}" required placeholder="12345678-5"></label>
            <label>Razón social<input name="legal_name" value="{{ old('legal_name') }}" required></label>
            <label>Nombre operacional<input name="operational_name" value="{{ old('operational_name') }}"></label>
            <label>Tipo operador<input name="operator_type" value="{{ old('operator_type', 'Courier') }}" required></label>
            <label>Tipo documento<input name="tax_document_type" value="{{ old('tax_document_type') }}"></label>
            <label>Comuna<input name="commercial_commune_name" value="{{ old('commercial_commune_name') }}"></label>
            <label class="wide">Dirección comercial<input name="commercial_address" value="{{ old('commercial_address') }}"></label>
            <label>Contacto<input name="contact_name" value="{{ old('contact_name') }}"></label>
            <label>Teléfono<input name="contact_phone" value="{{ old('contact_phone') }}"></label>
            <label class="wide">Correo<input type="email" name="contact_email" value="{{ old('contact_email') }}"></label>
            <h3 class="form-section">Cuenta bancaria del proveedor</h3>
            <label>Banco<select name="bank_name"><option value="">Sin cuenta bancaria</option>@foreach($banks as $bank)<option value="{{ $bank->banco }}" @selected(old('bank_name') === $bank->banco)>{{ $bank->banco }} · SBIF {{ $bank->codigo_sbif }}</option>@endforeach</select></label>
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
                <summary>{{ $provider->legal_name }} · {{ $provider->tax_id }} <span class="badge {{ $provider->is_active ? '' : 'off' }}">{{ $provider->is_active ? 'Activo' : 'Inactivo' }}</span><br><span class="record-meta">{{ $provider->operational_name ?: 'Sin nombre operacional' }} · {{ $provider->operator_type }}@if($bankAccount) · {{ $bankAccount->bank_name }} · {{ $bankAccount->maskedAccountNumber() }}@endif</span></summary>
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
                    <label>Correo<input type="email" name="contact_email" value="{{ $provider->contact_email }}"></label>
                    <label>Estado<select name="is_active"><option value="1" @selected($provider->is_active)>Activo</option><option value="0" @selected(!$provider->is_active)>Inactivo</option></select></label>
                    <h3 class="form-section">Cuenta bancaria del proveedor</h3>
                    <label>Banco<select name="bank_name"><option value="">Sin cuenta bancaria</option>@foreach($banks as $bank)<option value="{{ $bank->banco }}" @selected($bankAccount?->bank_name === $bank->banco)>{{ $bank->banco }} · SBIF {{ $bank->codigo_sbif }}</option>@endforeach</select></label>
                    <label>Tipo de cuenta<select name="account_type"><option value="">Selecciona un tipo</option>@foreach($accountTypes as $accountType)<option value="{{ $accountType->tipo_cuenta }}" @selected($bankAccount?->account_type === $accountType->tipo_cuenta)>{{ $accountType->tipo_cuenta }}</option>@endforeach</select></label>
                    <label class="wide">Número de cuenta<input name="account_number" value="" placeholder="{{ $bankAccount ? $bankAccount->maskedAccountNumber().' · dejar vacío para conservar' : 'Ingresa el número de cuenta' }}"></label>
                    <button class="wide" type="submit">Guardar cambios seguros</button>
                </form>
            </details>
        @empty
            <div class="card empty">No hay proveedores creados.</div>
        @endforelse
    </section>
</div>
@endsection
