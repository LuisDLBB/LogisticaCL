@extends('provider-payments::layout')
@section('title', 'Mantenedor: Bancos')
@push('styles')
@include('provider-payments::partials.master-styles')
<style>
.bank-dashboard{display:grid;grid-template-columns:repeat(4,minmax(170px,1fr));gap:14px;margin-bottom:22px}.bank-metric{padding:18px}.bank-metric strong{display:block;margin-top:6px;color:var(--turquoise-dark);font-size:30px}.account-types{margin-top:28px}.account-type-create{margin:12px 0 18px}.account-type-create>summary{display:inline-flex;padding:11px 18px;border-radius:8px;background:var(--turquoise-dark);color:#fff;font-weight:800;cursor:pointer}.account-type-create>.card{max-width:620px;margin-top:12px}@media(max-width:900px){.bank-dashboard{grid-template-columns:1fr 1fr}}@media(max-width:650px){.bank-dashboard{grid-template-columns:1fr}}
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
    <div class="card bank-metric"><span class="note">Tipos de cuenta</span><strong>{{ number_format($accountTypes->count(), 0, ',', '.') }}</strong></div>
    <div class="card bank-metric"><span class="note">Tipos activos</span><strong>{{ number_format($accountTypes->where('is_active', true)->count(), 0, ',', '.') }}</strong></div>
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
                <summary>{{ $bank->banco }} <span class="badge {{ $bank->is_active ? '' : 'off' }}">{{ $bank->is_active ? 'Activo' : 'Inactivo' }}</span><br><span class="record-meta">{{ $bank->nombre_entidad_financiera }} · {{ $bank->marcas_productos_asociados }}</span></summary>
                <form class="form-grid" method="post" action="{{ route('provider-payments.maintainers.bancos.update', $bank) }}">
                    @csrf @method('PUT')
                    <div class="protected wide"><strong>Código SBIF:</strong> {{ $bank->codigo_sbif }} <span class="note">(protegido)</span></div>
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

<section class="account-types">
    <h2>Tipos de cuenta bancaria <span class="note">({{ $accountTypes->count() }})</span></h2>
    <details class="account-type-create">
        <summary>Crear nuevo tipo de cuenta</summary>
        <div class="card">
            <form class="form-grid" method="post" action="{{ route('provider-payments.maintainers.bancos.tipos-cuenta.store') }}">
                @csrf
                <label class="wide">Tipo de cuenta<input name="tipo_cuenta" value="{{ old('tipo_cuenta') }}" placeholder="Ejemplo: Cuenta Corriente" required></label>
                <button class="wide">Crear tipo de cuenta</button>
            </form>
        </div>
    </details>
    @forelse($accountTypes as $accountType)
        <details class="record">
            <summary>{{ $accountType->tipo_cuenta }} <span class="badge {{ $accountType->is_active ? '' : 'off' }}">{{ $accountType->is_active ? 'Activo' : 'Inactivo' }}</span></summary>
            <form class="form-grid" method="post" action="{{ route('provider-payments.maintainers.bancos.tipos-cuenta.update', $accountType) }}">
                @csrf @method('PUT')
                <div class="protected wide"><strong>Identificador interno protegido.</strong></div>
                <label>Tipo de cuenta<input name="tipo_cuenta" value="{{ $accountType->tipo_cuenta }}" required></label>
                <label>Estado<select name="is_active"><option value="1" @selected($accountType->is_active)>Activo</option><option value="0" @selected(!$accountType->is_active)>Inactivo</option></select></label>
                <button class="wide">Guardar cambios</button>
            </form>
        </details>
    @empty
        <div class="card empty">No hay tipos de cuenta registrados.</div>
    @endforelse
</section>
@endsection
