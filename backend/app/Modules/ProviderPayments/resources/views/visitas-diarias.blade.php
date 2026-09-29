@extends('provider-payments::layout')
@section('title', 'Visitas Diarias')
@push('styles')
<style>
    .visit-tools,.visit-filters,.visit-stats,.visit-actions{display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin:12px 0}.visit-tools form,.visit-filters form,.visit-actions form{display:flex;align-items:end;gap:9px;flex-wrap:wrap}.visit-tools label,.visit-filters label,.visit-field{display:grid;gap:4px;font-size:12px;font-weight:700;color:var(--muted)}.visit-tools input,.visit-tools select,.visit-filters input,.visit-filters select,.visit-field input,.visit-field select{width:100%;padding:7px 9px;border:1px solid var(--line);border-radius:7px;background:#fff;color:var(--ink)}.visit-stats .card{flex:1 1 170px;padding:10px 14px}.visit-stats strong{display:block;font-size:18px}.visit-filters form{width:100%}.visit-filters .search{flex:1 1 210px}.visit-filters label:not(.search){flex:1 1 140px}.visit-filters button{flex:none}.visit-card{margin:10px 0;padding:12px}.visit-card.pending{border-left:4px solid #d47759;background:#fff6f2}.visit-card.high-rate:not(.pending){border-left:4px solid #dfaa38;background:#fffaf0}.visit-head{display:flex;justify-content:space-between;gap:10px;align-items:start}.visit-head strong{font-size:14px}.visit-head small{display:block;color:var(--muted);margin-top:3px}.visit-total{white-space:nowrap;font-weight:800}.visit-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:9px;margin-top:10px}.visit-grid .wide{grid-column:span 2}.visit-grid .visit-field{min-width:0}.visit-calendar{margin-top:10px;padding:8px 10px;background:#fff;border:1px solid var(--line);border-radius:7px}.visit-calendar summary{cursor:pointer;color:var(--turquoise-dark);font-size:13px;font-weight:750}.visit-calendar-scroll{overflow-x:auto;margin-top:9px}.visit-calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));min-width:560px;gap:4px}.visit-weekday{text-align:center;padding:5px;background:var(--turquoise-soft);color:#006d70;font-size:11px;font-weight:800}.visit-day{display:grid;justify-items:center;gap:3px;min-height:51px;padding:5px 2px;border:1px solid var(--line);border-radius:5px;font-size:11px;cursor:pointer}.visit-day:has(input:checked){background:var(--turquoise-soft);border-color:var(--turquoise-dark);font-weight:750}.visit-day input{margin:0;accent-color:var(--turquoise-dark)}.visit-pagination{margin:15px 0}.visit-new-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:10px;margin-top:12px}.visit-locked{border:0;margin:0;padding:0;min-width:0}@media(max-width:1120px){.visit-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:660px){.visit-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.visit-grid .wide{grid-column:span 2}}
</style>
@endpush
@section('content')
<div class="page-heading"><a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a><p class="eyebrow">Carga Movimientos Courier</p><h1>Visitas Diarias</h1></div>
<p class="intro">Carga locales, revisa las fechas propuestas y el monto por visita. Al cerrar el mes se generan los pagos y queda bloqueada la edición.</p>
@if (session('status')) <div class="card" role="status" style="border-left:4px solid var(--turquoise-dark);margin-bottom:12px">{{ session('status') }}</div> @endif
@if ($errors->any()) <div class="warning" role="alert" style="margin-bottom:12px">{{ $errors->first() }}</div> @endif

<section class="card visit-tools" aria-label="Cargar y generar visitas">
    <form method="post" enctype="multipart/form-data" action="{{ route('provider-payments.courier-movements.visitas.import') }}">@csrf
        <label>Período de carga<input type="month" name="periodo_month" value="{{ old('periodo_month', $monthClosed ? \Carbon\CarbonImmutable::create((int) substr($period,0,4),(int) substr($period,4,2),1)->addMonth()->format('Y-m') : ($period ? substr($period,0,4).'-'.substr($period,4,2) : now()->format('Y-m'))) }}" required></label>
        <label>Base_Visitas (.xlsx o .csv)<input type="file" name="file" accept=".xlsx,.csv" required></label>
        <button type="submit">Cargar planilla</button>
    </form>
    <form method="post" action="{{ route('provider-payments.courier-movements.visitas.generate') }}">@csrf
        <label>Generar desde mes anterior<input type="month" name="periodo_month" value="{{ $period ? \Carbon\CarbonImmutable::create((int) substr($period,0,4),(int) substr($period,4,2),1)->addMonth()->format('Y-m') : '' }}" required></label>
        <button type="submit">Generar mes</button>
    </form>
    @if ($periods->isNotEmpty())<form method="get" action="{{ route('provider-payments.courier-movements.visitas') }}"><label>Ver período<select name="periodo" onchange="this.form.submit()">@foreach($periods as $option)<option value="{{ $option }}" @selected($period === $option)>{{ $option }}</option>@endforeach</select></label><button type="submit">Ver</button></form>@endif
</section>

@if ($period !== '')
<div class="visit-stats">
    <div class="card"><span class="note">Proceso</span><strong>{{ $period }}-Visitas</strong></div>
    <div class="card"><span class="note">Locales</span><strong>{{ number_format((int) $summary->cantidad,0,',','.') }}</strong></div>
    <div class="card"><span class="note">Datos por revisar</span><strong>{{ (int) $summary->pendientes }}</strong></div>
    <div class="card"><span class="note">Valores diarios altos</span><strong>{{ (int) $summary->atipicos }}</strong></div>
    <div class="card"><span class="note">Total del proceso</span><strong>$ {{ number_format((int) $summary->total,0,',','.') }}</strong></div>
</div>
<div class="visit-actions">
    @if ($isClosed)
        <div class="card"><strong>Período cerrado.</strong> Los pagos ya están grabados y las visitas están bloqueadas.
        @unless($monthClosed)
        <details><summary>Reabrir con clave maestra</summary><form method="post" action="{{ route('provider-payments.courier-movements.visitas.reopen') }}">@csrf<input type="hidden" name="periodo" value="{{ $period }}"><label class="visit-field">Clave maestra<input type="password" name="password" required autocomplete="off"></label><button type="submit">Eliminar pagos de Visitas y reabrir</button></form></details>
        @endunless
        </div>
    @else
        <form method="post" action="{{ route('provider-payments.courier-movements.visitas.close') }}">@csrf<input type="hidden" name="periodo" value="{{ $period }}"><button type="submit" @disabled((int) $summary->pendientes > 0)>Grabar y cerrar proceso</button></form>
        <span class="note">Generará {{ (int) $summary->cantidad }} pagos por $ {{ number_format((int) $summary->total,0,',','.') }}. Guarda primero los cambios de cada página.</span>
    @endif
</div>

<section class="card visit-filters" aria-label="Filtrar visitas">
    <form method="get" action="{{ route('provider-payments.courier-movements.visitas') }}"><input type="hidden" name="periodo" value="{{ $period }}">
        <label>Proveedor<select name="proveedor"><option value="">Todos</option>@foreach($providers as $provider)<option value="{{ $provider->id }}" @selected($providerFilter === $provider->id)>{{ $provider->operational_name ?: $provider->legal_name }}</option>@endforeach</select></label>
        <label>Cliente<select name="cliente"><option value="">Todos</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected($clientFilter === $client->id)>{{ $client->commercial_name ?: $client->legal_name }}</option>@endforeach</select></label>
        <label>Local<select name="local"><option value="">Todos</option>@foreach($locals as $local)<option value="{{ $local }}" @selected($localFilter === $local)>{{ $local }}</option>@endforeach</select></label>
        <label class="search">Buscar y combinar datos<input type="search" name="q" value="{{ $search }}" placeholder="Proveedor, comuna, dirección, local…"></label>
        <button type="submit">Filtrar</button><a class="button" href="{{ route('provider-payments.courier-movements.visitas', ['periodo' => $period]) }}">Limpiar</a>
    </form>
</section>
<p class="note">{{ $rows->total() }} visitas encontradas · subtotal filtrado $ {{ number_format((int) $filteredTotal,0,',','.') }}. La búsqueda combina todas las palabras ingresadas.</p>
@unless ($isClosed)<details class="card" style="margin:12px 0"><summary><strong>Agregar visita adicional</strong></summary>
    <form method="post" action="{{ route('provider-payments.courier-movements.visitas.store') }}">@csrf<input type="hidden" name="periodo" value="{{ $period }}"><div class="visit-new-grid">
        <label class="visit-field">Proveedor<select name="provider_id" required><option value="">Selecciona</option>@foreach($providers as $provider)<option value="{{ $provider->id }}">{{ $provider->operational_name ?: $provider->legal_name }} · {{ $provider->tax_id }}</option>@endforeach</select></label>
        <label class="visit-field">Cliente<select name="client_id" required><option value="">Selecciona</option>@foreach($clients as $client)<option value="{{ $client->id }}">{{ $client->commercial_name ?: $client->legal_name }} · {{ $client->tax_id }}</option>@endforeach</select></label>
        <label class="visit-field">Agente / usuario<input name="agente_original" maxlength="255"></label>
        <label class="visit-field">Local<input name="local" maxlength="100" required></label>
        <label class="visit-field">Nombre del local<input name="nombre_local" maxlength="255" required></label>
        <label class="visit-field">Dirección<input name="direccion" maxlength="255" required></label>
        <label class="visit-field">Comuna<input name="comuna" maxlength="160" required></label>
        <label class="visit-field">Frecuencia<input name="frecuencia" list="visit-frequencies" maxlength="80" required></label>
        <label class="visit-field">Valor por día ($)<input type="number" name="valor_dia" min="0" required></label>
        <label class="visit-field">Zona<select name="zona"><option value="">Según proveedor</option><option>RM</option><option>Regiones</option></select></label>
    </div><datalist id="visit-frequencies">@foreach($frequencies as $frequency)<option value="{{ $frequency }}">@endforeach</datalist><p class="note">Los días se proponen según la frecuencia y podrás corregirlos en el calendario.</p><button type="submit">Agregar visita</button></form>
</details>@endunless

<form method="post" action="{{ route('provider-payments.courier-movements.visitas.update') }}" id="visit-save">@csrf<input type="hidden" name="periodo" value="{{ $period }}"><input type="hidden" name="page" value="{{ $rows->currentPage() }}">
    @unless($isClosed)<button type="submit">Guardar cambios de esta página</button>@endunless
    <fieldset class="visit-locked" @disabled($isClosed)>
    @foreach($rows as $row)
        @php($key = 'rows['.$loop->index.']')
        @php($days = old('rows.'.$loop->index.'.dias', $row->dias ?? []))
        <article class="card visit-card {{ $row->provider_id && $row->client_id && trim($row->direccion) !== '' ? '' : 'pending' }} {{ $row->valor_dia >= 10000 ? 'high-rate' : '' }}" data-visit-card>
            <input type="hidden" name="{{ $key }}[id]" value="{{ $row->id }}">
            <div class="visit-head"><div><strong>{{ $row->provider?->operational_name ?: ($row->nombre_pila_proveedor_origen ?: 'Proveedor pendiente') }} · {{ $row->local }} / {{ $row->nombre_local }}</strong><small>Origen: {{ $row->rut_proveedor_origen ?: 'sin RUT' }} · {{ $row->comerciante_pila_origen ?: $row->razon_social_cliente_origen }} · {{ $row->estatus_origen ?: 'sin estatus' }}
                @if(trim($row->direccion)==='') · Falta dirección @endif
                @if($row->valor_dia >= 10000) · Revisar valor diario alto @endif
            </small></div><span class="visit-total">$ <span data-visit-total>{{ number_format($row->total_mensual,0,',','.') }}</span></span></div>
            <div class="visit-grid">
                <label class="visit-field wide">Proveedor asociado<select name="{{ $key }}[provider_id]"><option value="">Por asociar</option>@foreach($providers as $provider)<option value="{{ $provider->id }}" @selected((int) old('rows.'.$loop->parent->index.'.provider_id',$row->provider_id) === $provider->id)>{{ $provider->operational_name ?: $provider->legal_name }} · {{ $provider->tax_id }}</option>@endforeach</select></label>
                <label class="visit-field wide">Cliente asociado<select name="{{ $key }}[client_id]"><option value="">Por asociar</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected((int) old('rows.'.$loop->parent->index.'.client_id',$row->client_id) === $client->id)>{{ $client->commercial_name ?: $client->legal_name }} · {{ $client->tax_id }}</option>@endforeach</select></label>
                <label class="visit-field">Zona<select name="{{ $key }}[zona]"><option value="">Según proveedor</option><option value="RM" @selected(old('rows.'.$loop->index.'.zona',$row->zona)==='RM')>RM</option><option value="Regiones" @selected(old('rows.'.$loop->index.'.zona',$row->zona)==='Regiones')>Regiones</option></select></label>
                <label class="visit-field">Valor por día ($)<input type="number" min="0" name="{{ $key }}[valor_dia]" value="{{ old('rows.'.$loop->index.'.valor_dia',$row->valor_dia) }}" data-visit-rate required></label>
                <label class="visit-field">Agente / usuario<input name="{{ $key }}[agente_original]" value="{{ old('rows.'.$loop->index.'.agente_original',$row->agente_original) }}"></label>
                <label class="visit-field">Local<input name="{{ $key }}[local]" value="{{ old('rows.'.$loop->index.'.local',$row->local) }}" required></label>
                <label class="visit-field">Nombre del local<input name="{{ $key }}[nombre_local]" value="{{ old('rows.'.$loop->index.'.nombre_local',$row->nombre_local) }}" required></label>
                <label class="visit-field wide">Dirección<input name="{{ $key }}[direccion]" value="{{ old('rows.'.$loop->index.'.direccion',$row->direccion) }}" required></label>
                <label class="visit-field">Comuna<input name="{{ $key }}[comuna]" value="{{ old('rows.'.$loop->index.'.comuna',$row->comuna) }}" required></label>
                <label class="visit-field">Frecuencia<input name="{{ $key }}[frecuencia]" list="visit-frequencies" value="{{ old('rows.'.$loop->index.'.frecuencia',$row->frecuencia) }}" required></label>
            </div>
            <details class="visit-calendar"><summary>Calendario de visitas · <span data-visit-count>{{ count($days) }}</span> días</summary><div class="visit-calendar-scroll"><div class="visit-calendar-grid" aria-label="Días de {{ $period }} para {{ $row->nombre_local }}">
                @foreach(['Lu','Ma','Mi','Ju','Vi','Sa','Do'] as $weekday)<div class="visit-weekday">{{ $weekday }}</div>@endforeach
                @for($blank=0;$blank<$firstWeekdayOffset;$blank++)<span aria-hidden="true"></span>@endfor
                @for($day=1;$day<=$daysInMonth;$day++)<label class="visit-day"><span>{{ $dayLabels[$day] }}</span><input type="checkbox" name="{{ $key }}[dias][]" value="{{ $day }}" @checked(in_array($day,array_map('intval',$days),true))></label>@endfor
            </div></div></details>
        </article>
    @endforeach
    </fieldset>
    @unless($isClosed)<button type="submit">Guardar cambios de esta página</button>@endunless
</form>
@include('provider-payments::partials.pagination', ['paginator' => $rows])
@else
<div class="card"><p class="note">Aún no hay visitas cargadas. Selecciona el período y sube Base_Visitas para comenzar.</p></div>
@endif
@endsection
@push('scripts')
<script>
for (const card of document.querySelectorAll('[data-visit-card]')) {
    const refresh = () => {
        const count = card.querySelectorAll('.visit-day input:checked').length;
        const rate = Number(card.querySelector('[data-visit-rate]').value || 0);
        card.querySelector('[data-visit-count]').textContent = count;
        card.querySelector('[data-visit-total]').textContent = new Intl.NumberFormat('es-CL').format(count * rate);
    };
    card.addEventListener('input', refresh);
    card.addEventListener('change', refresh);
}
</script>
@endpush
