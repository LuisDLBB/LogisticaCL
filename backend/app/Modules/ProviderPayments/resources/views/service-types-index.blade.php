@extends('provider-payments::layout')
@section('title', 'Mantenedor: Servicios')
@push('styles')
<style>
    .new-panel{margin-bottom:20px}.new-panel>summary{display:inline-block;list-style:none}.new-panel[open]>summary{margin-bottom:14px}.form-grid{display:grid;gap:16px}.form-grid label{display:grid;gap:7px;font-weight:750}.form-grid input{width:100%;padding:12px;border:1px solid var(--line);border-radius:8px}.automatic{padding:14px;border-radius:9px;background:var(--turquoise-soft);color:#086b6d}.status{margin-bottom:18px;padding:14px;border-left:4px solid var(--turquoise-dark);background:#e8fbfa}.badge{display:inline-block;padding:4px 9px;border-radius:99px;background:#e1f7ed;color:#16724a;font-size:12px;font-weight:750}.metric{font-size:30px;font-weight:850;color:var(--turquoise-dark)}@media(max-width:980px){.master-grid{grid-template-columns:1fr}}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a>
<p class="eyebrow">Gestión operacional</p><h1>Mantenedor: Servicios</h1><p class="intro">Consulta los servicios creados y agrega nuevos. El ID se asigna automáticamente según el último correlativo.</p>
@if(session('status'))<div class="status">{{ session('status') }}</div>@endif
<details class="new-panel" @if($errors->any()) open @endif><summary class="button">Crear nuevo servicio</summary>
    <section class="card"><form class="form-grid" method="post" action="{{ route('provider-payments.maintainers.servicios.store') }}">@csrf
        <div class="automatic">Próximo ID automático<br><strong class="metric">{{ $nextCode }}</strong></div>
        <label>Nombre del servicio<input name="name" value="{{ old('name') }}" maxlength="160" required placeholder="Ej.: Servicio Express"></label>
        @error('name')<p class="warning">{{ $message }}</p>@enderror
        <button type="submit">Crear servicio</button>
    </form></section></details>
    <section class="card"><h2>Servicios creados <span class="note">({{ $services->count() }})</span></h2><div class="table-wrap"><table><thead><tr><th>ID Servicio</th><th>Servicio</th><th>Estado</th></tr></thead><tbody>
        @forelse($services as $service)<tr><td>{{ $service->service_code }}</td><td>{{ $service->name }}</td><td><span class="badge">{{ $service->is_active ? 'Activo' : 'Inactivo' }}</span></td></tr>@empty<tr><td colspan="3">No hay servicios creados.</td></tr>@endforelse
    </tbody></table></div></section>
@endsection

