@extends('provider-payments::layout')
@section('title', 'Trabajar Registros de Courier')
@push('styles')
<style>
.compile-period{display:flex;align-items:end;gap:12px;margin-bottom:20px}.compile-period label{display:grid;gap:6px;font-weight:700}.compile-period select{padding:10px;min-width:180px;border:1px solid var(--line);border-radius:8px}.compile-processes{display:grid;gap:12px;margin:18px 0}.compile-process{display:flex;align-items:center;gap:12px;padding:15px;border:1px solid var(--line);border-radius:9px}.compile-process strong{min-width:110px}.compile-process small{color:var(--muted)}.compile-table{overflow:auto;max-height:65vh}.compile-table table{min-width:2200px}.compile-table th{position:sticky;top:0}.compile-status{padding:14px;margin:16px 0;background:#e8fbfa;border-left:4px solid var(--turquoise-dark)}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.courier-movements.compile') }}">← Compilar Movimientos</a>
<p class="eyebrow">Gestión operacional</p><h1>Trabajar Registros de Courier</h1>
<p class="intro">Selecciona el período AAAAMM y los procesos que pasarán a Pago_Movimientos_Courier.</p>
@if(session('status'))<div class="compile-status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="warning">{{ $errors->first() }}</div>@endif
<form class="compile-period" method="get"><label>Período AAAAMM<select name="period" onchange="this.form.submit()">@foreach($periods as $option)<option value="{{ $option }}" @selected($option === $period)>{{ $option }}</option>@endforeach</select></label><button type="submit">Ver procesos</button></form>
<form class="card" method="post" action="{{ route('provider-payments.courier-movements.compile.store') }}" onsubmit="this.querySelector('button[type=submit]').disabled=true;this.querySelector('.compile-loading').hidden=false;">@csrf<input type="hidden" name="period" value="{{ $period }}"><h2>Procesos disponibles</h2>
<div class="compile-processes">@forelse($processes as $process)<label class="compile-process"><input type="checkbox" name="processes[]" value="{{ substr($process->nombre_proceso, 7) }}"><strong>{{ substr($process->nombre_proceso, 7) }}</strong><span>{{ number_format($process->total, 0, ',', '.') }} movimientos</span><small>{{ number_format($compiled[$process->nombre_proceso] ?? 0, 0, ',', '.') }} trabajados</small></label>@empty<p>No hay procesos de Variables, Lanas o Retornos en este período.</p>@endforelse</div>
@if($processes->isNotEmpty())<button type="submit">Trabajar procesos seleccionados</button><div class="compile-loading" hidden><div class="progress-track"><div class="progress-bar"></div></div><p>Preparando registros…</p></div>@endif</form>
<h2>Registros trabajados ({{ number_format($rows->total(), 0, ',', '.') }})</h2>
<p class="note">Los registros sin proveedor único en Coberturas quedan con proveedor y zona pendientes.</p>
<div class="card compile-table"><table><thead><tr><th>Zona</th><th>Tipo Pago</th><th>Proceso</th><th>Período</th><th>Código seguimiento</th><th>Fecha</th><th>Dirección</th><th>Comuna destino</th><th>Comerciante (Pila)</th><th>RUT cliente</th><th>Razón social cliente</th><th>Peso Final</th><th>Estado envío</th><th>Razón social proveedor</th><th>RUT proveedor</th><th>Nombre operacional</th><th>Tipo documento</th><th>Nombre repartidor</th><th>Usuario entrega</th><th>Empresa mandante</th></tr></thead><tbody>@forelse($rows as $row)<tr><td>{{ $row->zona }}</td><td>{{ $row->tipo_pago }}</td><td>{{ $row->nombre_proceso }}</td><td>{{ $row->periodo }}</td><td>{{ $row->codigo_seguimiento }}</td><td>{{ $row->fecha?->format('d-m-Y') }}</td><td>{{ $row->direccion }}</td><td>{{ $row->comuna_destino }}</td><td>{{ $row->comerciante_pila }}</td><td>{{ $row->rut_cliente }}</td><td>{{ $row->razon_social_cliente }}</td><td>{{ $row->peso_final }}</td><td>{{ $row->estado_envio }}</td><td>{{ $row->razon_social_proveedor }}</td><td>{{ $row->rut_proveedor }}</td><td>{{ $row->nombre_operacional }}</td><td>{{ $row->tipo_documento }}</td><td>{{ $row->nombre_repartidor }}</td><td>{{ $row->usuario_entrega }}</td><td>{{ $row->empresa_mandante }}</td></tr>@empty<tr><td colspan="20">Aún no hay registros trabajados para este período.</td></tr>@endforelse</tbody></table></div>
{{ $rows->links() }}
@endsection
