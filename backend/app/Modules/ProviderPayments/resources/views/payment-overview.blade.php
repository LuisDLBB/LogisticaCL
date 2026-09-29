@once
@push('styles')
<style>
.payment-overview{margin:18px 0 0}.payment-overview-heading{display:flex;align-items:end;justify-content:space-between;gap:12px;margin-bottom:12px}.payment-overview h2{margin:0;font-size:18px}.payment-overview-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.payment-zone,.payment-chart-suite{min-width:0;padding:16px}.payment-zone h3,.payment-chart-suite h3{margin:0;color:var(--turquoise-dark);font-size:16px}.payment-zone .zone-total{display:block;margin:10px 0 2px;font-size:26px;line-height:1.1;font-variant-numeric:tabular-nums}.payment-zone .zone-label{color:var(--muted);font-size:12px}.payment-zone dl{display:grid;grid-template-columns:1fr auto;gap:7px;margin:16px 0 0;padding-top:13px;border-top:1px solid var(--line);font-size:13px}.payment-zone dt{color:var(--muted)}.payment-zone dd{margin:0;text-align:right;font-weight:750;font-variant-numeric:tabular-nums}.payment-zone .net-label,.payment-zone .net-value{padding-top:9px;border-top:1px solid var(--line);color:var(--ink);font-weight:800}.payment-chart-control{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;white-space:nowrap}.payment-chart-control select{width:auto;min-width:110px;padding:6px 28px 6px 10px;font-size:12px}.payment-chart-suite{grid-column:1/-1}.payment-chart-layout{display:grid;grid-template-columns:minmax(290px,350px) minmax(175px,225px) minmax(0,1fr);gap:20px;align-items:start}.payment-chart-module{min-width:0}.payment-chart-suite h3{font-size:14px;line-height:1.25}.payment-region-visual{display:flex;align-items:center;gap:18px;margin-top:17px}.payment-process-visual{display:flex;justify-content:center;margin-top:17px}.payment-donut{width:176px;height:176px;flex:none;display:grid;place-items:center;border-radius:50%;background:conic-gradient(var(--turquoise-dark) 0 var(--rm-end),var(--turquoise) var(--rm-end) var(--regions-end),#a5b7bb var(--regions-end) 100%)}.payment-donut.is-empty{background:#dce7e8}.payment-donut-center{width:128px;height:128px;display:flex;flex-direction:column;align-items:center;justify-content:center;border-radius:50%;background:#fff;text-align:center}.payment-donut-center span{color:var(--muted);font-size:11px}.payment-donut-center strong{margin-top:3px;font-size:16px;font-variant-numeric:tabular-nums}.payment-legend{display:grid;gap:9px;margin:0;padding:0;list-style:none;font-size:12px}.payment-legend li{display:grid;grid-template-columns:13px minmax(0,1fr);align-items:start;gap:7px;min-width:0}.payment-legend i{width:13px;height:13px;flex:none;margin-top:2px;border-radius:50%}.payment-legend .rm{background:var(--turquoise-dark)}.payment-legend .regions{background:var(--turquoise)}.payment-legend .unassigned{background:#a5b7bb}.payment-legend strong{font-weight:800;font-variant-numeric:tabular-nums}.payment-region-legend li span{white-space:nowrap}.payment-process-legend{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px 14px;align-content:start;padding-top:3px}.payment-process-legend small{display:block;margin-top:3px;color:var(--muted);font-size:11px;font-variant-numeric:tabular-nums}.payment-process-legend small strong{color:var(--ink)}.payment-process-legend li span{min-width:0;overflow-wrap:anywhere}.payment-chart-note{grid-column:1/-1;margin:0;color:var(--muted);font-size:11px;text-align:right}.payment-bars{display:none;min-width:0;margin-top:16px}.payment-bar-row{display:grid;gap:4px;margin-bottom:12px}.payment-bar-label{display:flex;justify-content:space-between;gap:8px;font-size:12px}.payment-bar-label strong{white-space:nowrap;font-variant-numeric:tabular-nums}.payment-bar-track{height:13px;overflow:hidden;border-radius:999px;background:#e5eeee}.payment-bar-fill{display:block;height:100%;min-width:0;border-radius:inherit;background:var(--bar-color);width:var(--bar-width)}.payment-bar-amount{color:var(--muted);font-size:11px;font-variant-numeric:tabular-nums}.payment-chart-suite[data-chart-view="barras"] .payment-chart-layout{grid-template-columns:minmax(0,1fr) minmax(0,2fr)}.payment-chart-suite[data-chart-view="barras"] .payment-region-visual,.payment-chart-suite[data-chart-view="barras"] .payment-process-visual,.payment-chart-suite[data-chart-view="barras"] .payment-process-legend{display:none}.payment-chart-suite[data-chart-view="barras"] .payment-bars{display:block}.payment-chart-suite[data-chart-view="mixto"] .payment-chart-layout{grid-template-columns:minmax(290px,350px) minmax(220px,1fr) minmax(280px,1fr)}.payment-chart-suite[data-chart-view="mixto"] .payment-process-visual{display:none}.payment-chart-suite[data-chart-view="mixto"] .payment-process-bars{display:block}@media(max-width:1200px){.payment-chart-layout,.payment-chart-suite[data-chart-view="mixto"] .payment-chart-layout{grid-template-columns:minmax(290px,1fr) minmax(175px,1fr)}.payment-process-legend{grid-column:1/-1;grid-template-columns:repeat(3,minmax(0,1fr));border-top:1px solid var(--line);padding-top:12px}}@media(max-width:700px){.payment-chart-layout,.payment-chart-suite[data-chart-view="barras"] .payment-chart-layout,.payment-chart-suite[data-chart-view="mixto"] .payment-chart-layout,.payment-overview-grid{grid-template-columns:1fr}.payment-process-legend{grid-column:auto;grid-template-columns:repeat(2,minmax(0,1fr))}.payment-process-visual{justify-content:start}}@media(max-width:420px){.payment-overview-heading{align-items:start;flex-direction:column}.payment-region-visual{flex-wrap:wrap}.payment-process-legend{grid-template-columns:1fr}}
.payment-chart-toolbar{display:flex;justify-content:flex-start;min-height:30px;margin:-4px 0 8px}
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
@isset($processAmounts)
    @php
        $processTotal = (int) $processAmounts->sum();
        $processColors = ['Acuerdos' => '#567b87', 'Variable' => '#008489', 'Lanas' => '#26c2bf', 'Retornos' => '#4a82bb', 'Peumo' => '#ab6a34', 'Especiales' => '#eaa344', 'Ruta CV' => '#795da8', 'Servicios' => '#d56572', 'Visitas' => '#9c6c4e', 'Apoyo' => '#6b9864'];
        $fallbackColors = ['#567b87', '#9c6c4e', '#6b9864', '#aa729e'];
        $processSlices = [];
        $processGradient = [];
        $runningAmount = 0;
        foreach ($processAmounts as $processName => $amount) {
            $color = $processColors[$processName] ?? $fallbackColors[count($processSlices) % count($fallbackColors)];
            $start = $processTotal > 0 ? $runningAmount * 100 / $processTotal : 0;
            $runningAmount += (int) $amount;
            $end = $processTotal > 0 ? $runningAmount * 100 / $processTotal : 0;
            $processGradient[] = $color.' '.number_format($start, 4, '.', '').'% '.number_format($end, 4, '.', '').'%';
            $processSlices[] = ['name' => $processName, 'amount' => (int) $amount, 'share' => $end - $start, 'color' => $color];
        }
    @endphp
@endisset
<section class="payment-overview" aria-label="Pagos por zona del período {{ $period }}">
    <div class="payment-overview-heading">
        <h2>Pagos del período {{ $period }}</h2>
    </div>
    <div class="payment-overview-grid">
        <article class="card payment-chart-suite" data-payment-chart-suite data-chart-view="anillos">
            @isset($processAmounts)
                <div class="payment-chart-toolbar">
                    <label class="payment-chart-control">Tipo de gráfico
                        <select data-payment-chart-select aria-label="Tipo de gráfico de pagos">
                            <option value="anillos">Anillos</option>
                            <option value="barras">Barras</option>
                            <option value="mixto">Mixto</option>
                        </select>
                    </label>
                </div>
            @endisset
            <div class="payment-chart-layout">
                <div class="payment-chart-module">
                    <h3>Distribución del neto considerado</h3>
                    <div class="payment-region-visual">
                        <div class="payment-donut {{ $totalNet === 0 ? 'is-empty' : '' }}" style="--rm-end:{{ number_format($rmShare, 2, '.', '') }}%;--regions-end:{{ number_format($rmShare + $regionsShare, 2, '.', '') }}%" role="img" aria-label="Neto considerado: RM $ {{ number_format($zones['RM']['net'], 0, ',', '.') }}; Regiones $ {{ number_format($zones['Regiones']['net'], 0, ',', '.') }}; Sin zona $ {{ number_format($zones['Sin zona']['net'], 0, ',', '.') }}">
                            <div class="payment-donut-center"><span>Neto total</span><strong>$ {{ number_format($totalNet, 0, ',', '.') }}</strong></div>
                        </div>
                        <ul class="payment-legend payment-region-legend">
                            <li><i class="rm" aria-hidden="true"></i><span>RM <strong>{{ number_format($rmShare, 1, ',', '.') }}%</strong></span></li>
                            <li><i class="regions" aria-hidden="true"></i><span>Regiones <strong>{{ number_format($regionsShare, 1, ',', '.') }}%</strong></span></li>
                            @if($zones['Sin zona']['total'] > 0)
                                <li><i class="unassigned" aria-hidden="true"></i><span>Sin zona <strong>{{ number_format(100 - $rmShare - $regionsShare, 1, ',', '.') }}%</strong></span></li>
                            @endif
                        </ul>
                    </div>
                    <div class="payment-bars payment-region-bars">
                        @foreach(['RM', 'Regiones', 'Sin zona'] as $zone)
                            @if($zone !== 'Sin zona' || $zones[$zone]['total'] > 0)
                                @php($share = $totalNet > 0 ? $zones[$zone]['net'] * 100 / $totalNet : 0)
                                <div class="payment-bar-row">
                                    <div class="payment-bar-label"><span>{{ $zone }}</span><strong>{{ number_format($share, 1, ',', '.') }}%</strong></div>
                                    <div class="payment-bar-track"><span class="payment-bar-fill" style="--bar-width:{{ number_format($share, 2, '.', '') }}%;--bar-color:{{ ['RM' => '#008489', 'Regiones' => '#26c2bf', 'Sin zona' => '#a5b7bb'][$zone] }}"></span></div>
                                    <span class="payment-bar-amount">$ {{ number_format($zones[$zone]['net'], 0, ',', '.') }}</span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
                @isset($processAmounts)
                    <div class="payment-chart-module">
                        <h3>Neto considerado por proceso</h3>
                        <div class="payment-process-visual">
                            <div class="payment-donut {{ $processTotal === 0 ? 'is-empty' : '' }}" @if($processTotal > 0) style="background:conic-gradient({{ implode(', ', $processGradient) }})" @endif role="img" aria-label="Neto considerado por proceso: $ {{ number_format($processTotal, 0, ',', '.') }}">
                                <div class="payment-donut-center"><span>Neto total</span><strong>$ {{ number_format($processTotal, 0, ',', '.') }}</strong></div>
                            </div>
                        </div>
                        <div class="payment-bars payment-process-bars">
                            @foreach($processSlices as $slice)
                                <div class="payment-bar-row">
                                    <div class="payment-bar-label"><span>{{ $slice['name'] }}</span><strong>{{ number_format($slice['share'], 1, ',', '.') }}%</strong></div>
                                    <div class="payment-bar-track"><span class="payment-bar-fill" style="--bar-width:{{ number_format($slice['share'], 2, '.', '') }}%;--bar-color:{{ $slice['color'] }}"></span></div>
                                    <span class="payment-bar-amount">$ {{ number_format($slice['amount'], 0, ',', '.') }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    @if($processSlices !== [])
                        <ul class="payment-legend payment-process-legend">
                            @foreach($processSlices as $slice)
                                <li><i style="background:{{ $slice['color'] }}" aria-hidden="true"></i><span>{{ $slice['name'] }}<small>$ {{ number_format($slice['amount'], 0, ',', '.') }} · <strong>{{ number_format($slice['share'], 1, ',', '.') }}%</strong></small></span></li>
                            @endforeach
                        </ul>
                    @else
                        <p class="note">Aún no hay montos considerados en este período.</p>
                    @endif
                    <p class="payment-chart-note">Solo registros con condición de pago SI.</p>
                @endisset
            </div>
        </article>
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
        @if($zones['Sin zona']['total'] > 0)
            <article class="card payment-zone">
                <h3>Sin zona</h3>
                <strong class="zone-total">{{ number_format($zones['Sin zona']['total'], 0, ',', '.') }}</strong>
                <span class="zone-label">registros trabajados · Neto considerado $ {{ number_format($zones['Sin zona']['net'], 0, ',', '.') }}</span>
            </article>
        @endif
    </div>
</section>
@once
@push('scripts')
<script>
document.querySelectorAll('[data-payment-chart-select]').forEach((select) => {
    const charts = select.closest('.payment-overview')?.querySelector('[data-payment-chart-suite]');
    if (charts) {
        select.addEventListener('change', () => { charts.dataset.chartView = select.value; });
    }
});
</script>
@endpush
@endonce
