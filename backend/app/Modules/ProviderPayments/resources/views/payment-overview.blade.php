@once
@push('styles')
<style>
.payment-overview{margin:18px 0 0}.payment-overview h2{margin:0 0 12px;font-size:18px}.payment-overview-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr)) minmax(260px,.9fr);gap:12px}.payment-zone,.payment-chart{min-width:0;padding:16px}.payment-zone h3,.payment-chart h3{margin:0;color:var(--turquoise-dark);font-size:16px}.payment-zone .zone-total{display:block;margin:10px 0 2px;font-size:26px;line-height:1.1;font-variant-numeric:tabular-nums}.payment-zone .zone-label{color:var(--muted);font-size:12px}.payment-zone dl{display:grid;grid-template-columns:1fr auto;gap:7px;margin:16px 0 0;padding-top:13px;border-top:1px solid var(--line);font-size:13px}.payment-zone dt{color:var(--muted)}.payment-zone dd{margin:0;text-align:right;font-weight:750;font-variant-numeric:tabular-nums}.payment-zone .net-label,.payment-zone .net-value{padding-top:9px;border-top:1px solid var(--line);color:var(--ink);font-weight:800}.payment-chart h3{font-size:14px}.payment-chart-content{display:flex;align-items:center;justify-content:center;gap:16px;flex-wrap:wrap;margin-top:14px}.payment-donut{width:140px;height:140px;flex:none;display:grid;place-items:center;border-radius:50%;background:conic-gradient(var(--turquoise-dark) 0 var(--rm-end),var(--turquoise) var(--rm-end) var(--regions-end),#a5b7bb var(--regions-end) 100%)}.payment-donut.is-empty{background:#dce7e8}.payment-donut-center{width:102px;height:102px;display:flex;flex-direction:column;align-items:center;justify-content:center;border-radius:50%;background:#fff;text-align:center}.payment-donut-center span{color:var(--muted);font-size:11px}.payment-donut-center strong{margin-top:3px;font-size:15px;font-variant-numeric:tabular-nums}.payment-legend{display:grid;gap:8px;margin:0;padding:0;list-style:none;font-size:12px}.payment-legend li{display:flex;align-items:center;gap:7px}.payment-legend i{width:10px;height:10px;flex:none;border-radius:50%}.payment-legend .rm{background:var(--turquoise-dark)}.payment-legend .regions{background:var(--turquoise)}.payment-legend .unassigned{background:#a5b7bb}.payment-legend strong{font-variant-numeric:tabular-nums}@media(max-width:1100px){.payment-overview-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.payment-chart{grid-column:1/-1}}@media(max-width:650px){.payment-overview-grid{grid-template-columns:1fr}.payment-chart{grid-column:auto}}
</style>
@endpush
@endonce
@php
    $zones = collect(['RM', 'Regiones', 'Sin zona'])->mapWithKeys(function ($zone) use ($paymentDashboard) {
        $rows = $paymentDashboard->get($zone, collect());
        $considered = (int) ($rows->firstWhere('condicion_pago', 'SI')?->total ?? 0);
        $excluded = (int) ($rows->firstWhere('condicion_pago', 'NO')?->total ?? 0);
        $undefined = (int) ($rows->first(fn ($row) => $row->condicion_pago === null)?->total ?? 0);

        return [$zone => [
            'considered' => $considered,
            'excluded' => $excluded,
            'undefined' => $undefined,
            'total' => $considered + $excluded + $undefined,
            'net' => (int) ($rows->firstWhere('condicion_pago', 'SI')?->neto_considerado ?? 0),
        ]];
    });
    $totalNet = $zones->sum('net');
    $rmShare = $totalNet > 0 ? round($zones['RM']['net'] * 100 / $totalNet, 2) : 0;
    $regionsShare = $totalNet > 0 ? round($zones['Regiones']['net'] * 100 / $totalNet, 2) : 0;
@endphp
<section class="payment-overview" aria-label="Pagos por zona del período {{ $period }}">
    <h2>Pagos del período {{ $period }}</h2>
    <div class="payment-overview-grid">
        @foreach(['RM', 'Regiones'] as $zone)
            @php($data = $zones[$zone])
            <article class="card payment-zone">
                <h3>{{ $zone }}</h3>
                <strong class="zone-total">{{ number_format($data['total'], 0, ',', '.') }}</strong>
                <span class="zone-label">registros trabajados</span>
                <dl>
                    <dt>Registros considerados</dt><dd>{{ number_format($data['considered'], 0, ',', '.') }}</dd>
                    <dt>Registros no considerados</dt><dd>{{ number_format($data['excluded'], 0, ',', '.') }}</dd>
                    <dt>Sin definir</dt><dd>{{ number_format($data['undefined'], 0, ',', '.') }}</dd>
                    <dt class="net-label">Neto considerado</dt><dd class="net-value">$ {{ number_format($data['net'], 0, ',', '.') }}</dd>
                </dl>
            </article>
        @endforeach
        <article class="card payment-chart">
            <h3>Distribución del neto considerado</h3>
            <div class="payment-chart-content">
                <div class="payment-donut {{ $totalNet === 0 ? 'is-empty' : '' }}" style="--rm-end:{{ number_format($rmShare, 2, '.', '') }}%;--regions-end:{{ number_format($rmShare + $regionsShare, 2, '.', '') }}%" role="img" aria-label="Neto considerado: RM $ {{ number_format($zones['RM']['net'], 0, ',', '.') }}; Regiones $ {{ number_format($zones['Regiones']['net'], 0, ',', '.') }}; Sin zona $ {{ number_format($zones['Sin zona']['net'], 0, ',', '.') }}">
                    <div class="payment-donut-center"><span>Neto total</span><strong>$ {{ number_format($totalNet, 0, ',', '.') }}</strong></div>
                </div>
                <ul class="payment-legend">
                    <li><i class="rm" aria-hidden="true"></i>RM <strong>{{ number_format($rmShare, 1, ',', '.') }}%</strong></li>
                    <li><i class="regions" aria-hidden="true"></i>Regiones <strong>{{ number_format($regionsShare, 1, ',', '.') }}%</strong></li>
                    @if($zones['Sin zona']['total'] > 0)
                        <li><i class="unassigned" aria-hidden="true"></i>Sin zona <strong>{{ number_format(100 - $rmShare - $regionsShare, 1, ',', '.') }}%</strong></li>
                    @endif
                </ul>
            </div>
        </article>
        @if($zones['Sin zona']['total'] > 0)
            <article class="card payment-zone">
                <h3>Sin zona</h3>
                <strong class="zone-total">{{ number_format($zones['Sin zona']['total'], 0, ',', '.') }}</strong>
                <span class="zone-label">registros trabajados · Neto considerado $ {{ number_format($zones['Sin zona']['net'], 0, ',', '.') }}</span>
            </article>
        @endif
    </div>
</section>
