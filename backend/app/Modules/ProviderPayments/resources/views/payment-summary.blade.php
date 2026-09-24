@once
@push('styles')
<style>
.payment-dashboard{max-width:960px;margin:10px 0;padding:12px;overflow-x:auto}.payment-dashboard h2{margin:0 0 8px;font-size:17px}.payment-grid{display:grid;grid-template-columns:95px repeat(4,minmax(110px,1fr)) minmax(170px,1.3fr);min-width:735px;gap:1px;background:var(--line);border:1px solid var(--line);font-size:13px}.payment-grid>*{margin:0;padding:7px 9px;background:#fff}.payment-grid strong{background:#eaf8f8;color:var(--turquoise-dark)}.payment-grid .payment-total,.payment-grid .payment-grand{font-weight:800}.payment-grid .payment-grand{background:#d9f5f4;border-top:2px solid var(--turquoise-dark)}@media(max-width:600px){.payment-grid{font-size:11px}.payment-grid>*{padding:6px 4px}}
</style>
@endpush
@endonce
<section class="card payment-dashboard" aria-label="Resumen por zona y condición de pago">
    <h2>Resumen de pago · {{ $period }}</h2>
    <div class="payment-grid">
        <strong>Zona</strong><strong>Registros Considerados</strong><strong>Registros No Considerados</strong><strong>Sin definir</strong><strong>Total</strong><strong>Total Neto Considerado</strong>
        @php($totals = ['yes' => 0, 'no' => 0, 'unset' => 0, 'net' => 0])
        @foreach(['RM', 'Regiones', 'Sin zona'] as $zoneGroup)
            @php($zoneRows = $paymentDashboard->get($zoneGroup, collect()))
            @php($yes = (int) ($zoneRows->firstWhere('condicion_pago', 'SI')?->total ?? 0))
            @php($no = (int) ($zoneRows->firstWhere('condicion_pago', 'NO')?->total ?? 0))
            @php($unset = (int) ($zoneRows->first(fn ($row) => $row->condicion_pago === null)?->total ?? 0))
            @php($net = (int) ($zoneRows->firstWhere('condicion_pago', 'SI')?->neto_considerado ?? 0))
            @php($totals['yes'] += $yes)
            @php($totals['no'] += $no)
            @php($totals['unset'] += $unset)
            @php($totals['net'] += $net)
            <strong>{{ $zoneGroup }}</strong><span>{{ number_format($yes, 0, ',', '.') }}</span><span>{{ number_format($no, 0, ',', '.') }}</span><span>{{ number_format($unset, 0, ',', '.') }}</span><span class="payment-total">{{ number_format($yes + $no + $unset, 0, ',', '.') }}</span><span class="payment-total">$ {{ number_format($net, 0, ',', '.') }}</span>
        @endforeach
        <strong class="payment-grand">Total</strong><span class="payment-grand">{{ number_format($totals['yes'], 0, ',', '.') }}</span><span class="payment-grand">{{ number_format($totals['no'], 0, ',', '.') }}</span><span class="payment-grand">{{ number_format($totals['unset'], 0, ',', '.') }}</span><span class="payment-grand">{{ number_format($totals['yes'] + $totals['no'] + $totals['unset'], 0, ',', '.') }}</span><span class="payment-grand">$ {{ number_format($totals['net'], 0, ',', '.') }}</span>
    </div>
</section>
