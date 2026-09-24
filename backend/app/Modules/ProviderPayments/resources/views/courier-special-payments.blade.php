@extends('provider-payments::layout')
@section('title', 'Courier Especiales')
@push('styles')
<style>
.special-summary{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}.special-summary .card{min-width:210px;padding:15px 18px}.special-summary strong{display:block;font-size:1.25rem}.special-tools{display:flex;align-items:end;gap:12px;flex-wrap:wrap;margin:18px 0}.special-tools label{display:block;font-weight:700;margin-bottom:5px}.special-tools select,.special-tools input{min-height:38px}.special-table{overflow:auto}.special-table table{width:100%;border-collapse:collapse;min-width:1250px}.special-table th,.special-table td{border-bottom:1px solid #d4e3e8;padding:9px;text-align:left;vertical-align:top}.special-table th{background:#eefbfc;white-space:nowrap}.special-table td.amount{text-align:right;white-space:nowrap}.special-alert{padding:12px 16px;margin:12px 0;background:#e8fbfb;border-left:4px solid #007980}.special-alert.error{background:#fff0f1;border-color:#b52b2b}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a>
<p class="eyebrow">Carga Movimientos Courier</p><h1>Courier Especiales</h1>
<p class="intro">Pagos especiales cargados desde Excel. El período de pago se elige al cargar la base y puede diferir de la fecha de cada registro.</p>
@if (session('status'))<div class="special-alert" role="status">{{ session('status') }}</div>@endif
@if ($errors->any())<div class="special-alert error" role="alert">{{ $errors->first() }}</div>@endif
<div class="special-summary">
    @foreach ($periods as $period)
        <div class="card"><span>{{ $period->periodo }}</span><strong>{{ number_format($period->total, 0, ',', '.') }} registros</strong><span>$ {{ number_format($period->monto_total, 0, ',', '.') }}</span></div>
    @endforeach
    @if ($periods->isEmpty())<div class="card">Aún no hay pagos especiales cargados.</div>@endif
</div>
<div class="card">
    <form method="post" action="{{ route('provider-payments.courier-movements.especiales.store') }}" enctype="multipart/form-data">
        @csrf
        <label for="special-file"><strong>Cargar base de pagos especiales (.xlsx)</strong></label>
        <div class="special-tools"><div><label for="period-month">Período de pago (AAAAMM-Especiales)</label><input id="period-month" name="period_month" type="month" value="{{ old('period_month', $selectedPeriod !== '' ? substr($selectedPeriod, 0, 4).'-'.substr($selectedPeriod, 4, 2) : now()->format('Y-m')) }}" required></div><div><label for="special-file">Archivo Excel</label><input id="special-file" name="file" type="file" accept=".xlsx" required></div><button type="submit">Cargar pagos especiales</button></div>
        <p class="note">Se conserva la fecha original de cada fila. Volver a cargar el mismo archivo no duplica registros y corrige su período si seleccionas otro.</p>
    </form>
</div>
<form class="special-tools" method="get" action="{{ route('provider-payments.courier-movements.especiales') }}">
    <div><label for="periodo">Período</label><select id="periodo" name="periodo">@foreach ($periods as $period)<option value="{{ $period->periodo }}" @selected($selectedPeriod === $period->periodo)>{{ $period->periodo }}</option>@endforeach</select></div>
    <button type="submit">Ver período</button>
</form>
<div class="card special-table"><table><thead><tr><th>Período</th><th>Fecha</th><th>Usuario Ingresa</th><th>Autoriza</th><th>Agente</th><th>Zona / Tipo</th><th>ID</th><th>Localidad</th><th>Cliente</th><th>Descripción</th><th>Monto ($)</th></tr></thead><tbody>
@forelse ($payments as $payment)
    <tr><td>{{ $payment->periodo }}</td><td>{{ $payment->fecha->format('d-m-Y') }}</td><td>{{ $payment->usuario_ingresa }}</td><td>{{ $payment->autoriza }}</td><td>{{ $payment->agente }}</td><td>{{ $payment->zona_tipo }}</td><td>{{ $payment->codigo_seguimiento ?? '—' }}</td><td>{{ $payment->localidad }}</td><td>{{ $payment->cliente ?? '—' }}</td><td>{{ $payment->descripcion ?? '—' }}</td><td class="amount">$ {{ number_format($payment->monto, 0, ',', '.') }}</td></tr>
@empty
    <tr><td colspan="11">No hay pagos especiales para mostrar.</td></tr>
@endforelse
</tbody></table></div>
{{ $payments->links() }}
@endsection
