@extends('provider-payments::layout')
@section('title', 'Mantenedor: Llave Centro de Costos')
@push('styles')
@include('provider-payments::partials.master-styles')
<style>
.view-tabs{display:flex;gap:10px;margin:20px 0}.view-tab{display:inline-flex;padding:11px 18px;border:1px solid var(--turquoise-dark);border-radius:8px;color:var(--turquoise-dark);font-weight:800;text-decoration:none}.view-tab.active{background:var(--turquoise-dark);color:#fff}.group{margin:10px 0;border:1px solid var(--line);border-radius:10px;background:#fff}.group>summary{padding:16px;font-size:17px;font-weight:800;cursor:pointer}.subgroup{margin:0 14px 14px;border:1px solid #d7e5e5;border-radius:8px;background:#fbfdfd}.subgroup>summary{padding:13px;font-weight:750;cursor:pointer}.key-table-wrap{overflow:auto;margin:0 12px 14px}.key-table{width:100%;min-width:880px;border-collapse:collapse}.key-table th,.key-table td{padding:10px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}.key-table th{background:#eaf8f8;color:var(--turquoise-dark);font-size:12px;text-transform:uppercase}.key-editor summary{color:var(--turquoise-dark);cursor:pointer;font-weight:700}.key-editor .form-grid{margin-top:10px;min-width:560px}.count{font-size:13px;color:#557078;font-weight:600}.empty-state{padding:22px}.new-key-panel{margin-bottom:22px}.new-key-panel>summary{display:inline-flex;padding:11px 18px;border-radius:8px;background:var(--turquoise-dark);color:#fff;font-weight:800;cursor:pointer}.new-key-panel>.card{margin-top:12px;max-width:760px}
.provider-choice{display:grid;gap:6px;font-weight:700}.provider-choice select{width:100%;padding:11px;border:1px solid var(--line);border-radius:8px;background:#fff}.copy-provider-panel{margin:14px 0 20px;padding:16px;border:1px solid var(--line);border-radius:9px;background:#f5fbfb}.copy-provider-panel h2{margin:0 0 8px}.copy-provider-panel p{margin:0 0 14px}.copy-provider-panel .form-grid{margin:0}.individual-key-heading{border-top:1px solid var(--line);padding-top:16px;margin:18px 0 12px}.individual-key-heading h2{margin:0 0 6px}.individual-key-heading p{margin:0}
.key-filters{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr)) auto auto;gap:10px;align-items:end;margin:16px 0;padding:16px}.key-filters label{display:grid;gap:6px;font-size:12px;font-weight:800;text-transform:uppercase}.key-filters select{width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;background:#fff}.key-filters .button{white-space:nowrap}.key-sheet{overflow:auto;max-height:70vh;padding:10px}.key-sheet table{min-width:1650px}.key-sheet th{position:sticky;top:0;z-index:1}.key-sheet td{vertical-align:middle}.key-sheet input,.key-sheet select{width:100%;min-width:110px;padding:8px;border:1px solid var(--line);border-radius:7px;background:#fff}.key-sheet .center-select{min-width:230px}.key-sheet .condition-input{min-width:100px}.key-sheet .agency-input{min-width:150px}.key-sheet .key-code{white-space:nowrap;font-size:12px;color:var(--muted)}@media(max-width:900px){.key-filters{grid-template-columns:1fr 1fr}.key-filters button,.key-filters .button{width:100%;text-align:center}}@media(max-width:550px){.key-filters{grid-template-columns:1fr}}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a>
<p class="eyebrow">Gestión operacional</p>
<h1>Mantenedor: Llave Centro de Costos</h1>
<p class="intro">Filtra las llaves por cliente y proveedor, y modifica sus datos directamente en la planilla.</p>
@if(session('status'))<div class="status">{{ session('status') }}</div>@endif

<form class="card key-filters" method="get" aria-label="Filtrar llaves">
    <input type="hidden" name="vista" value="{{ $viewMode }}">
    <label>Cliente<select name="client"><option value="">Todos los clientes</option>@foreach($clientOptions as $option)<option value="{{ $option['value'] }}" @selected($selectedClient === $option['value'])>{{ $option['label'] }}</option>@endforeach</select></label>
    <label>Proveedor<select name="provider"><option value="">Todos los proveedores</option>@foreach($providerOptions as $option)<option value="{{ $option['value'] }}" @selected($selectedProvider === $option['value'])>{{ $option['label'] }}</option>@endforeach</select></label>
    <button type="submit">Filtrar</button>
    <a class="button" href="{{ route('provider-payments.maintainers.llave-centro-costos', ['vista' => $viewMode]) }}">Limpiar</a>
</form>
<nav class="view-tabs" aria-label="Orden de la planilla">
    <a class="view-tab {{ $viewMode === 'cliente' ? 'active' : '' }}" href="{{ route('provider-payments.maintainers.llave-centro-costos', ['vista' => 'cliente', 'client' => $selectedClient, 'provider' => $selectedProvider]) }}">Ver por cliente</a>
    <a class="view-tab {{ $viewMode === 'proveedor' ? 'active' : '' }}" href="{{ route('provider-payments.maintainers.llave-centro-costos', ['vista' => 'proveedor', 'client' => $selectedClient, 'provider' => $selectedProvider]) }}">Ver por proveedor</a>
</nav>

<details class="new-key-panel" @if($errors->any()) open @endif>
    <summary>Crear nueva llave</summary>
    <section class="card">
        <label class="provider-choice">Proveedor nuevo<select id="new-key-provider" name="provider_id" form="manual-key-form" required><option value="">Selecciona</option>@foreach($providers as $provider)<option value="{{ $provider->id }}" @selected(old('provider_id', old('target_provider_id')) == $provider->id)>{{ $provider->legal_name }} · {{ $provider->tax_id }}</option>@endforeach</select></label>
        @if($errors->any())<p class="warning">{{ $errors->first() }}</p>@endif
        <div class="copy-provider-panel" id="copy-provider-panel" hidden>
            <h2>Replicar llaves de un proveedor existente</h2>
            <p class="note">Elige un proveedor con llaves creadas para copiar todas sus combinaciones al proveedor seleccionado. Las existentes no se duplicarán.</p>
            <form class="form-grid" method="post" action="{{ route('provider-payments.maintainers.llave-centro-costos.replicate-provider') }}" onsubmit="return confirm('¿Copiar todas las combinaciones del proveedor de origen al proveedor nuevo? Las existentes no se duplicarán.')">
                @csrf
                <input type="hidden" name="target_provider_id" id="copy-target-provider">
                <label class="wide">Proveedor de origen<select id="source-provider-select" name="source_provider_id" required><option value="">Selecciona un proveedor con llaves</option>@foreach($sourceProviders as $provider)<option value="{{ $provider->id }}" @selected(old('source_provider_id') == $provider->id)>{{ $provider->legal_name }} ({{ number_format($keys->filter(fn ($key) => $key->provider_id == $provider->id || $key->provider_tax_id === $provider->tax_id)->count(), 0, ',', '.') }} llaves)</option>@endforeach</select></label>
                <button class="wide" type="submit">Copiar todas las combinaciones</button>
            </form>
        </div>
        <div class="individual-key-heading"><h2>Crear llave individual</h2><p class="note">Configura una sola combinación de cliente, servicio y centro de costo para el proveedor seleccionado.</p></div>
        <form id="manual-key-form" class="form-grid" method="post" action="{{ route('provider-payments.maintainers.llave-centro-costos.manual-store') }}">
            @csrf
            <label class="wide">Cliente<select name="client_id" required><option value="">Selecciona</option>@foreach($clients as $client)<option value="{{ $client->id }}">{{ $client->source_merchant_name }} · {{ $client->tax_id }}</option>@endforeach</select></label>
            <label class="wide">Servicio<select name="service_type_id" required><option value="">Selecciona</option>@foreach($services as $service)<option value="{{ $service->id }}">{{ $service->name }}</option>@endforeach</select></label>
            <label>Agencia<input name="agent_name"></label><label>Condición de pago<input name="payment_status" value="REVISAR" required></label>
            <label class="wide">Centro de costo<select name="cost_center_code"><option value="">Sin centro</option>@foreach($costCenters as $center)<option value="{{ $center->cost_center_code }}">{{ $center->cost_center_code }} · {{ $center->dispatch_guide_detail }}</option>@endforeach</select></label>
            <button class="wide">Crear llave</button>
        </form>
    </section>
</details>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const provider = document.getElementById('new-key-provider');
    const panel = document.getElementById('copy-provider-panel');
    const target = document.getElementById('copy-target-provider');
    const updateTarget = () => {
        target.value = provider.value;
        panel.hidden = !provider.value;
    };
    provider.addEventListener('change', updateTarget);
    updateTarget();
});
</script>

<h2>Llaves creadas <span class="note">({{ number_format($total, 0, ',', '.') }} de {{ number_format($allTotal, 0, ',', '.') }})</span></h2>
<p class="note">Se muestran 100 registros por página. Desplaza la planilla para ver todas las columnas.</p>
<div class="card key-sheet"><table><thead><tr><th>Cliente</th><th>Proveedor</th><th>RUT proveedor</th><th>Servicio</th><th>Agencia</th><th>Centro de costo</th><th>Condición de pago</th><th>Estado</th><th>Llave</th><th>Guardar</th></tr></thead><tbody>
@forelse($rows as $key)
    @php($filterState = ! $key->is_active || in_array(mb_strtoupper(trim((string) $key->payment_status)), ['NO', 'NO PAGAR', 'INACTIVO'], true) ? 'inactive' : 'active')
    <tr data-master-record data-state="{{ $filterState }}">
        <td>{{ $key->client?->source_merchant_name ?: $key->merchant_name }}</td>
        <td>{{ $key->provider?->legal_name ?: ($key->agent_name ?: 'Proveedor sin nombre') }}</td>
        <td>{{ $key->provider?->tax_id ?: $key->provider_tax_id }}</td>
        <td>{{ $key->serviceType?->name ?: $key->service_name }}</td>
        <td><form id="key-form-{{ $key->id }}" method="post" action="{{ route('provider-payments.maintainers.llave-centro-costos.update', $key) }}">@csrf @method('PUT')</form><input class="agency-input" form="key-form-{{ $key->id }}" name="agent_name" value="{{ $key->agent_name }}" aria-label="Agencia"></td>
        <td><select class="center-select" form="key-form-{{ $key->id }}" name="cost_center_code" aria-label="Centro de costo"><option value="">Sin centro de costo</option>@foreach($costCenters as $center)<option value="{{ $center->cost_center_code }}" @selected((string) $center->cost_center_code === (string) $key->cost_center_code)>{{ $center->cost_center_code }} · {{ $center->dispatch_guide_detail }}</option>@endforeach</select></td>
        <td><input class="condition-input" form="key-form-{{ $key->id }}" name="payment_status" value="{{ $key->payment_status }}" aria-label="Condición de pago" required></td>
        <td><select form="key-form-{{ $key->id }}" name="is_active" aria-label="Estado"><option value="1" @selected($key->is_active)>Activa</option><option value="0" @selected(! $key->is_active)>Inactiva</option></select></td>
        <td class="key-code">{{ $key->provider_tax_id }} / {{ $key->client_tax_id }} / {{ $key->service_code }}</td>
        <td><button type="submit" form="key-form-{{ $key->id }}">Guardar</button></td>
    </tr>
@empty
    <tr><td colspan="10">No hay llaves para los filtros seleccionados.</td></tr>
@endforelse
</tbody></table></div>
{{ $rows->links() }}
@endsection
