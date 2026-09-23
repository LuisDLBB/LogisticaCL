@extends('provider-payments::layout')
@section('title', 'Mantenedor: Llave Centro de Costos')
@push('styles')
@include('provider-payments::partials.master-styles')
<style>
.view-tabs{display:flex;gap:10px;margin:20px 0}.view-tab{display:inline-flex;padding:11px 18px;border:1px solid var(--turquoise-dark);border-radius:8px;color:var(--turquoise-dark);font-weight:800;text-decoration:none}.view-tab.active{background:var(--turquoise-dark);color:#fff}.group{margin:10px 0;border:1px solid var(--line);border-radius:10px;background:#fff}.group>summary{padding:16px;font-size:17px;font-weight:800;cursor:pointer}.subgroup{margin:0 14px 14px;border:1px solid #d7e5e5;border-radius:8px;background:#fbfdfd}.subgroup>summary{padding:13px;font-weight:750;cursor:pointer}.key-table-wrap{overflow:auto;margin:0 12px 14px}.key-table{width:100%;min-width:880px;border-collapse:collapse}.key-table th,.key-table td{padding:10px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}.key-table th{background:#eaf8f8;color:var(--turquoise-dark);font-size:12px;text-transform:uppercase}.key-editor summary{color:var(--turquoise-dark);cursor:pointer;font-weight:700}.key-editor .form-grid{margin-top:10px;min-width:560px}.count{font-size:13px;color:#557078;font-weight:600}.empty-state{padding:22px}.new-key-panel{margin-bottom:22px}.new-key-panel>summary{display:inline-flex;padding:11px 18px;border-radius:8px;background:var(--turquoise-dark);color:#fff;font-weight:800;cursor:pointer}.new-key-panel>.card{margin-top:12px;max-width:760px}
.copy-provider-panel{border-top:1px solid var(--line);margin-top:22px;padding-top:18px}.copy-provider-panel h2{margin:0 0 8px}.copy-provider-panel p{margin:0 0 14px}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a>
<p class="eyebrow">Gestión operacional</p>
<h1>Mantenedor: Llave Centro de Costos</h1>
<p class="intro">Consulta las llaves agrupadas por cliente o por proveedor. Abre cada nivel para revisar los servicios, participantes y parámetros configurados.</p>
@if(session('status'))<div class="status">{{ session('status') }}</div>@endif

<nav class="view-tabs" aria-label="Forma de visualizar las llaves">
    <a class="view-tab {{ $viewMode === 'cliente' ? 'active' : '' }}" href="{{ route('provider-payments.maintainers.llave-centro-costos', ['vista' => 'cliente']) }}">Ver por cliente</a>
    <a class="view-tab {{ $viewMode === 'proveedor' ? 'active' : '' }}" href="{{ route('provider-payments.maintainers.llave-centro-costos', ['vista' => 'proveedor']) }}">Ver por proveedor</a>
</nav>

<details class="new-key-panel" @if($errors->any()) open @endif>
    <summary>Crear nueva llave</summary>
    <section class="card">
        <form class="form-grid" method="post" action="{{ route('provider-payments.maintainers.llave-centro-costos.manual-store') }}">
            @csrf
            <label class="wide">Proveedor<select name="provider_id" required><option value="">Selecciona</option>@foreach($providers as $provider)<option value="{{ $provider->id }}">{{ $provider->legal_name }} · {{ $provider->tax_id }}</option>@endforeach</select></label>
            <label class="wide">Cliente<select name="client_id" required><option value="">Selecciona</option>@foreach($clients as $client)<option value="{{ $client->id }}">{{ $client->source_merchant_name }} · {{ $client->tax_id }}</option>@endforeach</select></label>
            <label class="wide">Servicio<select name="service_type_id" required><option value="">Selecciona</option>@foreach($services as $service)<option value="{{ $service->id }}">{{ $service->name }}</option>@endforeach</select></label>
            <label>Agencia<input name="agent_name"></label><label>Condición de pago<input name="payment_status" value="REVISAR" required></label>
            <label class="wide">Centro de costo<select name="cost_center_code"><option value="">Sin centro</option>@foreach($costCenters as $center)<option value="{{ $center->cost_center_code }}">{{ $center->cost_center_code }} · {{ $center->dispatch_guide_detail }}</option>@endforeach</select></label>
            @if($errors->any())<p class="warning wide">{{ $errors->first() }}</p>@endif
            <button class="wide">Crear llave</button>
        </form>
        <div class="copy-provider-panel">
            <h2>Copiar combinaciones de otro proveedor</h2>
            <p class="note">Replica sus combinaciones de clientes, servicios y centros de costo. Las llaves que ya tenga el nuevo proveedor se conservarán.</p>
            <form class="form-grid" method="post" action="{{ route('provider-payments.maintainers.llave-centro-costos.replicate-provider') }}" onsubmit="return confirm('¿Copiar todas las combinaciones del proveedor de origen al proveedor nuevo? Las existentes no se duplicarán.')">
                @csrf
                <label class="wide">Proveedor de origen<select name="source_provider_id" required><option value="">Selecciona un proveedor con llaves</option>@foreach($sourceProviders as $provider)<option value="{{ $provider->id }}" @selected(old('source_provider_id') == $provider->id)>{{ $provider->legal_name }} · {{ $provider->tax_id }} · {{ $keys->filter(fn ($key) => $key->provider_id == $provider->id || $key->provider_tax_id === $provider->tax_id)->count() }} llaves</option>@endforeach</select></label>
                <label class="wide">Proveedor nuevo<select name="target_provider_id" required><option value="">Selecciona el proveedor que recibirá las llaves</option>@foreach($providers as $provider)<option value="{{ $provider->id }}" @selected(old('target_provider_id') == $provider->id)>{{ $provider->legal_name }} · {{ $provider->tax_id }}</option>@endforeach</select></label>
                <button class="wide" type="submit">Copiar todas las combinaciones</button>
            </form>
        </div>
    </section>
</details>

<h2>Llaves creadas <span class="note">({{ number_format($total, 0, ',', '.') }})</span></h2>

@if($viewMode === 'cliente')
    @forelse($keysByClient as $clientKeys)
        @php($client = $clientKeys->first()->client)
        <details class="group">
            <summary>{{ $client?->source_merchant_name ?: $clientKeys->first()->merchant_name }} <span class="count">{{ $clientKeys->groupBy('service_code')->count() }} servicios · {{ $clientKeys->count() }} llaves</span></summary>
            @foreach($clientKeys->groupBy('service_code') as $serviceKeys)
                <details class="subgroup">
                    <summary>{{ $serviceKeys->first()->serviceType?->name ?: $serviceKeys->first()->service_name }} <span class="count">{{ $serviceKeys->groupBy(fn($key) => $key->provider_id ?: $key->provider_tax_id)->count() }} proveedores · {{ $serviceKeys->count() }} llaves</span></summary>
                    @include('provider-payments::partials.cost-center-key-rows', ['rows' => $serviceKeys, 'firstColumn' => 'Proveedor'])
                </details>
            @endforeach
        </details>
    @empty
        <div class="card empty-state">No hay llaves creadas.</div>
    @endforelse
@else
    @forelse($keysByProvider as $providerKeys)
        @php($provider = $providerKeys->first()->provider)
        <details class="group">
            <summary>{{ $provider?->legal_name ?: ($providerKeys->first()->agent_name ?: 'Proveedor sin nombre') }} · {{ $provider?->tax_id ?: $providerKeys->first()->provider_tax_id }} <span class="count">{{ $providerKeys->groupBy(fn($key) => $key->client_id ?: $key->merchant_name)->count() }} clientes · {{ $providerKeys->count() }} llaves</span></summary>
            @foreach($providerKeys->groupBy(fn($key) => $key->client_id ?: 'merchant:'.$key->merchant_name) as $clientKeys)
                <details class="subgroup">
                    <summary>{{ $clientKeys->first()->client?->source_merchant_name ?: $clientKeys->first()->merchant_name }} <span class="count">{{ $clientKeys->groupBy('service_code')->count() }} servicios · {{ $clientKeys->count() }} llaves</span></summary>
                    @include('provider-payments::partials.cost-center-key-rows', ['rows' => $clientKeys, 'firstColumn' => 'Servicio'])
                </details>
            @endforeach
        </details>
    @empty
        <div class="card empty-state">No hay llaves creadas.</div>
    @endforelse
@endif
@endsection
