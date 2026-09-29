@extends('provider-payments::layout')
@section('title', 'Base Servicios')
@push('styles')
<style>
    .services-toolbar{display:flex;align-items:end;gap:10px;flex-wrap:wrap}.services-toolbar label{display:grid;gap:5px;font-size:12px;font-weight:700}.services-toolbar input,.services-toolbar select,.services-table select{min-width:130px;padding:7px;border:1px solid var(--line);border-radius:7px;background:#fff;color:var(--ink)}.services-toolbar input[type=file]{min-width:250px}.services-upload{margin:14px 0}.services-summary{display:flex;gap:9px;flex-wrap:wrap;margin:12px 0}.services-summary .card{min-width:145px;padding:11px 15px}.services-summary small,.services-summary strong{display:block}.services-summary small{color:var(--muted);font-size:12px}.services-summary strong{font-size:18px}.services-table{overflow:auto;max-height:650px}.services-table table{min-width:1180px}.services-table td{vertical-align:top;font-size:12px}.services-table th{position:sticky;top:0;z-index:1}.services-table tr.needs-both{background:#fff0f0}.services-table tr.needs-one{background:#fff8e6}.services-table .original{display:block;margin-bottom:5px;color:#3c585c}.services-table .original small{display:block;color:var(--muted)}.services-table select{width:230px;max-width:none}.services-table .amount{text-align:right;white-space:nowrap;font-weight:750}.services-table details{min-width:150px;text-align:left}.services-table details p{margin:6px 0;line-height:1.35}.services-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:12px 0}.services-actions .note{margin:0}.services-empty{padding:18px}.services-alert{padding:11px 14px;border-left:4px solid var(--turquoise-dark);background:#ebfaf9;margin:10px 0}.services-alert.error{border-color:#b72828;background:#fff1f1}.services-pager{margin:12px 0}.services-close{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:12px 0;padding:14px}.services-close p{margin:0}.services-close details{width:100%}.services-close details form{display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin-top:12px}.services-close details label{display:grid;gap:4px;font-size:12px;font-weight:700}.services-close details input{padding:8px;border:1px solid var(--line);border-radius:7px}.services-table select:disabled{background:#edf3f3;color:#49636a}.services-edit-fields{display:grid;gap:7px;min-width:250px;margin-top:8px}.services-edit-fields label{display:grid;gap:2px;font-size:11px;font-weight:700}.services-edit-fields input,.services-edit-fields textarea{width:100%;padding:6px;border:1px solid var(--line);border-radius:6px;background:#fff;color:var(--ink)}.services-edit-fields textarea{min-height:54px;resize:vertical}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a>
<p class="eyebrow">Carga Movimientos Courier</p><h1>Base Servicios</h1>
<p class="intro">Carga la planilla original en Excel o CSV y revisa las asociaciones con los maestros de clientes y proveedores. Cada fila se conserva, incluso cuando el contenido se repite.</p>
@if(session('status'))<div class="services-alert" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="services-alert error" role="alert">{{ $errors->first() }}</div>@endif
<form class="card services-toolbar services-upload" method="post" action="{{ route('provider-payments.courier-movements.servicios.store') }}" enctype="multipart/form-data">
    @csrf
    <label>Base_Servicios (.xlsx o .csv)<input type="file" name="file" accept=".xlsx,.csv" required></label>
    <button type="submit">Cargar servicios</button>
    <span class="note">Se toma el período de la columna “Periodo”; volver a cargar el mismo archivo no duplica sus filas.</span>
</form>
<div class="services-summary">
    <div class="card"><small>Período</small><strong>{{ $period ?: '—' }}</strong></div>
    <div class="card"><small>Registros</small><strong>{{ number_format($summary->total ?? 0, 0, ',', '.') }}</strong></div>
    <div class="card"><small>Valor final</small><strong>$ {{ number_format($summary->amount ?? 0, 0, ',', '.') }}</strong></div>
    <div class="card"><small>Clientes por revisar</small><strong>{{ number_format($summary->missing_clients ?? 0, 0, ',', '.') }}</strong></div>
    <div class="card"><small>Proveedores por revisar</small><strong>{{ number_format($summary->missing_providers ?? 0, 0, ',', '.') }}</strong></div>
    <div class="card"><small>Asociaciones completas</small><strong>{{ number_format($summary->complete ?? 0, 0, ',', '.') }}</strong></div>
</div>
@if((int) $summary->total > 0)
    @if($isClosed)
        <div class="card services-close"><p><strong>Período {{ $period }} cerrado.</strong> Los servicios están grabados en pagos y sus asociaciones no se pueden modificar.</p>
            @unless($monthClosed)
            <details><summary>Reabrir con clave maestra</summary><form method="post" action="{{ route('provider-payments.courier-movements.servicios.reopen') }}" onsubmit="return confirm('¿Eliminar los pagos de Servicios de {{ $period }} y reabrir el período para corregirlo?')">@csrf<input type="hidden" name="periodo" value="{{ $period }}"><label>Clave maestra<input type="password" name="password" required autocomplete="off"></label><button type="submit">Eliminar pagos de Servicios y reabrir</button></form></details>
            @endunless
        </div>
    @else
        <form class="card services-close" method="post" action="{{ route('provider-payments.courier-movements.servicios.close') }}" onsubmit="return confirm('¿Cerrar Servicios de {{ $period }} y grabar {{ $summary->total }} pagos? Después necesitarás la clave maestra para corregirlos.')">@csrf<input type="hidden" name="periodo" value="{{ $period }}"><p>Al cerrar, se grabarán {{ number_format($summary->total, 0, ',', '.') }} pagos por $ {{ number_format($summary->amount, 0, ',', '.') }} y el período quedará bloqueado.@if((int) $summary->missing_clients + (int) $summary->missing_providers > 0) Completa las asociaciones pendientes antes de cerrar.@endif</p><button type="submit" @disabled((int) $summary->missing_clients + (int) $summary->missing_providers > 0)>Cerrar proceso</button></form>
    @endif
@endif
<form class="card services-toolbar" method="get" action="{{ route('provider-payments.courier-movements.servicios') }}">
    <label>Período<select name="periodo">@foreach($periods as $option)<option value="{{ $option->periodo }}" @selected($period === $option->periodo)>{{ $option->periodo }} · {{ number_format($option->total, 0, ',', '.') }}</option>@endforeach</select></label>
    <label>Asociación<select name="estado"><option value="pendientes" @selected($status === 'pendientes')>Pendientes</option><option value="completos" @selected($status === 'completos')>Completas</option><option value="todos" @selected($status === 'todos')>Todas</option></select></label>
    <label>Cliente<select name="cliente"><option value="">Todos</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected($clientId === $client->id)>{{ $client->commercial_name }} · {{ $client->tax_id }}</option>@endforeach</select></label>
    <label>Proveedor<select name="proveedor"><option value="">Todos</option>@foreach($providers as $provider)<option value="{{ $provider->id }}" @selected($providerId === $provider->id)>{{ $provider->operational_name ?: $provider->legal_name }}</option>@endforeach</select></label>
    <label>Buscar<input type="search" name="q" value="{{ $search }}" placeholder="Cliente, proveedor, usuario…"></label>
    <button type="submit">Filtrar</button><a class="button" href="{{ route('provider-payments.courier-movements.servicios', ['periodo' => $period, 'estado' => 'pendientes']) }}">Limpiar</a>
</form>
<p class="note">{{ number_format($rows->total(), 0, ',', '.') }} filas encontradas. Rojo: faltan cliente y proveedor. Amarillo: falta uno. El nombre y RUT originales aparecen junto al maestro seleccionado.</p>
<form id="service-associations" method="post" action="{{ route('provider-payments.courier-movements.servicios.associate-page') }}">
    @csrf @method('PUT')
    <input type="hidden" name="return_periodo" value="{{ $period }}"><input type="hidden" name="return_estado" value="{{ $status }}">
    <input type="hidden" name="return_q" value="{{ $search }}"><input type="hidden" name="return_cliente" value="{{ $clientId }}">
    <input type="hidden" name="return_proveedor" value="{{ $providerId }}"><input type="hidden" name="return_page" value="{{ $rows->currentPage() }}">
</form>
@if($rows->isNotEmpty() && ! $isClosed)<div class="services-actions"><button type="submit" form="service-associations">Guardar cambios de esta página</button><span class="note">Los RUT y nombres relacionados se completan al guardar.</span></div>@endif
<div class="card services-table"><table><thead><tr><th>Fecha / zona</th><th>Cliente original → maestro</th><th>Proveedor original → maestro</th><th>Servicio / usuario</th><th>Comuna / dirección</th><th>Peso</th><th>Valor final</th><th>Más datos</th></tr></thead><tbody>
@forelse($rows as $row)
    <tr @class(['needs-both' => ! $row->client_id && ! $row->provider_id, 'needs-one' => (bool) $row->client_id !== (bool) $row->provider_id])>
        <td>{{ $row->fecha_carga?->format('d-m-Y') }}<small class="original">{{ $row->zona }} · {{ $row->periodo }}</small></td>
        <td><span class="original">{{ $row->cliente_origen }}<small>RUT original: {{ $row->rut_cliente_origen ?: 'sin dato' }}</small></span>
            <select name="rows[{{ $row->id }}][client_id]" form="service-associations" aria-label="Cliente para fila {{ $row->fila_origen }}" @disabled($isClosed)><option value="">Selecciona cliente</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected($row->client_id === $client->id)>{{ $client->commercial_name }} · {{ $client->legal_name }} · {{ $client->tax_id }}</option>@endforeach</select>
        </td>
        <td><span class="original">{{ $row->transportista }}<small>{{ $row->razon_social_proveedor_origen }} · {{ $row->rut_proveedor_origen ?: 'sin RUT' }}</small></span>
            <select name="rows[{{ $row->id }}][provider_id]" form="service-associations" aria-label="Proveedor para fila {{ $row->fila_origen }}" @disabled($isClosed)><option value="">Selecciona proveedor</option>@foreach($providers as $provider)<option value="{{ $provider->id }}" @selected($row->provider_id === $provider->id)>{{ $provider->operational_name ?: $provider->legal_name }} · {{ $provider->tax_id }}</option>@endforeach</select>
        </td>
        <td>{{ $row->servicio }}<small class="original">{{ $row->usuario }}</small></td>
        <td>{{ $row->comuna_destino }}<small class="original">{{ $row->direccion }}</small></td>
        <td>{{ number_format((float) $row->peso, 3, ',', '.') }}</td><td class="amount">$ {{ number_format($row->valor_final, 0, ',', '.') }}</td>
        <td><details><summary>Ver origen</summary><p><strong>Fila:</strong> {{ $row->fila_origen }}<br><strong>Período original:</strong> {{ $row->periodo_origen }}<br><strong>Proceso:</strong> {{ $row->nombre_proceso }}<br><strong>Seguimiento:</strong> {{ $row->seguimiento_paquete ?: '—' }}<br><strong>Estado:</strong> {{ $row->estado_envio }}<br><strong>Operador:</strong> {{ $row->operador }}<br><strong>Usuario 2:</strong> {{ $row->usuario2 ?: '—' }}<br><strong>Empresa:</strong> {{ $row->empresa }}<br><strong>Número / depto:</strong> {{ $row->numero_destino ?: '—' }} / {{ $row->depto_destino ?: '—' }}<br><strong>Archivo:</strong> {{ $row->archivo_origen }}</p></details>
            @unless($isClosed)<details><summary>Editar datos</summary><div class="services-edit-fields">
                <label>Fecha carga<input type="date" name="rows[{{ $row->id }}][fecha_carga]" form="service-associations" value="{{ old('rows.'.$row->id.'.fecha_carga', $row->fecha_carga?->format('Y-m-d')) }}"></label>
                <label>Zona<input name="rows[{{ $row->id }}][zona]" form="service-associations" value="{{ old('rows.'.$row->id.'.zona', $row->zona) }}" maxlength="20"></label>
                <label>Dirección<textarea name="rows[{{ $row->id }}][direccion]" form="service-associations" maxlength="2000">{{ old('rows.'.$row->id.'.direccion', $row->direccion) }}</textarea></label>
                <label>Comuna destino<input name="rows[{{ $row->id }}][comuna_destino]" form="service-associations" value="{{ old('rows.'.$row->id.'.comuna_destino', $row->comuna_destino) }}" maxlength="150"></label>
                <label>Servicio<input name="rows[{{ $row->id }}][servicio]" form="service-associations" value="{{ old('rows.'.$row->id.'.servicio', $row->servicio) }}" maxlength="160"></label>
                <label>Peso<input type="number" step="0.001" name="rows[{{ $row->id }}][peso]" form="service-associations" value="{{ old('rows.'.$row->id.'.peso', $row->peso) }}"></label>
                <label>Valor final ($)<input type="number" step="1" min="0" name="rows[{{ $row->id }}][valor_final]" form="service-associations" value="{{ old('rows.'.$row->id.'.valor_final', $row->valor_final) }}"></label>
                <label>Usuario / repartidor<input name="rows[{{ $row->id }}][usuario]" form="service-associations" value="{{ old('rows.'.$row->id.'.usuario', $row->usuario) }}" maxlength="160"></label>
                <label>Empresa mandante<input name="rows[{{ $row->id }}][empresa]" form="service-associations" value="{{ old('rows.'.$row->id.'.empresa', $row->empresa) }}" maxlength="20"></label>
            </div></details>@endunless
        </td>
    </tr>
@empty<tr><td colspan="8" class="services-empty">No hay servicios para estos filtros.</td></tr>@endforelse
</tbody></table></div>
@if($rows->isNotEmpty() && ! $isClosed)<div class="services-actions"><button type="submit" form="service-associations">Guardar cambios de esta página</button></div>@endif
@include('provider-payments::partials.pagination', ['paginator' => $rows])
@endsection
