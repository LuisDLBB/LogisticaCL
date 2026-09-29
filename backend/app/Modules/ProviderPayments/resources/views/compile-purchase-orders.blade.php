@extends('provider-payments::layout')
@section('title', 'Órdenes de compra · '.$period)
@push('styles')
<style>
.oc-review{overflow-x:auto;margin-top:18px}.oc-review table{width:100%;border-collapse:collapse;min-width:1220px;font-size:13px}.oc-review th,.oc-review td{padding:9px 10px;border-bottom:1px solid var(--line);text-align:left}.oc-review th{color:var(--turquoise-dark);background:#eaf8f8}.oc-review td:nth-last-child(-n+5),.oc-review th:nth-last-child(-n+5){text-align:right}.oc-review .oc-total{font-weight:700;background:#eaf8f8}.oc-review-summary{display:flex;gap:18px;flex-wrap:wrap;margin:14px 0;color:var(--muted)}.oc-downloads{white-space:nowrap}.oc-downloads a{display:inline-block;margin-left:8px}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.courier-movements.compile', ['period' => $period]) }}">← Compilar Movimientos</a>
<p class="eyebrow">Gestión operacional</p><h1>Órdenes de compra · {{ $period }}</h1>
<p class="intro">{{ $closed ? 'Órdenes definitivas guardadas en Maestro_Pagos.' : 'Vista previa de los pagos SI. Las OC se asignarán al cerrar el período; si cambian los pagos antes del cierre, esta vista también cambiará.' }}</p>
@if($errors->any())<p class="warning">{{ $errors->first() }}</p>@endif
<div class="oc-review-summary">
    <strong>{{ number_format(count($summary), 0, ',', '.') }} órdenes de compra</strong>
    <strong>$ {{ number_format(array_sum(array_column($summary, 'total')), 0, ',', '.') }} base</strong>
    <strong>$ {{ number_format($taxTotals['IVA'], 0, ',', '.') }} IVA</strong>
    <strong>$ {{ number_format($taxTotals['Retencion'], 0, ',', '.') }} retenciones</strong>
    <strong>$ {{ number_format(array_sum(array_column($summary, 'valor_final_total')), 0, ',', '.') }} final</strong>
</div>
<p class="note">El IVA se suma al valor base y las retenciones se descuentan. El impuesto se redondea al peso entero sobre el total de cada documento por OC.</p>
@if($closed)<p><a class="button" href="{{ route('provider-payments.courier-movements.compile.purchase-orders.summary-excel', ['period' => $period]) }}">Descargar resumen completo en Excel</a> <a class="button" href="{{ route('provider-payments.courier-movements.compile.purchase-orders.mail', ['period' => $period]) }}">Preparar envío de prefacturas por correo</a></p>@endif
<div class="card oc-review">
    <table>
        <thead><tr><th>OC</th><th>Zona</th><th>Razón social proveedor</th><th>RUT</th><th>Empresa mandante</th><th>Agrupación</th><th>Pagos</th><th>Valor base</th><th>Impuesto</th><th>% impuesto</th><th>Valor impuesto</th><th>Valor final total</th><th>Documentos</th></tr></thead>
        <tbody>
            @forelse($summary as $order)
                <tr><td><strong>{{ $order['oc'] }}</strong></td><td>{{ $order['zona'] }}</td><td>{{ $order['razon_social_proveedor'] }}</td><td>{{ $order['rut_proveedor'] }}</td><td>{{ $order['empresa_mandante'] }}</td><td>{{ $order['concepto'] }}</td><td>{{ number_format($order['registros'], 0, ',', '.') }}</td><td>$ {{ number_format($order['total'], 0, ',', '.') }}</td><td>{{ $order['impuesto'] }}</td><td>{{ $order['porcentaje_impuesto'] === null ? '—' : rtrim(rtrim(number_format((float) $order['porcentaje_impuesto'], 2, ',', ''), '0'), ',').'%' }}</td><td>$ {{ number_format($order['valor_impuesto'], 0, ',', '.') }}</td><td><strong>$ {{ number_format($order['valor_final_total'], 0, ',', '.') }}</strong></td><td class="oc-downloads">@if($closed)<a href="{{ route('provider-payments.courier-movements.compile.purchase-orders.pdf', $order['oc']) }}">PDF</a><a href="{{ route('provider-payments.courier-movements.compile.purchase-orders.excel', $order['oc']) }}">Excel</a>@else<span class="note">Al cerrar</span>@endif</td></tr>
            @empty
                <tr><td colspan="13">No hay pagos SI para este período.</td></tr>
            @endforelse
        </tbody>
        @if($summary)
            <tfoot><tr class="oc-total"><td colspan="6">Total</td><td>{{ number_format(array_sum(array_column($summary, 'registros')), 0, ',', '.') }}</td><td>$ {{ number_format(array_sum(array_column($summary, 'total')), 0, ',', '.') }}</td><td colspan="2"></td><td>$ {{ number_format(array_sum(array_column($summary, 'valor_impuesto')), 0, ',', '.') }}</td><td>$ {{ number_format(array_sum(array_column($summary, 'valor_final_total')), 0, ',', '.') }}</td><td></td></tr></tfoot>
        @endif
    </table>
</div>
@endsection
