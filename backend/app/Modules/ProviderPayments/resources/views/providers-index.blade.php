@extends('provider-payments::layout')
@section('title', 'Mantenedor: Proveedores')
@push('styles')
@include('provider-payments::partials.master-styles')
<style>
.provider-dashboard{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin:0 0 22px}.provider-metric{padding:18px}.provider-metric strong{display:block;margin-top:6px;color:var(--turquoise-dark);font-size:30px}.provider-metric.operator{border-top:4px solid var(--turquoise-dark)}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a><p class="eyebrow">Gestión operacional</p><h1>Mantenedor: Proveedores</h1><p class="intro">Crea proveedores y actualiza sus datos operativos. El RUT queda protegido porque participa en las llaves y cruces del sistema.</p>
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
<div class="master-grid"><section class="card"><h2>Nuevo proveedor</h2><form class="form-grid" method="post" action="{{ route('provider-payments.maintainers.proveedores.store') }}">@csrf
<label>RUT<input name="tax_id" value="{{ old('tax_id') }}" required placeholder="12345678-5"></label><label>Razón social<input name="legal_name" value="{{ old('legal_name') }}" required></label>
<label>Nombre operacional<input name="operational_name" value="{{ old('operational_name') }}"></label><label>Tipo operador<input name="operator_type" value="{{ old('operator_type', 'Courier') }}" required></label>
<label>Tipo documento<input name="tax_document_type" value="{{ old('tax_document_type') }}"></label><label>Comuna<input name="commercial_commune_name" value="{{ old('commercial_commune_name') }}"></label>
<label class="wide">Dirección comercial<input name="commercial_address" value="{{ old('commercial_address') }}"></label><label>Contacto<input name="contact_name" value="{{ old('contact_name') }}"></label><label>Teléfono<input name="contact_phone" value="{{ old('contact_phone') }}"></label><label class="wide">Correo<input type="email" name="contact_email" value="{{ old('contact_email') }}"></label>
@if($errors->any())<p class="warning wide">{{ $errors->first() }}</p>@endif<button class="wide" type="submit">Crear proveedor</button></form></section>
<section><h2>Proveedores creados <span class="note">({{ $providers->count() }})</span></h2>@forelse($providers as $provider)<details class="record" data-operator="{{ $provider->operator_type }}"><summary>{{ $provider->legal_name }} · {{ $provider->tax_id }} <span class="badge {{ $provider->is_active ? '' : 'off' }}">{{ $provider->is_active ? 'Activo' : 'Inactivo' }}</span><br><span class="record-meta">{{ $provider->operational_name ?: 'Sin nombre operacional' }} · {{ $provider->operator_type }} · Selecciona para editar datos seguros</span></summary><form class="form-grid" method="post" action="{{ route('provider-payments.maintainers.proveedores.update', $provider) }}">@csrf @method('PUT')
<div class="protected wide"><strong>RUT protegido:</strong> {{ $provider->tax_id }}</div><label>Razón social<input name="legal_name" value="{{ $provider->legal_name }}" required></label><label>Nombre operacional<input name="operational_name" value="{{ $provider->operational_name }}"></label><label>Tipo operador<input name="operator_type" value="{{ $provider->operator_type }}" required></label><label>Tipo documento<input name="tax_document_type" value="{{ $provider->tax_document_type }}"></label><label class="wide">Dirección<input name="commercial_address" value="{{ $provider->commercial_address }}"></label><label>Comuna<input name="commercial_commune_name" value="{{ $provider->commercial_commune_name }}"></label><label>Contacto<input name="contact_name" value="{{ $provider->contact_name }}"></label><label>Teléfono<input name="contact_phone" value="{{ $provider->contact_phone }}"></label><label>Correo<input type="email" name="contact_email" value="{{ $provider->contact_email }}"></label><label>Estado<select name="is_active"><option value="1" @selected($provider->is_active)>Activo</option><option value="0" @selected(!$provider->is_active)>Inactivo</option></select></label><button class="wide" type="submit">Guardar cambios seguros</button></form></details>@empty<div class="card empty">No hay proveedores creados.</div>@endforelse</section></div>
@endsection
