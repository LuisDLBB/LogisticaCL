@extends('provider-payments::layout')
@section('title', 'Mantenedor: Bancos')
@push('styles')
@include('provider-payments::partials.master-styles')
<style>
.bank-dashboard{display:grid;grid-template-columns:repeat(2,minmax(180px,280px));gap:14px;margin-bottom:22px}.bank-metric{padding:18px}.bank-metric strong{display:block;margin-top:6px;color:var(--turquoise-dark);font-size:30px}@media(max-width:650px){.bank-dashboard{grid-template-columns:1fr}}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a>
<p class="eyebrow">Gestión operacional</p>
<h1>Mantenedor: Bancos</h1>
<p class="intro">Administra el catálogo oficial de bancos, códigos SBIF, entidades financieras y marcas asociadas.</p>
@if(session('status'))<div class="status">{{ session('status') }}</div>@endif
<section class="bank-dashboard">
    <div class="card bank-metric"><span class="note">Bancos registrados</span><strong>{{ number_format($banks->count(), 0, ',', '.') }}</strong></div>
    <div class="card bank-metric"><span class="note">Bancos activos</span><strong>{{ number_format($banks->where('is_active', true)->count(), 0, ',', '.') }}</strong></div>
</section>
<div class="master-grid">
    <section class="card">
        <h2>Nuevo banco</h2>
        <form class="form-grid" method="post" action="{{ route('provider-payments.maintainers.bancos.store') }}">
            @csrf
            <label>Banco<input name="banco" value="{{ old('banco') }}" required></label>
            <label>Código SBIF<input type="number" name="codigo_sbif" value="{{ old('codigo_sbif') }}" min="1" max="65535" required></label>
            <label class="wide">Nombre de la entidad financiera<input name="nombre_entidad_financiera" value="{{ old('nombre_entidad_financiera') }}" required></label>
            <label class="wide">Marcas / productos asociados<textarea name="marcas_productos_asociados" required>{{ old('marcas_productos_asociados') }}</textarea></label>
            @if($errors->any())<p class="warning wide">{{ $errors->first() }}</p>@endif
            <button class="wide">Crear banco</button>
        </form>
    </section>
    <section>
        <h2>Bancos creados <span class="note">({{ $banks->count() }})</span></h2>
        @forelse($banks as $bank)
            <details class="record">
                <summary>IDBanco {{ $bank->id_banco }} · {{ $bank->banco }} · SBIF {{ $bank->codigo_sbif }} <span class="badge {{ $bank->is_active ? '' : 'off' }}">{{ $bank->is_active ? 'Activo' : 'Inactivo' }}</span><br><span class="record-meta">{{ $bank->nombre_entidad_financiera }} · {{ $bank->marcas_productos_asociados }}</span></summary>
                <form class="form-grid" method="post" action="{{ route('provider-payments.maintainers.bancos.update', $bank) }}">
                    @csrf @method('PUT')
                    <div class="protected wide"><strong>Llaves protegidas:</strong> IDBanco {{ $bank->id_banco }} · Código SBIF {{ $bank->codigo_sbif }}</div>
                    <label>Banco<input name="banco" value="{{ $bank->banco }}" required></label>
                    <label>Estado<select name="is_active"><option value="1" @selected($bank->is_active)>Activo</option><option value="0" @selected(!$bank->is_active)>Inactivo</option></select></label>
                    <label class="wide">Nombre de la entidad financiera<input name="nombre_entidad_financiera" value="{{ $bank->nombre_entidad_financiera }}" required></label>
                    <label class="wide">Marcas / productos asociados<textarea name="marcas_productos_asociados" required>{{ $bank->marcas_productos_asociados }}</textarea></label>
                    <button class="wide">Guardar cambios</button>
                </form>
            </details>
        @empty
            <div class="card empty">No hay bancos registrados.</div>
        @endforelse
    </section>
</div>
@endsection
