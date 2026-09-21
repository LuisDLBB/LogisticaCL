@extends('provider-payments::layout')
@section('title', 'Mantenedor: Clientes')
@push('styles')
@include('provider-payments::partials.master-styles')
<style>.page-actions{display:flex;justify-content:flex-end;margin-bottom:18px}.client-dashboard{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:14px;margin-bottom:22px}.client-metric{padding:20px}.client-metric strong{display:block;margin-top:5px;color:var(--turquoise-dark);font-size:30px}.movement-share{display:grid;grid-template-columns:auto minmax(120px,240px) 62px;gap:10px;align-items:center;margin-top:8px;color:var(--muted);font-size:12px}.share-track{height:8px;overflow:hidden;border-radius:99px;background:#dcecec}.share-bar{display:block;height:100%;border-radius:99px;background:var(--turquoise-dark)}.client-head{padding:15px 125px 15px 15px}.client-edit{border-top:1px solid var(--line);padding:15px!important}.client-editor>summary{position:absolute;top:34px;right:15px;list-style:none}.client-editor>summary::-webkit-details-marker{display:none}.record{position:relative}@media(max-width:760px){.client-dashboard{grid-template-columns:1fr}.movement-share{grid-template-columns:1fr}.client-head{padding-right:15px}.client-editor>summary{position:static;margin:0 15px 15px}}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a>
<p class="eyebrow">Gestión operacional</p><h1>Mantenedor: Clientes</h1>
<p class="intro">Consulta los clientes existentes y su participación en los bultos cargados. El RUT permanece protegido porque participa en las llaves del sistema.</p>
<div class="page-actions"><a class="button" href="{{ route('provider-payments.maintainers.clientes', ['new' => 1]) }}">Crear nuevo cliente</a></div>
@if(session('status'))<div class="status">{{ session('status') }}</div>@endif
<section class="client-dashboard">
    <div class="card client-metric"><span class="note">Clientes creados</span><strong>{{ number_format($clients->count(), 0, ',', '.') }}</strong></div>
    <div class="card client-metric"><span class="note">Clientes activos</span><strong>{{ number_format($activeClients, 0, ',', '.') }}</strong></div>
    <div class="card client-metric"><span class="note">Bultos en movimientos_courier</span><strong>{{ number_format($totalMovements, 0, ',', '.') }}</strong></div>
</section>
<section><h2>Participación de bultos por cliente</h2>
@forelse($clients as $client)
    @php($share = $totalMovements > 0 ? ($client->courier_movements_count / $totalMovements) * 100 : 0)
    <article class="record"><div class="client-head"><strong>{{ $client->legal_name }} · {{ $client->tax_id }}</strong> <span class="badge {{ $client->is_active ? '' : 'off' }}">{{ $client->is_active ? 'Activo' : 'Inactivo' }}</span><br><span class="record-meta">{{ $client->source_merchant_name }} · {{ $client->billing_commune_name }}</span><span class="movement-share"><span>{{ number_format($client->courier_movements_count, 0, ',', '.') }} bultos</span><span class="share-track"><span class="share-bar" style="width:{{ min(100, $share) }}%"></span></span><strong>{{ number_format($share, 2, ',', '.') }}%</strong></span></div>
    <details class="client-editor"><summary class="button">Modificar</summary><form class="form-grid client-edit" method="post" action="{{ route('provider-payments.maintainers.clientes.update', $client) }}" onsubmit="return confirm('¿Estás seguro de realizar estos cambios en el cliente?')">@csrf @method('PUT')
        <div class="protected wide"><strong>RUT protegido:</strong> {{ $client->tax_id }}</div>
        <label>Comerciante (Pila)<input name="source_merchant_name" value="{{ $client->source_merchant_name }}" required></label><label>Razón social<input name="legal_name" value="{{ $client->legal_name }}" required></label><label>Nombre comercial<input name="commercial_name" value="{{ $client->commercial_name }}" required></label><label>Empresa facturadora<input name="billing_company_code" value="{{ $client->billing_company_code }}"></label><label class="wide">Dirección facturación<input name="billing_address" value="{{ $client->billing_address }}"></label><label>Comuna<input name="billing_commune_name" value="{{ $client->billing_commune_name }}"></label><label>Giro<input name="business_activity" value="{{ $client->business_activity }}"></label><label>Estado<select name="is_active"><option value="1" @selected($client->is_active)>Activo</option><option value="0" @selected(!$client->is_active)>Inactivo</option></select></label><button type="submit">Guardar modificación</button><button class="wide cancel-client-edit" type="button">Cancelar modificación</button>
    </form></details></article>
@empty<div class="card empty">No hay clientes creados.</div>@endforelse
</section>
@endsection
@push('scripts')
<script>
document.querySelectorAll('.client-editor').forEach(editor => {
    const form = editor.querySelector('form');
    editor.querySelector('.cancel-client-edit').addEventListener('click', () => { editor.open = false; form.reset(); });
});
</script>
@endpush
