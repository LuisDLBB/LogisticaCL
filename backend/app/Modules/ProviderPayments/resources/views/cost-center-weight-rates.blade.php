@extends('provider-payments::layout')
@section('title', 'Mantenedor: Tarifas CC')
@push('styles')
<style>
.rate-filter{display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin:18px 0}.rate-filter label,.rate-form label{display:grid;gap:6px;font-weight:750}.rate-filter select,.rate-form input,.rate-form select,.rate-table input,.rate-table select{padding:10px;border:1px solid var(--line);border-radius:8px;background:#fff}.rate-filter select{min-width:min(420px,80vw)}.rate-actions{display:flex;gap:10px;flex-wrap:wrap;margin:18px 0}.rate-actions details>summary{list-style:none;cursor:pointer}.rate-actions details>summary::-webkit-details-marker{display:none}.rate-actions details[open]{width:100%}.rate-actions .card{max-width:680px;margin-top:12px}.rate-form{display:grid;grid-template-columns:repeat(2,minmax(160px,1fr));gap:14px}.rate-form .wide{grid-column:1/-1}.rate-table{overflow:auto;max-height:65vh}.rate-table table{min-width:740px}.rate-table th{position:sticky;top:0}.rate-table form{display:flex;align-items:center;gap:8px;justify-content:flex-end}.rate-table input{width:120px}.rate-table button{white-space:nowrap}.rate-status{padding:14px;margin:12px 0;background:#e8fbfa;border-left:4px solid var(--turquoise-dark)}@media(max-width:640px){.rate-form{grid-template-columns:1fr}.rate-form .wide{grid-column:auto}.rate-filter select{min-width:0;width:100%}.rate-filter label{width:100%}}
.rate-values{display:grid;grid-template-columns:repeat(4,minmax(110px,1fr));gap:12px}.rate-values label{font-size:14px}.rate-values input{width:100%}@media(max-width:640px){.rate-values{grid-template-columns:repeat(2,minmax(110px,1fr))}}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a>
<p class="eyebrow">Mantenedor de tarifas</p>
<h1>Tarifas CC</h1>
<p class="intro">Consulta las tarifas por peso final de cada centro de costo. Los valores se expresan en pesos chilenos.</p>
@if(session('status'))<div class="rate-status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="warning">{{ $errors->first() }}</div>@endif

<form class="rate-filter" method="get">
    <label>Centro de costo
        <select name="center" onchange="this.form.submit()">
            <option value="">Todos los centros de costo</option>
            @foreach($centers as $center)
                <option value="{{ $center->cost_center_code }}" @selected((string) $center->cost_center_code === $selectedCenterCode)>{{ $center->cost_center_code }} · {{ $center->dispatch_guide_detail }}</option>
            @endforeach
        </select>
    </label>
    <button type="submit">Filtrar</button>
</form>

<div class="rate-actions">
    <details @if($errors->has('dispatch_guide_detail') || $errors->has('additional_kilo_value')) open @endif>
        <summary class="button">Crear nuevo centro de costo</summary>
        <section class="card">
            <form class="rate-form" method="post" action="{{ route('provider-payments.maintainers.centro-de-costos.store') }}">
                @csrf
                <input type="hidden" name="return_to" value="tarifas-cc">
                <p class="note wide">El próximo ID de centro de costo será {{ $nextCode }}.</p>
                <label class="wide">Detalle del centro de costo<input name="dispatch_guide_detail" value="{{ old('dispatch_guide_detail') }}" maxlength="255" required></label>
                <label>Valor kilo adicional ($)<input type="number" name="additional_kilo_value" value="{{ old('additional_kilo_value', 0) }}" min="0" step="1" required></label>
                <button class="wide" type="submit">Crear centro de costo</button>
            </form>
        </section>
    </details>
    <details @if(old('values') !== null || $errors->has('cost_center_code')) open @endif>
        <summary class="button">Agregar tarifas de 1 a 20 kg</summary>
        <section class="card">
            <form class="rate-form" method="post" action="{{ route('provider-payments.maintainers.tarifas-cc.store') }}">
                @csrf
                <label class="wide">Centro de costo<select name="cost_center_code" required onchange="window.location.href='{{ route('provider-payments.maintainers.tarifas-cc') }}?center='+encodeURIComponent(this.value)"><option value="">Selecciona</option>@foreach($centers->where('is_active', true) as $center)<option value="{{ $center->cost_center_code }}" @selected((string) old('cost_center_code', $selectedCenterCode) === (string) $center->cost_center_code)>{{ $center->cost_center_code }} · {{ $center->dispatch_guide_detail }}</option>@endforeach</select></label>
                <p class="note wide">Ingresa el monto en pesos chilenos para cada peso final. Las tarifas existentes aparecen precargadas y se actualizan al guardar.</p>
                <div class="rate-values wide">
                    @foreach(range(1, 20) as $weight)
                        <label>{{ $weight }} kg<input type="number" name="values[{{ $weight }}]" value="{{ old('values.'.$weight, $rateValues->get($weight)) }}" min="0" step="1" required></label>
                    @endforeach
                </div>
                <button class="wide" type="submit">Guardar tarifas de 1 a 20 kg</button>
            </form>
        </section>
    </details>
</div>

<h2>Tarifas registradas <span class="note">({{ number_format($rates->count(), 0, ',', '.') }})</span></h2>
<div class="card rate-table"><table><thead><tr><th>Centro de costo</th><th>Detalle</th><th>Peso final</th><th>Valor</th><th>Estado</th><th>Modificar</th></tr></thead><tbody>
@forelse($rates as $rate)
    <tr><td>{{ $rate->cost_center_code }}</td><td>{{ $rate->costCenter?->dispatch_guide_detail ?: 'Sin centro asociado' }}</td><td>{{ $rate->final_weight }} kg</td><td>${{ number_format($rate->value, 0, ',', '.') }}</td><td>{{ $rate->is_active ? 'Activo' : 'Inactivo' }}</td><td><form method="post" action="{{ route('provider-payments.maintainers.tarifas-cc.update', $rate) }}">@csrf @method('PUT')<label class="note">Valor ($)<input type="number" name="value" value="{{ $rate->value }}" min="0" step="1" required></label><label class="note">Estado<select name="is_active"><option value="1" @selected($rate->is_active)>Activo</option><option value="0" @selected(! $rate->is_active)>Inactivo</option></select></label><button type="submit">Guardar</button></form></td></tr>
@empty
    <tr><td colspan="6">No hay tarifas para el centro de costo seleccionado.</td></tr>
@endforelse
</tbody></table></div>
@endsection
