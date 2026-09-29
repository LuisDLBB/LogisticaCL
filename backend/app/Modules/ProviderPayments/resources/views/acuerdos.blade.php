@extends('provider-payments::layout')
@section('title', 'Acuerdos')
@section('content')
<style>
.agreements-actions{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(0,1.1fr) minmax(0,.75fr);gap:10px;align-items:stretch;margin:8px 0}
.agreements-page-heading{padding-top:var(--agreements-heading-offset,30px)}
.agreements-filters,.agreements-metrics{display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin:8px 0}
.agreements-actions label,.agreements-filters label{display:grid;gap:2px;font-size:11px;font-weight:750}
.agreements-actions input,.agreements-filters input,.agreements-filters select{padding:5px 7px;border:1px solid var(--line);border-radius:7px;background:white}
.agreements-actions form.card{display:flex;align-items:end;justify-content:space-between;gap:8px;min-width:0;margin:0;padding:7px 9px;min-height:58px}
.agreements-actions form.card label{flex:1;min-width:0}
.agreements-actions form.card input,.agreements-actions form.card select{min-height:30px;height:30px}
.agreements-actions form.card input[type=month],.agreements-actions form.card select{width:100%;min-width:0}
.agreements-actions form.card button{min-height:30px;padding:5px 10px;white-space:nowrap}
.agreements-actions form.card input[type=file]{width:100%;min-width:0;max-width:none;padding:2px 5px}
.agreements-metrics{align-items:stretch;margin:8px 0 12px}.agreements-metrics .card{display:flex;align-items:center;justify-content:space-between;gap:8px;min-width:0;min-height:48px;flex:1 1 205px;padding:7px 10px}.agreements-metrics small,.agreements-metrics strong{display:block}.agreements-metrics small{color:var(--muted);font-size:11px}.agreements-metrics strong{font-size:16px;white-space:nowrap}
.agreements-block{margin:15px 0;padding:16px}.agreements-block h2{margin:0 0 10px;font-size:17px}
.agreements-review{padding:7px 14px 14px}.agreements-review h2{margin:0 0 8px;line-height:1.3}
.agreements-review .agreements-filters{display:grid;grid-template-columns:minmax(120px,1fr) minmax(145px,1.1fr) minmax(145px,1.1fr) minmax(100px,.7fr) minmax(125px,.9fr) minmax(145px,1fr) auto auto;gap:8px;align-items:end;margin:0 0 8px}
.agreements-review .agreements-filters label{min-width:0}.agreements-review .agreements-filters input,.agreements-review .agreements-filters select{width:100%;min-width:0;height:36px}.agreements-review .agreements-filters .button,.agreements-review .agreements-filters button{min-height:36px;white-space:nowrap}.agreements-review>.agreements-note{margin:8px 0 12px}
.agreements-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px;max-width:760px}.agreements-grid .weekday{text-align:center;font-weight:800;color:#007f82;font-size:12px}
.agreements-day{min-height:70px;display:flex;flex-direction:column;justify-content:center;align-items:center;border:1px solid var(--line);border-radius:7px;font-size:12px;cursor:pointer}.agreements-day.holiday{background:#fff0e6;border-color:#ecac85}.agreements-day small{color:var(--muted)}
.agreements-rule{display:grid;grid-template-columns:minmax(180px,2fr) 130px 100px minmax(180px,1fr) auto;gap:8px;align-items:center;padding:7px 0;border-bottom:1px solid var(--line);font-size:12px}.agreements-rule input[type=number],.agreements-rule select{width:100%;padding:6px;border:1px solid var(--line);border-radius:6px}.agreements-weekdays{display:flex;flex-wrap:wrap;gap:6px}.agreements-weekdays label{white-space:nowrap}.agreements-list{max-height:350px;overflow:auto}
.agreements-tables{display:grid;grid-template-columns:1fr 1fr;gap:15px}.agreements-tables table,.agreements-records table{width:100%;border-collapse:collapse;font-size:12px}.agreements-tables th,.agreements-tables td,.agreements-records th,.agreements-records td{text-align:left;padding:7px;border-bottom:1px solid var(--line);vertical-align:top}.agreements-tables td:last-child,.agreements-records .amount{text-align:right;white-space:nowrap}
.agreements-records{overflow:auto}.agreements-records table{min-width:1280px}.agreements-records select,.agreements-records input{width:100%;min-width:85px;padding:6px;border:1px solid var(--line);border-radius:6px;background:white}.agreements-records small{display:block;color:var(--muted)}.agreements-records tr.pending{background:#fff3ec}.agreements-records .narrow{width:85px}.agreements-records .wide{min-width:170px}.agreements-note{color:var(--muted);font-size:12px}.agreements-alert{padding:12px 15px;margin:12px 0;border-left:4px solid #008486;background:#e2f6f4}.agreements-alert.error{border-color:#b32924;background:#fff0ef}
.agreements-records tbody td{vertical-align:middle}.agreements-records tbody td:nth-child(n+5):nth-child(-n+10),.agreements-records thead th:nth-child(n+5):nth-child(-n+10){text-align:center}.agreements-records .agreements-mandante{min-width:160px}.agreements-records .agreements-mandante input{min-width:145px}.agreements-records input[type=number]{min-width:0;text-align:center}.agreements-records .narrow{width:95px}.agreements-records .wide{min-width:210px}.agreements-records .agreements-party{display:grid;grid-template-rows:minmax(32px,auto) 16px 36px;gap:2px;align-items:center}.agreements-records .agreements-party strong{align-self:end;line-height:1.25;overflow-wrap:anywhere}.agreements-records .agreements-party small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.agreements-records .agreements-party select{height:36px}
.agreements-close{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:11px 14px;margin:11px 0}.agreements-close p{margin:0}.agreements-close details{width:100%}.agreements-close details form{display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin-top:10px}.agreements-close details label{display:grid;gap:4px;font-size:12px;font-weight:700}.agreements-close details input{padding:7px;border:1px solid var(--line);border-radius:7px}.agreements-records input:disabled,.agreements-records select:disabled{background:#edf3f3;color:#49636a}
@media(max-width:1250px){.agreements-actions{grid-template-columns:repeat(2,minmax(0,1fr))}.agreements-actions form.card:first-child{grid-column:1/-1}}
@media(max-width:1050px){.agreements-actions{grid-template-columns:minmax(0,1fr)}.agreements-actions form.card:first-child{grid-column:auto}}
@media(max-width:900px){.agreements-tables{grid-template-columns:1fr}.agreements-rule{grid-template-columns:1fr 1fr}.agreements-grid{gap:3px}.agreements-day{min-height:57px}}
@media(max-width:700px){.agreements-actions form.card{flex-wrap:wrap}.agreements-actions form.card button{width:100%}.agreements-metrics .card{flex-basis:175px}}
@media(max-width:1350px){.agreements-review .agreements-filters{grid-template-columns:repeat(4,minmax(0,1fr))}}
@media(max-width:700px){.agreements-review .agreements-filters{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<div class="page-heading agreements-page-heading"><a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a><p class="eyebrow">Carga Movimientos Courier</p><h1>Acuerdos</h1></div>
<p class="intro">Control mensual de acuerdos: calendario, días por servicio, inasistencias, adicionales y montos. Los datos se conservan en la base de Acuerdos para su revisión.</p>
@if(session('status'))<div class="agreements-alert" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="agreements-alert error" role="alert">{{ $errors->first() }}</div>@endif
<div class="agreements-actions">
    <form class="card agreements-actions" method="post" enctype="multipart/form-data" action="{{ route('provider-payments.courier-movements.acuerdos.import') }}">@csrf
        <label>Plantilla Base_Acuerdos.xlsx<input type="file" name="file" accept=".xlsx" required></label><button type="submit">Cargar plantilla</button>
    </form>
    <form class="card agreements-actions" method="post" action="{{ route('provider-payments.courier-movements.acuerdos.generate') }}">@csrf
        <label>Generar mes desde el anterior<input type="month" name="periodo" value="{{ $period ? \Carbon\CarbonImmutable::create((int) substr($period, 0, 4), (int) substr($period, 4, 2), 1)->addMonth()->format('Y-m') : '' }}" required></label><button type="submit">Generar borrador</button>
    </form>
    @if($periods->isNotEmpty())<form class="card agreements-actions" method="get" action="{{ route('provider-payments.courier-movements.acuerdos') }}">
        <label>Período<select name="periodo">@foreach($periods as $item)<option value="{{ $item->periodo }}" @selected($period === $item->periodo)>{{ $item->periodo }}</option>@endforeach</select></label><button type="submit">Ver</button>
    </form>@endif
</div>
@if($period)
<div class="agreements-metrics">
    <div class="card"><small>Proceso</small><strong>{{ $period }}-Acuerdos</strong></div>
    <div class="card"><small>Acuerdos</small><strong>{{ number_format($summary->registros, 0, ',', '.') }}</strong></div>
    <div class="card"><small>Total calculado</small><strong>$ {{ number_format($summary->monto, 0, ',', '.') }}</strong></div>
    <div class="card"><small>Clientes por revisar</small><strong>{{ number_format($summary->clientes_pendientes, 0, ',', '.') }}</strong></div>
    <div class="card"><small>Proveedores por revisar</small><strong>{{ number_format($summary->proveedores_pendientes, 0, ',', '.') }}</strong></div>
</div>
@if((int) $summary->registros > 0)
    @if($isClosed)
        <div class="card agreements-close"><p><strong>Período {{ $period }} cerrado.</strong> Los acuerdos están grabados en pagos y no se pueden modificar.</p>
            @unless($monthClosed)
            <details><summary>Reabrir con clave maestra</summary><form method="post" action="{{ route('provider-payments.courier-movements.acuerdos.reopen') }}" onsubmit="return confirm('¿Eliminar solo los pagos de Acuerdos de {{ $period }} y reabrir el período para corregirlo?')">@csrf<input type="hidden" name="periodo" value="{{ $period }}"><label>Clave maestra<input type="password" name="password" required autocomplete="off"></label><button type="submit">Eliminar pagos de Acuerdos y reabrir</button></form></details>
            @endunless
        </div>
    @else
        <form class="card agreements-close" method="post" action="{{ route('provider-payments.courier-movements.acuerdos.close') }}" onsubmit="return confirm('¿Cerrar Acuerdos de {{ $period }} y grabar {{ $summary->registros }} pagos? Después necesitarás la clave maestra para corregirlos.')">@csrf<input type="hidden" name="periodo" value="{{ $period }}"><p>Al cerrar, se grabarán {{ number_format($summary->registros, 0, ',', '.') }} pagos por $ {{ number_format($summary->monto, 0, ',', '.') }} y el período quedará bloqueado.@if((int) $summary->clientes_pendientes + (int) $summary->proveedores_pendientes > 0) Completa las asociaciones de clientes y proveedores.@endif @if((int) $summary->zonas_pendientes > 0) Faltan {{ $summary->zonas_pendientes }} zonas por indicar.@endif @if((int) $summary->mandantes_pendientes > 0) Faltan {{ $summary->mandantes_pendientes }} empresas mandantes.@endif</p><button type="submit" @disabled((int) $summary->clientes_pendientes + (int) $summary->proveedores_pendientes + (int) $summary->zonas_pendientes + (int) $summary->mandantes_pendientes > 0)>Cerrar proceso Acuerdos</button></form>
    @endif
@endif
@include('provider-payments::partials.acuerdos-floating-panels')
<section class="card agreements-block agreements-review"><h2>Revisar acuerdos</h2>
    <form class="agreements-filters" method="get" action="{{ route('provider-payments.courier-movements.acuerdos') }}"><input type="hidden" name="periodo" value="{{ $period }}">
        <label>Servicio<select name="servicio"><option value="">Todos</option>@foreach($byService as $item)<option value="{{ $item->servicio }}" @selected($service === $item->servicio)>{{ $item->servicio }}</option>@endforeach</select></label>
        <label>Proveedor<select name="proveedor"><option value="">Todos</option>@foreach($providers as $provider)<option value="{{ $provider->id }}" @selected($providerId === $provider->id)>{{ $provider->operational_name ?: $provider->legal_name }}</option>@endforeach</select></label>
        <label>Cliente<select name="cliente"><option value="">Todos</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected($clientId === $client->id)>{{ $client->commercial_name }}</option>@endforeach</select></label>
        <label>Asociación<select name="estado"><option value="todos" @selected($status === 'todos')>Todas</option><option value="pendientes" @selected($status === 'pendientes')>Por revisar</option><option value="completos" @selected($status === 'completos')>Completas</option></select></label>
        <label>Empresa mandante<select name="mandante"><option value="">Todas</option>@foreach($mandantes as $company)<option value="{{ $company }}" @selected($mandante === $company)>{{ $company }}</option>@endforeach</select></label>
        <label>Buscar<input type="search" name="q" value="{{ $search }}" placeholder="Cliente, proveedor, marca…"></label><button type="submit">Filtrar</button><a class="button" href="{{ route('provider-payments.courier-movements.acuerdos', ['periodo' => $period]) }}">Limpiar</a>
    </form>
    <p class="agreements-note">{{ $rows->total() }} acuerdos encontrados. Las filas color durazno requieren revisar cliente o proveedor. El total se calcula como (días calendario − inasistencias + adicionales) × costo × factor.</p>
    <form id="agreements-save" method="post" action="{{ route('provider-payments.courier-movements.acuerdos.update') }}">@csrf<input type="hidden" name="periodo" value="{{ $period }}"><input type="hidden" name="page" value="{{ $rows->currentPage() }}"><input type="hidden" name="estado" value="{{ $status }}"><input type="hidden" name="servicio" value="{{ $service }}"><input type="hidden" name="mandante" value="{{ $mandante }}"><input type="hidden" name="q" value="{{ $search }}"><input type="hidden" name="proveedor" value="{{ $providerId }}"><input type="hidden" name="cliente" value="{{ $clientId }}"></form>
    @if($rows->isNotEmpty() && ! $isClosed)<button type="submit" form="agreements-save">Guardar cambios de esta página</button>@endif
    <datalist id="agreement-mandantes">@foreach($mandanteOptions as $company)<option value="{{ $company }}"></option>@endforeach</datalist>
    <div class="agreements-records"><table><thead><tr><th>Proveedor original → asociado</th><th>Cliente original → asociado</th><th>Zona</th><th>Empresa mandante</th><th>Servicio / marca</th><th>Costo</th><th>Días</th><th>Inasist.</th><th>Adicionales</th><th>Factor</th><th>Cantidad</th><th>Total</th></tr></thead><tbody>
        @forelse($rows as $row)<tr @class(['pending' => ! $row->provider_id || ! $row->client_id || (! $row->zona && ! $zoneByProvider->get($row->provider_id)) || ! $row->empresa_mandante])>
            <td class="wide"><div class="agreements-party"><strong>{{ $row->proveedor_origen }}</strong><small>{{ $row->rut_proveedor_origen ?: 'Sin RUT' }}</small><select name="rows[{{ $row->id }}][provider_id]" form="agreements-save" aria-label="Proveedor acuerdo {{ $row->id }}" @disabled($isClosed)><option value="">Por asociar</option>@foreach($providers as $provider)<option value="{{ $provider->id }}" @selected($row->provider_id === $provider->id)>{{ $provider->operational_name ?: $provider->legal_name }} · {{ $provider->tax_id }}</option>@endforeach</select></div></td>
            <td class="wide"><div class="agreements-party"><strong>{{ $row->comerciante_pila_origen ?: $row->razon_social_cliente_origen }}</strong><small>{{ $row->rut_cliente_origen ?: 'Sin RUT' }}</small><select name="rows[{{ $row->id }}][client_id]" form="agreements-save" aria-label="Cliente acuerdo {{ $row->id }}" @disabled($isClosed)><option value="">Por asociar</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected($row->client_id === $client->id)>{{ $client->commercial_name }} · {{ $client->tax_id }}</option>@endforeach</select></div></td>
            <td><select name="rows[{{ $row->id }}][zona]" form="agreements-save" aria-label="Zona acuerdo {{ $row->id }}" @disabled($isClosed)><option value="" @selected(! $row->zona)>{{ $zoneByProvider->get($row->provider_id) ? 'Auto: '.$zoneByProvider->get($row->provider_id) : 'Por definir' }}</option><option value="RM" @selected($row->zona === 'RM')>RM</option><option value="Regiones" @selected($row->zona === 'Regiones')>Regiones</option></select></td>
            <td class="agreements-mandante"><input type="text" list="agreement-mandantes" maxlength="100" name="rows[{{ $row->id }}][empresa_mandante]" form="agreements-save" aria-label="Empresa mandante acuerdo {{ $row->id }}" placeholder="Sin informar" value="{{ old('rows.'.$row->id.'.empresa_mandante', $row->empresa_mandante) }}" @disabled($isClosed)></td>
            <td>{{ $row->servicio }}<small>{{ $row->marca }} · {{ $row->agencia }} · {{ $row->tipo_servicio }}</small></td>
            <td class="narrow"><input type="number" min="0" name="rows[{{ $row->id }}][costo]" form="agreements-save" value="{{ old('rows.'.$row->id.'.costo', $row->costo) }}" @disabled($isClosed)></td><td>{{ $row->dias_calendario }}</td>
            <td class="narrow"><input type="number" min="0" max="366" name="rows[{{ $row->id }}][inasistencias]" form="agreements-save" value="{{ old('rows.'.$row->id.'.inasistencias', $row->inasistencias) }}" @disabled($isClosed)></td>
            <td class="narrow"><input type="number" min="0" max="366" name="rows[{{ $row->id }}][adicionales]" form="agreements-save" value="{{ old('rows.'.$row->id.'.adicionales', $row->adicionales) }}" @disabled($isClosed)></td>
            <td class="narrow"><input type="number" min="1" max="1000" name="rows[{{ $row->id }}][factor]" form="agreements-save" value="{{ old('rows.'.$row->id.'.factor', $row->factor) }}" @disabled($isClosed)></td><td>{{ $row->cantidad }}</td><td class="amount"><strong>$ {{ number_format($row->total, 0, ',', '.') }}</strong></td>
        </tr>@empty<tr><td colspan="12">Sin acuerdos para estos filtros.</td></tr>@endforelse
    </tbody></table></div>
    @if($rows->isNotEmpty() && ! $isClosed)<p><button type="submit" form="agreements-save">Guardar cambios de esta página</button></p>@endif
    @include('provider-payments::partials.pagination', ['paginator' => $rows])
</section>
@else<p class="agreements-note">Carga la planilla inicial para comenzar. Luego podrás generar y ajustar los meses siguientes.</p>@endif
@endsection
