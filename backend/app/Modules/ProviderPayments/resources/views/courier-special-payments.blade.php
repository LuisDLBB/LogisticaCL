@extends('provider-payments::layout')
@section('title', 'Courier Especiales')
@push('styles')
<style>
.special-summary{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}.special-summary .card{min-width:210px;padding:15px 18px}.special-summary strong{display:block;font-size:1.25rem}.special-tools{display:flex;align-items:end;gap:12px;flex-wrap:wrap;margin:18px 0}.special-tools label{display:block;font-weight:700;margin-bottom:5px}.special-tools select,.special-tools input{min-height:38px}.special-tools input[type=search]{min-width:250px}.special-progress{display:flex;flex-direction:column;gap:5px;min-width:260px}.special-progress[hidden]{display:none}.special-progress progress{width:min(360px,70vw);height:12px;accent-color:#007980}.special-table{overflow:auto}.special-table table{width:100%;border-collapse:collapse;min-width:1220px}.special-table th,.special-table td{border-bottom:1px solid #d4e3e8;padding:9px;text-align:left;vertical-align:top}.special-table th{background:#eefbfc;white-space:nowrap}.special-table td.amount{text-align:right;white-space:nowrap}.special-table tr.special-row-review>td{background:#fff0f1}.special-table tr.special-row-review>td:first-child{border-left:4px solid #ba3232}.special-table tr.special-row-partial>td{background:#fff9e6}.special-table tr.special-row-partial>td:first-child{border-left:4px solid #d29b12}.special-match-warning{display:block;color:#a72828;font-size:.8rem;font-weight:700}.special-alert{padding:12px 16px;margin:12px 0;background:#e8fbfb;border-left:4px solid #007980}.special-alert.error{background:#fff0f1;border-color:#b52b2b}.special-editor{min-width:700px}.special-editor summary{cursor:pointer;color:#007980;font-weight:700}.special-editor-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:14px 0}.special-editor-grid label{display:flex;flex-direction:column;gap:5px;font-weight:700}.special-editor-grid input,.special-editor-grid textarea{width:100%;min-height:38px}.special-editor-grid .wide{grid-column:span 2}.special-source{color:#5b6f76;font-size:.85rem;margin:0 0 12px}.special-match-note{display:block;color:#59717a;font-size:.8rem;font-weight:400}.special-confirmed{display:block;color:#007980;font-size:.8rem;font-weight:700}.special-inline-pair{display:flex;align-items:flex-start;gap:10px}.special-original{min-width:105px;max-width:145px;overflow-wrap:anywhere}.special-choice{display:block;min-width:205px}.special-choice select{width:100%;min-height:36px;font-size:.86rem}.special-choice select.special-match-review{background:#fff0f1;border:2px solid #ba3232;border-radius:6px}.special-row-partial .special-choice select.special-match-review{background:#fff9e6;border-color:#d29b12}.special-actions{display:flex;flex-direction:column;gap:6px;align-items:flex-start}.special-actions button{white-space:nowrap}.special-actions .delete-button{background:#a82c2c}.special-actions .delete-button:hover{background:#852020}
.special-inline-pair{flex-direction:column;align-items:stretch;gap:4px}
.special-inline-pair .special-original{display:block;min-width:0;max-width:none;font-size:.82rem;line-height:1.25;font-weight:700}
.special-inline-pair .special-choice{width:100%;min-width:205px;margin:0}
.special-inline-pair .special-match-note{font-size:.73rem;line-height:1.2;margin-bottom:2px}.special-closed{background:#e2f6e9;border-left:4px solid #288753;padding:12px 16px;margin:12px 0;border-radius:6px}.special-closed-form{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-top:10px}.special-closed-form label{display:flex;flex-direction:column;gap:4px;font-weight:700}.special-closed-form input{min-height:38px}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a>
<p class="eyebrow">Carga Movimientos Courier</p><h1>Courier Especiales</h1>
<p class="intro">Pagos especiales cargados desde Excel. El período de pago se elige al cargar la base y puede diferir de la fecha de cada registro.</p>
@if (session('status'))<div class="special-alert" role="status">{{ session('status') }}</div>@endif
@if (session('paid_report_token'))<div class="special-alert error" role="status">Esos seguimientos ya están en Maestro_Pagos y no pueden pagarse otra vez. <a href="{{ route('provider-payments.courier-movements.paid-report.download', session('paid_report_token')) }}">Exportar registros ya pagados en Excel</a></div>@endif
@if ($errors->any())<div class="special-alert error" role="alert">{{ $errors->first() }}</div>@endif
<div class="special-summary">
    @foreach ($periods as $period)
        <div class="card"><span>{{ $period->periodo }} · {{ (int) $period->total > 0 && (int) $period->total === (int) $period->finalized_total ? 'Período cerrado' : 'En revisión' }}</span><strong>{{ number_format($period->total, 0, ',', '.') }} registros</strong><span>$ {{ number_format($period->monto_total, 0, ',', '.') }}</span></div>
    @endforeach
    @if ($periods->isEmpty())<div class="card">Aún no hay pagos especiales cargados.</div>@endif
</div>
<div class="card">
    <form id="special-import-form" method="post" action="{{ route('provider-payments.courier-movements.especiales.store') }}" enctype="multipart/form-data">
        @csrf
        <label for="special-file"><strong>Cargar base de pagos especiales (.xlsx)</strong></label>
        <div class="special-tools"><div><label for="period-month">Período de pago (AAAAMM-Especiales)</label><input id="period-month" name="period_month" type="month" value="{{ old('period_month', $monthClosed ? \Carbon\CarbonImmutable::create((int) substr($selectedPeriod, 0, 4), (int) substr($selectedPeriod, 4, 2), 1)->addMonth()->format('Y-m') : ($selectedPeriod !== '' ? substr($selectedPeriod, 0, 4).'-'.substr($selectedPeriod, 4, 2) : now()->format('Y-m'))) }}" required></div><div><label for="special-file">Archivo Excel</label><input id="special-file" name="file" type="file" accept=".xlsx" required></div><button type="submit">Cargar pagos especiales</button></div>
        <p id="special-import-locked" class="note" @unless($isClosed) hidden @endunless>El período elegido está cerrado. Selecciona otro mes para cargar una base nueva.</p>
        <p class="note">Se conserva la fecha original de cada fila. Volver a cargar el mismo archivo no duplica registros y corrige su período si seleccionas otro.</p>
    </form>
</div>
<form class="special-tools" method="get" action="{{ route('provider-payments.courier-movements.especiales') }}">
    <div><label for="periodo">Período</label><select id="periodo" name="periodo">@foreach ($periods as $period)<option value="{{ $period->periodo }}" @selected($selectedPeriod === $period->periodo)>{{ $period->periodo }}</option>@endforeach</select></div>
    <div><label for="special-search">Buscar agente, ID, localidad o cliente</label><input id="special-search" name="q" type="search" maxlength="100" value="{{ $search }}" placeholder="Nombre o código"></div>
    <button type="submit">Filtrar</button>
    <a class="button secondary" href="{{ route('provider-payments.courier-movements.especiales', ['periodo' => $selectedPeriod]) }}">Limpiar</a>
</form>
@if ($isClosed)
    <div class="special-closed" role="status">
        <strong>Período cerrado · {{ $selectedPeriod }}</strong>
        <span>{{ $monthClosed ? 'El mes tiene cierre definitivo en Maestro_Pagos.' : 'Los '.number_format($periodTotal, 0, ',', '.').' pagos están finalizados.' }} La carga y edición de este período están bloqueadas.</span>
        @unless($monthClosed)
        <form class="special-closed-form" method="post" action="{{ route('provider-payments.courier-movements.especiales.reopen') }}" onsubmit="return confirm('¿Reabrir este período? Se retirarán los pagos Especiales y se restaurarán los pagos originales asociados.');">
            @csrf<input type="hidden" name="periodo" value="{{ $selectedPeriod }}">
            <label>Clave maestra<input type="password" name="password" required autocomplete="off"></label>
            <button type="submit">Reabrir período</button>
        </form>
        @endunless
    </div>
@endif
@unless ($isClosed)
<form id="special-finalize-form" class="special-tools" method="post" action="{{ route('provider-payments.courier-movements.especiales.finalize') }}">
    @csrf
    <input type="hidden" name="periodo" value="{{ $selectedPeriod }}">
    <button type="submit" @disabled($periodTotal === 0 || $missingAssociations > 0)>Grabar datos · Finalizar proceso</button>
    <div id="special-finalize-progress" class="special-progress" role="status" aria-live="polite" hidden>
        <span>Finalizando pagos especiales. Espera el resultado…</span>
        <progress max="100" aria-label="Finalizando pagos especiales"></progress>
    </div>
    <span class="note">{{ $finalizedCount }} de {{ $periodTotal }} finalizados.@if ($missingAssociations > 0) Completa proveedor, cliente y servicio en {{ $missingAssociations }} registros para continuar.@endif</span>
</form>
@endunless
<p class="note">{{ number_format($payments->total(), 0, ',', '.') }} registros encontrados. Rojo pastel: faltan cliente y proveedor. Amarillo pastel: solo uno está asociado. El texto original aparece encima de cada lista; los códigos de seguimiento cruzan cliente y servicio automáticamente.</p>
<div class="card special-table"><table><thead><tr><th>Fecha</th><th>Agente original / Proveedor</th><th>Cliente original / Cliente</th><th>Servicio</th><th>Seguimiento</th><th>Monto ($)</th><th>Acción</th></tr></thead><tbody>
@forelse ($payments as $payment)
    @php
        $providerMatch = $associations[$payment->id]['provider'];
        $clientMatch = $associations[$payment->id]['client'];
        $selectedProviderId = $payment->provider_id ?? $providerMatch['id'];
        $selectedClientId = $payment->client_id ?? $clientMatch['id'];
        $providerNeedsReview = ! $payment->provider_id;
        $clientNeedsReview = ! $payment->client_id;
        $serviceNeedsReview = ! $payment->service_type_id;
        $needsReview = $providerNeedsReview || $clientNeedsReview || $serviceNeedsReview;
        $hasNoMatches = $providerNeedsReview && $clientNeedsReview;
        $hasOneMatch = $providerNeedsReview !== $clientNeedsReview;
    @endphp
    <tr data-association-row data-payment-id="{{ $payment->id }}" @class(['special-row-review' => $hasNoMatches, 'special-row-partial' => $hasOneMatch])>
        <td>{{ $payment->fecha->format('d-m-Y') }}</td>
        <td><div class="special-inline-pair"><span class="special-original">{{ $payment->agente }}</span><label class="special-choice"><span class="special-match-note">Nombre de pila del proveedor</span>
            <select name="provider_id" form="asociar-{{ $payment->id }}" data-saved-value="{{ $payment->provider_id ?? '' }}" aria-label="Proveedor para {{ $payment->agente }}" @disabled($isClosed || $payment->finalized_at) @class(['special-match-review' => $providerNeedsReview])>
                <option value="">Sin asociar</option>@foreach ($providers as $provider)<option value="{{ $provider->id }}" @selected((string) (old('association_id') == $payment->id ? old('provider_id') : $selectedProviderId) === (string) $provider->id)>{{ $provider->operational_name ?: $provider->legal_name }} · {{ $provider->tax_id }}</option>@endforeach
            </select>
            @if ($payment->provider_id)<span class="special-confirmed">Confirmado</span>@elseif ($providerMatch['id'])<span class="special-match-warning">Pendiente de guardar · Cobertura {{ $providerMatch['matched'] }} · {{ $providerMatch['score'] }}% coincidencia</span>@else<span class="special-match-warning">Proveedor sin coincidencia</span>@endif
        </label></div></td>
        <td><div class="special-inline-pair"><span class="special-original">{{ $payment->cliente ?? '—' }}</span><label class="special-choice"><span class="special-match-note">Nombre de pila del cliente</span>
            <select name="client_id" form="asociar-{{ $payment->id }}" data-saved-value="{{ $payment->client_id ?? '' }}" aria-label="Cliente para {{ $payment->cliente ?? 'registro sin cliente' }}" @disabled($isClosed || $payment->finalized_at) @class(['special-match-review' => $clientNeedsReview])>
                <option value="">Sin asociar</option>@foreach ($clients as $client)<option value="{{ $client->id }}" @selected((string) (old('association_id') == $payment->id ? old('client_id') : $selectedClientId) === (string) $client->id)>{{ $client->commercial_name }} · {{ $client->tax_id }}</option>@endforeach
            </select>
            @if ($payment->client_id)<span class="special-confirmed">Confirmado</span>@elseif ($clientMatch['id'])<span class="special-match-warning">Pendiente de guardar · {{ $clientMatch['score'] }}% coincidencia</span>@else<span class="special-match-warning">Cliente sin coincidencia</span>@endif
        </label></div></td>
        <td><label class="special-choice"><span class="special-match-note">Servicio del seguimiento</span><select name="service_type_id" form="asociar-{{ $payment->id }}" data-saved-value="{{ $payment->service_type_id ?? '' }}" aria-label="Servicio para registro {{ $payment->id }}" @disabled($isClosed || $payment->finalized_at) @class(['special-match-review' => $serviceNeedsReview])>
            <option value="">Sin servicio</option>@foreach ($serviceTypes as $serviceType)<option value="{{ $serviceType->id }}" @selected((string) (old('association_id') == $payment->id ? old('service_type_id') : $payment->service_type_id) === (string) $serviceType->id)>{{ $serviceType->name }}</option>@endforeach
        </select>
        @if ($serviceNeedsReview)
            <span class="special-match-warning">
                @if (! $payment->codigo_seguimiento || strtoupper(trim($payment->codigo_seguimiento)) === 'N/A')
                    Sin código de seguimiento
                @elseif (! $foundTrackingCodes->has($payment->codigo_seguimiento))
                    Seguimiento no encontrado en movimientos cargados
                @else
                    Servicio pendiente de revisar
                @endif
            </span>
        @endif
        </label></td>
        <td>{{ $payment->codigo_seguimiento ?? '—' }}@if ($payment->finalized_tracking_number && $payment->finalized_tracking_number !== $payment->codigo_seguimiento)<span class="special-confirmed">{{ $payment->finalized_tracking_number }}</span>@endif</td><td class="amount">$ {{ number_format($payment->monto, 0, ',', '.') }}</td>
        <td><div class="special-actions">@unless ($isClosed || $payment->finalized_at)<form id="asociar-{{ $payment->id }}" method="post" action="{{ route('provider-payments.courier-movements.especiales.associate', $payment->id) }}">
            @csrf @method('PUT')<input type="hidden" name="association_id" value="{{ $payment->id }}"><input type="hidden" name="return_q" value="{{ $search }}"><input type="hidden" name="return_page" value="{{ $payments->currentPage() }}"><button type="submit">Guardar</button>
        </form><a class="special-edit-link" href="#editar-{{ $payment->id }}">Otros datos</a>@endunless
        @if ($payment->finalized_at)
            <span class="special-confirmed">Finalizado</span>
            @unless ($monthClosed)
                <details><summary>Corregir proveedor</summary>
                    <form method="post" action="{{ route('provider-payments.courier-movements.especiales.correct-provider', $payment->id) }}">
                        @csrf
                        <input type="hidden" name="return_q" value="{{ $search }}">
                        <input type="hidden" name="return_page" value="{{ $payments->currentPage() }}">
                        <label>Proveedor
                            <select name="provider_id" required>
                                @foreach ($providers as $provider)
                                    <option value="{{ $provider->id }}" @selected($provider->id === $payment->provider_id)>{{ $provider->operational_name ?: $provider->legal_name }} · {{ $provider->tax_id }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Clave maestra<input type="password" name="password" required autocomplete="off"></label>
                        <button type="submit">Actualizar este pago</button>
                    </form>
                </details>
            @endunless
        @elseif (! $isClosed)
            <form method="post" action="{{ route('provider-payments.courier-movements.especiales.destroy', $payment->id) }}" onsubmit="return confirm('¿Eliminar este pago especial?')">
                @csrf @method('DELETE')<button class="delete-button" type="submit">Eliminar fila</button>
            </form>
        @endif
        @if ($needsReview)<span class="special-match-warning">Revisar coincidencia</span>@endif</div></td>
    </tr>
    @unless ($isClosed || $payment->finalized_at)<tr id="editar-{{ $payment->id }}"><td colspan="7"><details class="special-editor" @if (old('edit_id') == $payment->id) open @endif><summary>Corregir registro #{{ $payment->id }}</summary>
        <form method="post" action="{{ route('provider-payments.courier-movements.especiales.update', $payment->id) }}">
            @csrf @method('PUT')
            <input type="hidden" name="edit_id" value="{{ $payment->id }}"><input type="hidden" name="return_q" value="{{ $search }}"><input type="hidden" name="return_page" value="{{ $payments->currentPage() }}">
            <div class="special-editor-grid">
                <label>Fecha<input type="date" name="fecha" value="{{ old('edit_id') == $payment->id ? old('fecha') : $payment->fecha->format('Y-m-d') }}" required></label>
                <label>Usuario ingresa<input name="usuario_ingresa" value="{{ old('edit_id') == $payment->id ? old('usuario_ingresa') : $payment->usuario_ingresa }}" maxlength="160" required></label>
                <label>Autoriza<input name="autoriza" value="{{ old('edit_id') == $payment->id ? old('autoriza') : $payment->autoriza }}" maxlength="160" required></label>
                <label>Agente<input name="agente" value="{{ old('edit_id') == $payment->id ? old('agente') : $payment->agente }}" maxlength="255" required></label>
                <label>Zona / Tipo<input name="zona_tipo" value="{{ old('edit_id') == $payment->id ? old('zona_tipo') : $payment->zona_tipo }}" maxlength="40" required></label>
                <label>ID / Seguimiento<input name="codigo_seguimiento" value="{{ old('edit_id') == $payment->id ? old('codigo_seguimiento') : $payment->codigo_seguimiento }}" maxlength="100"></label>
                <label>Localidad<input name="localidad" value="{{ old('edit_id') == $payment->id ? old('localidad') : $payment->localidad }}" maxlength="160" required></label>
                <label>Cliente<input name="cliente" value="{{ old('edit_id') == $payment->id ? old('cliente') : $payment->cliente }}" maxlength="255"></label>
                <label>Monto ($)<input type="number" name="monto" value="{{ old('edit_id') == $payment->id ? old('monto') : $payment->monto }}" min="0" step="1" required></label>
                <label class="wide">Descripción<textarea name="descripcion" rows="2">{{ old('edit_id') == $payment->id ? old('descripcion') : $payment->descripcion }}</textarea></label>
            </div>
            <p class="special-source">Período {{ $payment->periodo }} · Archivo {{ $payment->archivo_origen }} · Fila {{ $payment->fila_origen }}</p>
            <button type="submit">Guardar corrección</button>
        </form>
    </details></td></tr>@endunless
@empty
    <tr><td colspan="7">No hay pagos especiales para mostrar.</td></tr>
@endforelse
</tbody></table></div>
@unless ($isClosed)<form id="special-save-all" class="special-tools" method="post" action="{{ route('provider-payments.courier-movements.especiales.associate-page') }}">
    @csrf @method('PUT')
    <input type="hidden" name="periodo" value="{{ $selectedPeriod }}">
    <input type="hidden" name="return_q" value="{{ $search }}">
    <input type="hidden" name="return_page" value="{{ $payments->currentPage() }}">
    <button type="submit" disabled>Guardar todos los cambios en pantalla</button>
    <span id="special-pending-count" class="note" aria-live="polite">Sin cambios pendientes.</span>
    <span class="note">Incluye las propuestas preseleccionadas aún sin guardar.</span>
</form>@endunless
@include('provider-payments::partials.pagination', ['paginator' => $payments])
<script>
const closedSpecialPeriods = @json($closedPeriods);
const specialImportForm = document.getElementById('special-import-form');
const specialImportMonth = document.getElementById('period-month');
const refreshSpecialImport = () => {
    const locked = closedSpecialPeriods.includes(specialImportMonth.value.replace('-', '') + '-Especiales');
    specialImportForm.querySelector('input[type="file"]').disabled = locked;
    specialImportForm.querySelector('button[type="submit"]').disabled = locked;
    document.getElementById('special-import-locked').hidden = !locked;
};
specialImportMonth.addEventListener('change', refreshSpecialImport);
refreshSpecialImport();
</script>
@unless ($isClosed)
<script>
const finalizeForm = document.getElementById('special-finalize-form');
finalizeForm.addEventListener('submit', (event) => {
    event.preventDefault();
    if (finalizeForm.dataset.submitting === 'true') return;
    finalizeForm.dataset.submitting = 'true';
    finalizeForm.setAttribute('aria-busy', 'true');
    document.getElementById('special-finalize-progress').hidden = false;
    const button = finalizeForm.querySelector('button[type="submit"]');
    button.disabled = true;
    button.textContent = 'Finalizando pagos…';
    window.setTimeout(() => finalizeForm.submit(), 80);
});
document.querySelectorAll('.special-edit-link').forEach((link) => {
    link.addEventListener('click', () => {
        const editor = document.querySelector(link.getAttribute('href') + ' details');
        if (editor) editor.open = true;
    });
});
const bulkForm = document.getElementById('special-save-all');
const bulkButton = bulkForm.querySelector('button[type="submit"]');
const pendingCount = document.getElementById('special-pending-count');
const associationRows = Array.from(document.querySelectorAll('[data-association-row]'));
const changedRows = () => associationRows.filter((row) =>
    Array.from(row.querySelectorAll('select[data-saved-value]')).some((select) => select.value !== select.dataset.savedValue)
);
function refreshPendingCount() {
    const count = changedRows().length;
    bulkButton.disabled = count === 0;
    pendingCount.textContent = count === 0 ? 'Sin cambios pendientes.' : `${count} filas pendientes en esta página.`;
}
associationRows.forEach((row) => row.querySelectorAll('select[data-saved-value]').forEach((select) => {
    select.addEventListener('change', refreshPendingCount);
}));
bulkForm.addEventListener('submit', (event) => {
    const rows = changedRows();
    if (rows.length === 0) {
        event.preventDefault();
        return;
    }
    bulkForm.querySelectorAll('[data-bulk-row]').forEach((input) => input.remove());
    rows.forEach((row, index) => {
        const values = { id: row.dataset.paymentId };
        row.querySelectorAll('select[data-saved-value]').forEach((select) => { values[select.name] = select.value; });
        Object.entries(values).forEach(([field, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `rows[${index}][${field}]`;
            input.value = value;
            input.dataset.bulkRow = 'true';
            bulkForm.appendChild(input);
        });
    });
});
refreshPendingCount();
</script>
@endunless
@endsection
