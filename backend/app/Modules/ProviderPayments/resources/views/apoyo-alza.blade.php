@extends('provider-payments::layout')
@section('title', 'Apoyo Alza')
@push('styles')
<style>
.apoyo-bar,.apoyo-stats{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:10px 0}.apoyo-bar label{display:flex;flex-direction:column;gap:4px;font-size:.82rem;font-weight:700}.apoyo-bar input,.apoyo-bar select{min-height:38px}.apoyo-stats .card{min-width:160px;padding:10px 14px;flex:1}.apoyo-stats strong{display:block;font-size:1.15rem}.apoyo-table{overflow-x:auto}.apoyo-table table{width:100%;border-collapse:collapse;min-width:1250px}.apoyo-table th,.apoyo-table td{padding:8px;border-bottom:1px solid #d4e3e8;text-align:left;vertical-align:middle}.apoyo-table th{background:#eaf8f8;color:#007980;font-size:.78rem}.apoyo-table tr.pending{background:#fff1e9}.apoyo-table td.money{text-align:right;white-space:nowrap}.apoyo-table select,.apoyo-table input,.apoyo-table textarea{width:100%;min-height:36px;font-size:.85rem}.apoyo-table .small{color:#60747a;font-size:.77rem;display:block}.apoyo-alert{padding:10px 14px;border-left:4px solid #008187;background:#e7f7f5;margin:10px 0}.apoyo-alert.error{background:#fff0f1;border-color:#b52b2b}.apoyo-close{padding:12px 16px;margin:12px 0}.apoyo-close p{margin:0 0 8px}.apoyo-close details{margin-top:8px}.apoyo-close summary{cursor:pointer;font-weight:700}.apoyo-close label{display:grid;gap:4px;max-width:250px;margin:10px 0;font-size:13px;font-weight:700}.apoyo-close input{padding:8px;border:1px solid #d4e3e8;border-radius:7px}
</style>
@endpush
@section('content')
<div class="page-heading"><a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a><p class="eyebrow">Carga Movimientos Courier</p><h1>Apoyo Alza</h1></div>
<p class="intro">Carga el proceso AAAAMM-Apoyo y revisa cada cálculo antes de incorporarlo a pagos.</p>
@if (session('status'))<div class="apoyo-alert" role="status">{{ session('status') }}</div>@endif
@if ($errors->any())<div class="apoyo-alert error" role="alert">{{ $errors->first() }}</div>@endif
<div class="card"><form class="apoyo-bar" method="post" enctype="multipart/form-data" action="{{ route('provider-payments.courier-movements.apoyo-alza.import') }}">
    @csrf<label>Período AAAAMM<input type="month" name="periodo" value="{{ old('periodo', $isClosed ? \Carbon\CarbonImmutable::create((int) substr($period,0,4),(int) substr($period,4,2),1)->addMonth()->format('Y-m') : substr($period, 0, 4).'-'.substr($period, 4, 2)) }}" required></label>
    <label>Planilla Base_ApoyoAlza.xlsx<input type="file" name="file" accept=".xlsx" required></label><button type="submit">Cargar planilla</button>
</form><p class="note">Cada fila toma el total mensual del proveedor, aunque se repita en distintas agencias. Se usan solo pagos de origen cerrados. Revisa y guarda los cambios antes de cerrar.</p></div>
<form class="apoyo-bar" method="get" action="{{ route('provider-payments.courier-movements.apoyo-alza') }}">
    <label>Período<select name="periodo">@foreach ($periods as $option)<option value="{{ $option }}" @selected($period === $option)>{{ $option }}</option>@endforeach@if (! $periods->contains($period))<option value="{{ $period }}" selected>{{ $period }}</option>@endif</select></label>
    <label>Proceso base<select name="proceso"><option value="">Todos</option>@foreach (['Acuerdos', 'Variables', 'Ruta CV'] as $option)<option value="{{ $option }}" @selected($process === $option)>{{ $option }}</option>@endforeach</select></label>
    <label>Estado<select name="estado"><option value="todos" @selected($status === 'todos')>Todos</option><option value="pendientes" @selected($status === 'pendientes')>Pendientes</option><option value="calculado" @selected($status === 'calculado')>Calculados</option><option value="no_pagar" @selected($status === 'no_pagar')>No pagar</option></select></label>
    <label>Buscar<input type="search" name="q" value="{{ $search }}" placeholder="Proveedor, RUT, agencia…"></label><button type="submit">Filtrar</button>
    <a class="button secondary" href="{{ route('provider-payments.courier-movements.apoyo-alza', ['periodo' => $period]) }}">Limpiar</a>
</form>
<div class="apoyo-stats">
    <div class="card"><span>Proceso</span><strong>{{ $period }}-Apoyo</strong></div>
    <div class="card"><span>Filas cargadas</span><strong>{{ number_format($summary->registros ?? 0, 0, ',', '.') }}</strong></div>
    <div class="card"><span>Calculadas</span><strong>{{ number_format($summary->calculados ?? 0, 0, ',', '.') }}</strong></div>
    <div class="card"><span>No pagar</span><strong>{{ number_format($summary->no_pagar ?? 0, 0, ',', '.') }}</strong></div>
    <div class="card"><span>Pendientes</span><strong>{{ number_format(($summary->registros ?? 0) - ($summary->calculados ?? 0) - ($summary->no_pagar ?? 0), 0, ',', '.') }}</strong></div>
    <div class="card"><span>{{ $isClosed ? 'Monto grabado' : 'Monto propuesto' }}</span><strong>$ {{ number_format($summary->monto ?? 0, 0, ',', '.') }}</strong></div>
</div>
@if (($summary->registros ?? 0) > 0)
    @if ($isClosed)
        <div class="card apoyo-close"><p><strong>Período {{ $period }} cerrado.</strong> Los apoyos están grabados en pagos y la edición está bloqueada.</p>
            @unless($monthClosed)
            <details><summary>Reabrir con clave maestra</summary><form method="post" action="{{ route('provider-payments.courier-movements.apoyo-alza.reopen') }}" onsubmit="return confirm('¿Eliminar solo los pagos de Apoyo Alza de {{ $period }} y reabrir el período para corregirlo?')">@csrf<input type="hidden" name="periodo" value="{{ $period }}"><label>Clave maestra<input type="password" name="password" required autocomplete="off"></label><button type="submit">Eliminar pagos de Apoyo Alza y reabrir</button></form></details>
            @endunless
        </div>
    @else
        <form class="apoyo-bar" method="post" action="{{ route('provider-payments.courier-movements.apoyo-alza.recalculate') }}">@csrf<input type="hidden" name="periodo" value="{{ $period }}"><button type="submit">Recalcular con procesos cerrados</button><span class="note">Primero los problemas; luego, proveedor y servicio en orden alfabético.</span></form>
        <form class="card apoyo-close" method="post" action="{{ route('provider-payments.courier-movements.apoyo-alza.close') }}" onsubmit="return confirm('¿Grabar {{ $summary->calculados }} pagos de Apoyo Alza de {{ $period }} y bloquear su edición?')">@csrf<input type="hidden" name="periodo" value="{{ $period }}"><p>Al cerrar, se grabarán {{ number_format($summary->calculados, 0, ',', '.') }} pagos por $ {{ number_format($summary->monto, 0, ',', '.') }}. {{ number_format($summary->no_pagar, 0, ',', '.') }} apoyos de $ 0 quedarán sin pago. Cliente: 4 Nortes Logística SpA. El proceso quedará bloqueado.</p><button type="submit" @disabled((int) $summary->calculados + (int) $summary->no_pagar !== (int) $summary->registros)>Grabar y cerrar proceso</button>@if((int) $summary->calculados + (int) $summary->no_pagar !== (int) $summary->registros)<span class="note">Corrige los apoyos pendientes antes de cerrar.</span>@endif</form>
    @endif
@endif
<form method="post" action="{{ route('provider-payments.courier-movements.apoyo-alza.update') }}">
    @csrf<input type="hidden" name="periodo" value="{{ $period }}"><input type="hidden" name="page" value="{{ $rows->currentPage() }}"><input type="hidden" name="proceso" value="{{ $process }}"><input type="hidden" name="estado" value="{{ $status }}"><input type="hidden" name="q" value="{{ $search }}">
    <div class="card apoyo-table"><table><thead><tr><th>Proveedor de planilla / Asociado</th><th>Proceso y servicio</th><th>Agencia</th><th>Empresa</th><th>Factor</th><th>Base de cálculo</th><th>Apoyo calculado</th><th>Estado</th></tr></thead><tbody>
    @forelse ($rows as $row)
        @php $prefix = 'rows['.$row->id.']'; @endphp
        <tr @class(['pending' => ! in_array($row->estado_calculo, ['calculado', 'no_pagar'], true)])>
            <td><strong>{{ $row->proveedor_origen }}</strong><span class="small">RUT origen: {{ $row->rut_proveedor_origen ?: 'sin RUT' }} · fila {{ $row->fila_origen }}</span><select name="{{ $prefix }}[provider_id]" aria-label="Proveedor fila {{ $row->fila_origen }}" @disabled($isClosed)><option value="">Seleccionar proveedor</option>@foreach ($providers as $provider)<option value="{{ $provider->id }}" @selected((string) $row->provider_id === (string) $provider->id)>{{ $provider->operational_name ?: $provider->legal_name }} · {{ $provider->tax_id }}</option>@endforeach</select></td>
            <td><strong>{{ $row->proceso_base }}</strong>@if ($row->proceso_base === 'Acuerdos')<span class="small">Servicios del acuerdo · separar con |</span><textarea name="{{ $prefix }}[servicio_acuerdo]" rows="3" maxlength="160" aria-label="Servicios fila {{ $row->fila_origen }}" @disabled($isClosed)>{{ $row->servicio_acuerdo }}</textarea>@else<input type="hidden" name="{{ $prefix }}[servicio_acuerdo]" value="{{ $row->servicio_acuerdo }}"><span class="small">{{ $row->servicio_acuerdo ?: 'Total del proceso' }}</span>@endif</td>
            <td><input name="{{ $prefix }}[agencia]" value="{{ $row->agencia }}" maxlength="150" aria-label="Agencia fila {{ $row->fila_origen }}" required @disabled($isClosed)></td>
            <td><input name="{{ $prefix }}[empresa_mandante]" value="{{ $row->empresa_mandante }}" maxlength="20" aria-label="Empresa mandante fila {{ $row->fila_origen }}" required @disabled($isClosed)></td>
            <td>@if ($row->factor === 'Dia de Ruta CV')<span class="small">Monto por día ($)</span><input type="number" name="{{ $prefix }}[monto_dia]" value="{{ $row->monto_dia }}" min="0" step="1" aria-label="Monto por día fila {{ $row->fila_origen }}" @disabled($isClosed)>@else<span class="small">Porcentaje (%)</span><input type="number" name="{{ $prefix }}[porcentaje]" value="{{ $row->porcentaje !== null ? (float) $row->porcentaje * 100 : '' }}" min="0" max="100" step="0.0001" aria-label="Porcentaje fila {{ $row->fila_origen }}" @disabled($isClosed)>@endif</td>
            <td>
                @if ($row->factor === 'Dia de Ruta CV')
                    {{ number_format($row->dias_base ?? 0, 0, ',', '.') }} días
                @else
                    $ {{ number_format($row->monto_base ?? 0, 0, ',', '.') }}
                @endif
                <span class="small">{{ $row->registros_base }} pagos de origen</span>
            </td>
            <td class="money"><strong>{{ $row->monto_apoyo === null ? 'Pendiente' : '$ '.number_format($row->monto_apoyo, 0, ',', '.') }}</strong></td>
            <td>{{ match ($row->estado_calculo) { 'calculado' => 'Calculado', 'no_pagar' => 'No pagar', 'sin_proveedor' => 'Revisar proveedor', 'proceso_abierto' => 'Cerrar proceso base', 'sin_base' => 'Sin pagos base', 'sin_valor_base' => 'Falta valor base', 'sin_factor' => 'Falta factor', default => 'Pendiente' } }}</td>
        </tr>
    @empty<tr><td colspan="8">No hay apoyos para los filtros elegidos. Carga la planilla para comenzar.</td></tr>@endforelse
    </tbody></table></div>
    @if ($rows->count() > 0 && ! $isClosed)<div class="apoyo-bar"><button type="submit">Guardar cambios de esta página</button><span class="note">Al guardar, se recalculan los apoyos del período.</span></div>@endif
</form>
@include('provider-payments::partials.pagination', ['paginator' => $rows])
@endsection
