@extends('provider-payments::layout')
@section('title', $title)
@push('styles')
<style>
.compile-actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap}.compile-period{display:flex;align-items:end;gap:10px;margin:22px 0}.compile-period label{display:grid;gap:6px;font-size:13px;font-weight:700}.compile-period select{padding:10px;min-width:160px;border:1px solid var(--line);border-radius:8px}.compile-list{max-width:720px}.compile-row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 0;border-bottom:1px solid var(--line)}.compile-row:last-child{border-bottom:0}.compile-row small{display:block;margin-top:3px;color:var(--muted)}.compile-row form{display:flex;align-items:end;gap:8px}.compile-row label{display:grid;gap:4px;font-size:12px;font-weight:700}.compile-row input{padding:8px;border:1px solid var(--line);border-radius:7px}.compile-delete{background:#a33030}.compile-delete:hover{background:#842525}.compile-status{padding:14px;margin:18px 0;background:#e8fbfa;border-left:4px solid var(--turquoise-dark)}.compile-close{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.compile-close button{background:#a33030}.compile-close button:disabled{background:#a8b7b8;cursor:not-allowed}.compile-close-note{font-size:13px;color:var(--muted)}.compile-oc{width:100%;border-collapse:collapse;font-size:13px}.compile-oc th,.compile-oc td{padding:8px;border-bottom:1px solid var(--line);text-align:left}.compile-oc th{color:var(--turquoise-dark)}.compile-oc td:last-child,.compile-oc th:last-child{text-align:right}.compile-oc-wrap{overflow-x:auto;margin-top:12px}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a>
<p class="eyebrow">Gestión operacional</p><h1>{{ $title }}</h1>
<p class="intro">Prepara los registros de Courier para el pago a proveedores.</p>
@if(session('status'))<div class="compile-status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="warning">{{ $errors->first() }}</div>@endif
<div class="compile-actions">
    <a class="button" href="{{ route('provider-payments.courier-movements.compile.work', $period ? ['period' => $period] : []) }}">Trabajar Registros de Courier</a>
    @if($period !== '')
        <a class="button" href="{{ route('provider-payments.courier-movements.compile.export', ['period' => $period]) }}">Exportar consolidado Excel</a>
        <a class="button" href="{{ route('provider-payments.courier-movements.compile.purchase-orders', ['period' => $period]) }}">Revisar órdenes de compra</a>
    @endif
</div>
@if($period !== '')
<div class="card" style="margin:16px 0;padding:16px">
    @if($closure)
        <strong>Período {{ $period }} cerrado definitivamente</strong>
        <span class="compile-close-note"> · {{ number_format($closure->registros, 0, ',', '.') }} pagos en Maestro_Pagos · $ {{ number_format($closure->total, 0, ',', '.') }}</span>
        <details style="margin-top:10px"><summary>Ver {{ number_format($purchaseOrders->count(), 0, ',', '.') }} órdenes de compra asignadas</summary>
            <div class="compile-oc-wrap"><table class="compile-oc"><thead><tr><th>OC</th><th>Zona</th><th>Razón social proveedor</th><th>RUT</th><th>Empresa mandante</th><th>Registros</th><th>Valor base</th><th>Impuesto / retención</th><th>Valor final</th><th>Detalle</th></tr></thead><tbody>
                @foreach($purchaseOrders as $order)
                    <tr><td>{{ $order->oc }}</td><td>{{ $order->zona }}</td><td>{{ $order->razon_social_proveedor }}</td><td>{{ $order->rut_proveedor }}</td><td>{{ $order->empresa_mandante }}</td><td>{{ number_format($order->registros, 0, ',', '.') }}</td><td>$ {{ number_format($order->total, 0, ',', '.') }}</td><td>$ {{ number_format($order->total_impuesto, 0, ',', '.') }}</td><td>$ {{ number_format($order->total_final, 0, ',', '.') }}</td><td><a href="{{ route('provider-payments.courier-movements.compile.purchase-orders.pdf', $order->oc) }}">PDF</a> · <a href="{{ route('provider-payments.courier-movements.compile.purchase-orders.excel', $order->oc) }}">Excel</a></td></tr>
                @endforeach
            </tbody></table></div>
        </details>
    @else
        <form class="compile-close" method="post" action="{{ route('provider-payments.courier-movements.compile.close') }}" onsubmit="return confirm('¿Cerrar definitivamente el período {{ $period }}? Solo los pagos SI pasarán a Maestro_Pagos y este período quedará bloqueado.')">
            @csrf<input type="hidden" name="period" value="{{ $period }}">
            <button type="submit" @disabled(! $payable || (int) $payable->registros === 0)>Cerrar procesos del período {{ $period }}</button>
            <span class="compile-close-note">{{ number_format($payable->registros ?? 0, 0, ',', '.') }} pagos SI · $ {{ number_format($payable->total ?? 0, 0, ',', '.') }} · Cierre definitivo</span>
        </form>
    @endif
</div>
@endif
<h2>Procesos cargados</h2>
<p class="note">Elimina solo los registros trabajados de Pago_Movimientos_Courier. Los movimientos originales se conservan.</p>
@if($periods)
<form class="compile-period" method="get"><label>Período AAAAMM<select name="period" onchange="this.form.submit()">@foreach($periods as $option)<option value="{{ $option }}" @selected($option === $period)>{{ $option }}</option>@endforeach</select></label><button type="submit">Filtrar</button></form>
<div class="card compile-list">
    @foreach($loadedProcesses as $process)
        @php($fullProcessName = $period.'-'.$process->nombre_proceso)
        @php($isFixed = in_array($process->nombre_proceso, ['Especiales', 'Ruta CV', 'Servicios', 'Acuerdos', 'Apoyo', 'Visitas'], true))
        <div class="compile-row"><div><strong>{{ $fullProcessName }}</strong><small>{{ number_format($process->total, 0, ',', '.') }} registros cargados</small></div>
            @if($closure)
                <span class="compile-close-note">Cierre definitivo</span>
            @else
            <form method="post" action="{{ $isFixed ? route('provider-payments.movements.processes.destroy') : route('provider-payments.courier-movements.compile.destroy') }}" onsubmit="return confirm('¿Eliminar los registros trabajados de {{ $fullProcessName }}?')">
                @csrf
                @if($isFixed)
                    @method('DELETE')<input type="hidden" name="process_name" value="{{ $fullProcessName }}">
                @else
                    @method('DELETE')<input type="hidden" name="period" value="{{ $period }}"><input type="hidden" name="process" value="{{ $process->nombre_proceso }}">
                @endif
                <label>Clave maestra <input type="password" name="password" required autocomplete="off"></label><button class="compile-delete" type="submit">{{ $isFixed ? 'Reabrir proceso' : 'Eliminar proceso' }}</button>
            </form>
            @endif
        </div>
    @endforeach
</div>
@else<p class="note">Aún no hay procesos trabajados.</p>@endif
@endsection
