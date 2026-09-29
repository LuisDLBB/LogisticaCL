@extends('provider-payments::layout')
@section('title', 'Revisar Inconsistencias Llave CC')
@push('styles')
<style>
.review-heading{display:flex;justify-content:space-between;align-items:end;gap:16px;flex-wrap:wrap}.review-heading h1{margin-bottom:4px}.review-status{padding:14px;margin:16px 0;background:#e8fbfa;border-left:4px solid var(--turquoise-dark)}.review-tools{display:flex;align-items:end;gap:12px;flex-wrap:wrap;margin:18px 0}.review-tools label{display:grid;gap:5px;font-size:12px;font-weight:800;text-transform:uppercase}.review-tools select{min-width:280px;max-width:100%;padding:9px;border:1px solid var(--line);border-radius:8px;background:#fff}.review-tools p{margin:0}.review-sheet{overflow:auto;max-height:70vh;padding:10px}.review-sheet table{min-width:1300px;width:100%;border-collapse:collapse}.review-sheet th,.review-sheet td{padding:9px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}.review-sheet th{position:sticky;top:0;background:#eaf8f8;color:var(--turquoise-dark);font-size:12px;text-transform:uppercase}.review-sheet select{width:100%;min-width:115px;padding:8px;border:1px solid var(--line);border-radius:7px;background:#fff}.review-sheet .center-select{min-width:230px}.review-sheet .pending{color:var(--muted)}.review-actions{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:12px 0}.review-actions>div{display:flex;gap:8px;flex-wrap:wrap}.review-actions button:disabled{opacity:.55;cursor:not-allowed}.review-sheet tr.changed{background:#f2fbf5}.review-sheet small{color:var(--muted)}.review-sheet .new-key-label{display:block;color:var(--turquoise-dark);font-weight:700;font-size:12px;margin-bottom:5px}.review-sheet .create-key-label{display:flex;gap:6px;align-items:center;font-size:12px;margin-top:6px;white-space:nowrap}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.courier-movements.compile.work', ['period' => $period]) }}">← Trabajar Registros de Courier</a>
<p class="eyebrow">Gestión operacional</p>
<div class="review-heading"><div><h1>Revisar Inconsistencias Llave CC</h1><p class="intro">Período {{ $period }} · {{ number_format($groups->pluck('provider_tax_id')->unique()->count(), 0, ',', '.') }} proveedores · {{ number_format($groups->count(), 0, ',', '.') }} combinaciones por configurar.</p></div><a class="button" href="{{ route('provider-payments.maintainers.llave-centro-costos') }}" target="_blank" rel="noopener">Abrir Mantenedor Llave CC ↗</a></div>
@if(session('status'))<div class="review-status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="warning">{{ $errors->first() }}</div>@endif
<div class="review-tools">
    <form method="get"><input type="hidden" name="period" value="{{ $period }}"><label>Proveedor<select name="provider" onchange="this.form.submit()"><option value="">Todos los proveedores</option>@foreach($providerOptions as $taxId => $name)<option value="{{ $taxId }}" @selected($provider === $taxId)>{{ $name }} · {{ $taxId }}</option>@endforeach</select></label></form>
    @if($missingTotal > 0)
        <form method="post" action="{{ route('provider-payments.courier-movements.compile.keys.generate') }}">@csrf<input type="hidden" name="period" value="{{ $period }}"><button type="submit">Generar todas como pendientes ({{ number_format($missingTotal, 0, ',', '.') }})</button></form>
        <p class="note">Se crean con centro 0, pago NO e Inactiva.</p>
    @endif
</div>
<p class="note">Puedes configurar cada combinación nueva aquí: elige centro, condición de pago y estado. Al cambiarla se marca para guardar; «Guardar y calcular pagos» actualiza sus movimientos del período. Para pagar SI, usa un centro distinto de 0 y deja la llave Activa.</p>
<form id="review-key-form" method="post" action="{{ route('provider-payments.courier-movements.compile.keys.save') }}">
    @csrf<input type="hidden" name="period" value="{{ $period }}"><input type="hidden" name="provider" value="{{ $provider }}"><input type="hidden" name="page" value="{{ $rows->currentPage() }}">
    <div class="review-actions"><strong>Mostrando {{ number_format($rows->count(), 0, ',', '.') }} de {{ number_format($rows->total(), 0, ',', '.') }} combinaciones</strong><div><button class="save-review" type="submit" name="assign_payments" value="0" @disabled($rows->isEmpty())>Solo guardar</button><button class="save-review" type="submit" name="assign_payments" value="1" @disabled($rows->isEmpty())>Guardar y calcular pagos</button></div></div>
    <div class="card review-sheet"><table><thead><tr><th>Proveedor</th><th>RUT proveedor</th><th>Cliente</th><th>Servicio</th><th>Registros</th><th>Centro de costo</th><th>Condición de pago</th><th>Estado</th></tr></thead><tbody>
    @forelse($rows as $index => $group)
        <tr><td>{{ $group->provider_name }}</td><td>{{ $group->provider_tax_id }}</td><td>{{ $group->client_name }}</td><td>{{ $group->service_name }}</td><td>{{ number_format($group->movements, 0, ',', '.') }}</td>
        @if($group->key)
            <td><input type="hidden" name="rows[{{ $index }}][id]" value="{{ $group->key->id }}"><select class="center-select" name="rows[{{ $index }}][cost_center_code]" required>@foreach($centers as $center)<option value="{{ $center->cost_center_code }}" @selected((string) old("rows.$index.cost_center_code", $group->key->cost_center_code) === (string) $center->cost_center_code)>{{ $center->cost_center_code }} · {{ $center->dispatch_guide_detail }}</option>@endforeach</select></td>
            <td><select name="rows[{{ $index }}][payment_status]"><option value="NO" @selected(old("rows.$index.payment_status", $group->key->payment_status) === 'NO')>NO</option><option value="SI" @selected(old("rows.$index.payment_status", $group->key->payment_status) === 'SI')>SI</option><option value="REVISAR" @selected(old("rows.$index.payment_status", $group->key->payment_status) === 'REVISAR')>REVISAR</option></select></td>
            <td><select name="rows[{{ $index }}][is_active]"><option value="0" @selected((string) old("rows.$index.is_active", (int) $group->key->is_active) === '0')>Inactiva</option><option value="1" @selected((string) old("rows.$index.is_active", (int) $group->key->is_active) === '1')>Activa</option></select></td>
        @else
            <td><span class="new-key-label">Nueva combinación</span><input type="hidden" name="rows[{{ $index }}][provider_tax_id]" value="{{ $group->provider_tax_id }}"><input type="hidden" name="rows[{{ $index }}][client_tax_id]" value="{{ $group->client_tax_id }}"><input type="hidden" name="rows[{{ $index }}][service_code]" value="{{ $group->service_code }}"><select class="center-select" name="rows[{{ $index }}][cost_center_code]" required>@foreach($centers as $center)<option value="{{ $center->cost_center_code }}" @selected((string) old("rows.$index.cost_center_code", 0) === (string) $center->cost_center_code)>{{ $center->cost_center_code }} · {{ $center->dispatch_guide_detail }}</option>@endforeach</select></td>
            <td><select name="rows[{{ $index }}][payment_status]"><option value="NO" @selected(old("rows.$index.payment_status", 'NO') === 'NO')>NO</option><option value="SI" @selected(old("rows.$index.payment_status", 'NO') === 'SI')>SI</option><option value="REVISAR" @selected(old("rows.$index.payment_status", 'NO') === 'REVISAR')>REVISAR</option></select></td>
            <td><select name="rows[{{ $index }}][is_active]"><option value="0" @selected((string) old("rows.$index.is_active", 0) === '0')>Inactiva</option><option value="1" @selected((string) old("rows.$index.is_active", 0) === '1')>Activa</option></select><label class="create-key-label"><input class="create-key" type="checkbox" name="rows[{{ $index }}][create]" value="1" @checked(old("rows.$index.create"))> Guardar esta combinación</label></td>
        @endif
        </tr>
    @empty<tr><td colspan="8">No hay llaves pendientes para este período y proveedor.</td></tr>@endforelse
    </tbody></table></div>
    <div class="review-actions"><span class="note">Solo se crearán las combinaciones nuevas marcadas.</span><div><button class="save-review" type="submit" name="assign_payments" value="0" @disabled($rows->isEmpty())>Solo guardar</button><button class="save-review" type="submit" name="assign_payments" value="1" @disabled($rows->isEmpty())>Guardar y calcular pagos</button></div></div>
</form>
@include('provider-payments::partials.pagination', ['paginator' => $rows])
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('review-key-form');
    let changed = false;
    form.querySelectorAll('select').forEach(select => select.addEventListener('change', () => {
        changed = true;
        const row = select.closest('tr');
        row.classList.add('changed');
        const create = row.querySelector('.create-key');
        if (create) create.checked = true;
    }));
    form.querySelectorAll('.create-key').forEach(checkbox => checkbox.addEventListener('change', () => {
        changed = true;
        checkbox.closest('tr').classList.toggle('changed', checkbox.checked);
    }));
    form.addEventListener('submit', () => { changed = false; });
    window.addEventListener('beforeunload', event => {
        if (!changed) return;
        event.preventDefault();
        event.returnValue = '';
    });
});
</script>
@endsection
