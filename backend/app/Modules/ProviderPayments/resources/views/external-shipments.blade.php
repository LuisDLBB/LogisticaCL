@extends('provider-payments::layout')
@section('title', 'Envíos Externos')
@push('styles')
<style>
.external-form,.external-filters{display:flex;align-items:end;gap:12px;flex-wrap:wrap;padding:14px;margin:14px 0}.external-form label,.external-filters label{display:grid;gap:5px;font-size:13px;font-weight:700}.external-form input,.external-filters input,.external-filters select{padding:9px;border:1px solid var(--line);border-radius:7px;background:#fff}.external-form p{flex-basis:100%;margin:0;color:var(--muted);font-size:13px}.external-sheet{max-height:65vh;overflow:auto;border:1px solid var(--line);background:#fff}.external-sheet table{min-width:1100px}.external-sheet th{position:sticky;top:0;white-space:nowrap}.external-sheet td{white-space:nowrap}.external-status{padding:14px;margin:12px 0;border-left:4px solid var(--turquoise-dark);background:#e8fbfa}.external-status.error{border-left-color:#b42318;background:#fff0f1;color:#842029}.external-status.warning{border-left-color:#a78328;background:#fff9df}
.import-progress{flex-basis:100%;display:flex;align-items:center;gap:12px;color:var(--muted);font-size:13px}.import-progress[hidden]{display:none}.import-progress progress{width:min(420px,100%);height:12px;accent-color:var(--turquoise-dark)}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a><p class="eyebrow">Carga Movimientos Courier</p><h1>Envíos Externos</h1>
<p class="intro">Carga los envíos que no se deben pagar al proveedor. El ID se cruza con Seguimiento_Paquete; en procesos activos, Considerar pago queda en NO y Valor en $ 0. Los procesos cerrados no se modifican.</p>
@if(session('status'))<div class="external-status" role="status">{{ session('status') }}</div>@endif
@if(session('external_issue_report_token'))<div class="external-status warning" role="alert"><strong>Filas pendientes de revisión.</strong> Los envíos válidos ya se cargaron. <a href="{{ route('provider-payments.courier-movements.externos.issues', session('external_issue_report_token')) }}">Descargar Excel con los problemas</a>.@if(session('external_import_issues'))<ul>@foreach(session('external_import_issues') as $issue)<li>Fila {{ $issue['source_row'] }}: {{ $issue['reason'] }}@if($issue['tracking']) ({{ $issue['tracking'] }})@endif</li>@endforeach</ul>@endif</div>@endif
@if(session('paid_report_token'))<div class="external-status" role="alert"><strong>Se detectaron envíos externos que ya fueron pagados.</strong> El Excel de revisión se descarga automáticamente. <a href="{{ route('provider-payments.courier-movements.externos.paid-report', session('paid_report_token')) }}">Descargar nuevamente el detalle</a>.</div>@endif
@if($paidExistingCount > 0)<div class="external-status warning" role="alert"><strong>{{ number_format($paidExistingCount, 0, ',', '.') }} envíos externos ya figuran en Maestro Pagos.</strong> <a href="{{ route('provider-payments.courier-movements.externos.paid-existing-report') }}">Descargar revisión histórica en Excel</a>. Los pagos cerrados permanecen intactos.</div>@endif
@if($errors->has('file'))<div class="external-status error" role="alert">{{ $errors->first('file') }}</div>@endif
<form class="card external-form" method="post" enctype="multipart/form-data" action="{{ route('provider-payments.courier-movements.externos.import') }}" onsubmit="this.querySelector('button[type=submit]').disabled = true; this.querySelector('.import-progress').hidden = false;">@csrf
<label>Planilla Envíos Externos (.xlsx)<input type="file" name="file" accept=".xlsx" required></label><button type="submit">Cargar planilla</button>
<div class="import-progress" role="status" aria-live="polite" hidden><progress aria-label="Cargando Envíos Externos"></progress><span>Enviando y procesando la planilla. Espera el resultado antes de cerrar esta página.</span></div>
<p>Columnas: Fecha, ID, OS Blue, Localidad Destino, Punto entrega, Cliente, Observacion. Puedes volver a cargar un ID para corregirlo mientras el período esté abierto.</p>
</form>
<form class="card external-filters" method="get">
<label>Año y mes<select name="period"><option value="">Todos</option>@foreach($periods as $option)<option value="{{ $option }}" @selected($option === $period)>{{ $option }}</option>@endforeach</select></label>
<label>Buscar<input name="q" value="{{ $search }}" placeholder="ID, OS Blue, cliente, localidad…"></label><button type="submit">Filtrar</button><a class="button" href="{{ route('provider-payments.courier-movements.externos') }}">Limpiar</a>
</form>
<p><strong>{{ number_format($rows->total(), 0, ',', '.') }}</strong> envíos encontrados. Se muestran 100 por página.</p>
<div class="external-sheet"><table><thead><tr><th>Fecha</th><th>ID / Seguimiento</th><th>OS Blue</th><th>Localidad Destino</th><th>Punto entrega</th><th>Cliente</th><th>Observación</th><th>Pago</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td>{{ $row->fecha?->format('d-m-Y') }}</td><td>{{ $row->tracking_number }}</td><td>{{ $row->external_order_number ?: '—' }}</td><td>{{ $row->destination_locality_name ?: '—' }}</td><td>{{ $row->delivery_point ?: '—' }}</td><td>{{ $row->client_name_source ?: '—' }}</td><td>{{ $row->observacion ?: '—' }}</td><td>{{ $row->exclude_provider_payment ? 'NO PAGAR' : 'Revisar' }}</td></tr>
@empty<tr><td colspan="8">No hay envíos para los filtros seleccionados.</td></tr>@endforelse
</tbody></table></div>
@include('provider-payments::partials.pagination', ['paginator' => $rows])
@if(session('paid_report_token'))<iframe hidden title="Descarga de envíos externos pagados" src="{{ route('provider-payments.courier-movements.externos.paid-report', session('paid_report_token')) }}"></iframe>@endif
@endsection
