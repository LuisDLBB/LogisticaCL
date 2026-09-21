@extends('provider-payments::layout')
@section('title', 'Mantenedor: Centro de Costos')
@push('styles')
<style>
    .new-panel{margin-bottom:20px}.new-panel>summary{display:inline-block;list-style:none}.new-panel[open]>summary{margin-bottom:14px}.form-grid{display:grid;gap:16px}.form-grid label{display:grid;gap:7px;font-weight:750}.form-grid input{width:100%;padding:12px;border:1px solid var(--line);border-radius:8px}.automatic{padding:14px;border-radius:9px;background:var(--turquoise-soft);color:#086b6d}.status{margin-bottom:18px;padding:14px;border-left:4px solid var(--turquoise-dark);background:#e8fbfa}.badge{display:inline-block;padding:4px 9px;border-radius:99px;background:#e1f7ed;color:#16724a;font-size:12px;font-weight:750}.metric{font-size:30px;font-weight:850;color:var(--turquoise-dark)}@media(max-width:980px){.master-grid{grid-template-columns:1fr}}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a>
<p class="eyebrow">Gestión operacional</p><h1>Mantenedor: Centro de Costos</h1><p class="intro">Consulta los centros de costos creados y agrega nuevos. El ID se asigna automáticamente según el último correlativo.</p>
@if(session('status'))<div class="status">{{ session('status') }}</div>@endif
<details class="new-panel" @if($errors->any()) open @endif><summary class="button">Crear nuevo centro de costo</summary>
    <section class="card"><form class="form-grid" method="post" action="{{ route('provider-payments.maintainers.centro-de-costos.store') }}">@csrf
        <div class="automatic">Próximo ID automático<br><strong class="metric">{{ $nextCode }}</strong></div>
        <label>Detalle del centro de costo<input name="dispatch_guide_detail" value="{{ old('dispatch_guide_detail') }}" maxlength="255" required placeholder="Ej.: Santiago Express"></label>
        <label>Valor kilo adicional<input type="number" name="additional_kilo_value" value="{{ old('additional_kilo_value', 0) }}" min="0" step="1" required></label>
        @if($errors->any())<p class="warning">{{ $errors->first() }}</p>@endif
        <button type="submit">Crear centro de costo</button>
    </form></section></details>
    <section class="card"><h2>Centros de costos creados <span class="note">({{ $costCenters->count() }})</span></h2><div class="table-wrap"><table><thead><tr><th>ID Centro</th><th>Detalle guía despacho</th><th>Valor kilo adicional</th><th>Estado</th></tr></thead><tbody>
        @forelse($costCenters as $costCenter)<tr><td>{{ $costCenter->cost_center_code }}</td><td>{{ $costCenter->dispatch_guide_detail }}</td><td>${{ number_format($costCenter->additional_kilo_value, 0, ',', '.') }}</td><td><span class="badge">{{ $costCenter->is_active ? 'Activo' : 'Inactivo' }}</span></td></tr>@empty<tr><td colspan="4">No hay centros de costos creados.</td></tr>@endforelse
    </tbody></table></div></section>
@endsection

