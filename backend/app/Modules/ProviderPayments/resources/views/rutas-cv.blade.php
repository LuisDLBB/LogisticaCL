@extends('provider-payments::layout')
@section('title', 'Rutas CV')
@push('styles')
<style>
    .cv-tools{display:flex;flex-wrap:wrap;align-items:end;gap:12px;margin:16px 0}.cv-tools form{display:flex;flex-wrap:wrap;align-items:end;gap:10px}.cv-tools label,.cv-field{display:grid;gap:4px;font-size:12px;font-weight:700;color:var(--muted)}.cv-tools input,.cv-tools select,.cv-field input,.cv-field select,.cv-field textarea{padding:7px 9px;border:1px solid var(--line);border-radius:7px;background:white;color:var(--ink)}
    .cv-stats{display:flex;flex-wrap:wrap;gap:10px;margin:15px 0}.cv-stats .card{min-width:170px;padding:12px 16px}.cv-stats strong{display:block;font-size:19px;color:var(--ink)}
    .cv-card{margin:12px 0;padding:14px}.cv-card.needs-provider{border-left:4px solid #d47759;background:#fff6f2}.cv-card.provider-pending{border-left:4px solid #e3aa3b;background:#fffaf0}.cv-head{display:flex;align-items:start;justify-content:space-between;gap:16px;flex-wrap:wrap}.cv-head strong{font-size:15px}.cv-head .note{font-size:12px}.cv-amount{font-weight:800;white-space:nowrap}.cv-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;margin-top:12px}.cv-fields .wide{grid-column:span 2}.cv-fields input,.cv-fields select{width:100%}.cv-fields select:disabled,.cv-add-grid select:disabled{background:#edf3f3;color:#49636a}.cv-days{display:grid;grid-template-columns:repeat(auto-fill,minmax(76px,1fr));gap:4px;margin-top:12px}.cv-day{display:grid;place-items:center;gap:2px;min-width:76px;padding:4px 2px;border:1px solid var(--line);border-radius:5px;font-size:11px;cursor:pointer}.cv-day:has(input:checked){background:var(--turquoise-soft);border-color:var(--turquoise-dark);color:#005f62;font-weight:800}.cv-day.extra{background:#fff7e9}.cv-day.extra:has(input:checked){background:#f9df9c}.cv-day input{margin:0;accent-color:var(--turquoise-dark)}.cv-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:12px}.cv-warning{color:#a34027;font-weight:800}.cv-add-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}.cv-add-grid .cv-field input,.cv-add-grid .cv-field select{width:100%}.cv-actions{display:flex;align-items:center;gap:12px;margin:14px 0}.cv-actions button{min-width:170px}.cv-reset{min-height:28px;padding:4px 8px;background:transparent;color:var(--turquoise-dark);border:1px solid var(--line);font-size:11px}.cv-reset:hover{background:var(--turquoise-soft);color:var(--turquoise-dark)}.cv-weekdays{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0}.cv-weekdays label{display:flex;align-items:center;gap:4px;padding:6px 8px;border:1px solid var(--line);border-radius:6px;font-size:12px}@media(max-width:600px){.cv-days{grid-template-columns:repeat(4,minmax(72px,1fr))}.cv-fields .wide{grid-column:span 1}}
    .cv-card .cv-fields{grid-template-columns:minmax(0,1.3fr) minmax(0,.85fr) minmax(0,1.3fr) minmax(0,.65fr) minmax(0,1fr) minmax(0,.85fr)}.cv-card .cv-fields .cv-field{min-width:0}.cv-card .cv-fields input,.cv-card .cv-fields select{min-width:0}.cv-locked-fields{border:0;margin:0;padding:0;min-width:0}.cv-close-action{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:14px 0}.cv-close-action p{margin:0}@media(max-width:1000px){.cv-card .cv-fields{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:640px){.cv-card .cv-fields{grid-template-columns:repeat(2,minmax(0,1fr))}}
    .cv-calendar{margin-top:12px;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:#fff}.cv-calendar summary{cursor:pointer;color:var(--turquoise-dark);font-weight:700}.cv-calendar-scroll{overflow-x:auto;margin-top:12px}.cv-calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:5px;min-width:540px}.cv-calendar-weekday{text-align:center;padding:5px 0;background:var(--turquoise-soft);color:var(--turquoise-dark);font-size:12px;font-weight:800}.cv-calendar .cv-day{min-width:0;min-height:56px}.cv-calendar .cv-day span{font-size:12px}.cv-calendar-empty{min-height:56px}.cv-extra-days{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:12px}.cv-extra-days .cv-day{min-width:76px}.cv-calendar-absence{max-width:160px;margin-top:12px}.cv-calendar-absence input{width:100%}
    .cv-head-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.cv-delete{min-height:30px;padding:5px 9px;border:1px solid #b85252;border-radius:6px;background:white;color:#9d2929;font-size:12px;font-weight:700}.cv-delete:hover{background:#fff0f0;color:#842020}
    .cv-generate{max-width:650px}.cv-source-picker{flex-basis:100%;padding:9px;border:1px solid var(--line);border-radius:7px;background:#fff}.cv-source-list{display:grid;gap:4px;max-height:180px;overflow:auto;margin-top:7px}.cv-source-list label{display:flex;align-items:center;gap:8px;padding:4px;font-size:12px;font-weight:400;color:var(--ink)}.cv-source-list input{accent-color:#a13c3c}
</style>
@endpush
@section('content')
<div class="page-heading"><a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a><p class="eyebrow">Carga Movimientos Courier</p><h1>Rutas CV</h1></div>
<p class="intro">Rutas fijas de Cruz Verde. Al generar un período, sus días se marcan según la frecuencia. Puedes cambiar proveedor, corregir días y eliminar rutas del borrador antes de cerrarlo.</p>
@if (session('status')) <div class="card" role="status" style="border-left:4px solid var(--turquoise-dark);margin-bottom:14px">{{ session('status') }}</div> @endif
@if ($errors->any()) <div class="warning" role="alert" style="margin-bottom:14px">{{ $errors->first() }}</div> @endif

<div class="card cv-tools">
    <form method="post" enctype="multipart/form-data" action="{{ route('provider-payments.courier-movements.rutas-cv.import') }}">
        @csrf <label>Base Ruta CV (.xlsx)<input type="file" name="file" accept=".xlsx" required></label><button type="submit">Cargar planilla</button>
    </form>
    <form method="post" action="{{ route('provider-payments.courier-movements.rutas-cv.generate') }}" class="cv-generate" data-cv-generate data-source-url="{{ route('provider-payments.courier-movements.rutas-cv.source-routes') }}">
        @csrf <label>Generar período AAAAMM<input type="month" name="periodo_month" value="{{ old('periodo_month', $selectedPeriod ? \Carbon\CarbonImmutable::create((int) substr($selectedPeriod,0,4),(int) substr($selectedPeriod,4,2),1)->addMonth()->format('Y-m') : '') }}" required></label>
        <button type="submit">Generar mes</button>
        <div class="cv-source-picker"><strong>Rutas de la base anterior</strong><p class="note" data-cv-source-message aria-live="polite">Buscando rutas para el período seleccionado…</p><div class="cv-source-list" data-cv-source-list></div><p class="note">Marca «No copiar» en las rutas que quieras quitar del nuevo mes. La base anterior se conserva.</p></div>
    </form>
    @if ($periods->isNotEmpty())
    <form method="get" action="{{ route('provider-payments.courier-movements.rutas-cv') }}">
        <label>Ver período<select name="periodo" onchange="this.form.submit()">@foreach ($periods as $period)<option value="{{ $period->periodo }}" @selected($selectedPeriod === $period->periodo)>{{ $period->periodo }}</option>@endforeach</select></label><button type="submit">Ver</button>
    </form>
    @endif
</div>
<details class="card" style="margin:12px 0"><summary><strong>Agregar nueva frecuencia</strong></summary>
    <form method="post" action="{{ route('provider-payments.courier-movements.rutas-cv.frequencies.store') }}" style="margin-top:12px">
        @csrf <input type="hidden" name="periodo" value="{{ $selectedPeriod }}">
        <label class="cv-field" style="max-width:320px">Nombre de la frecuencia<input name="name" maxlength="80" placeholder="Por ejemplo: Ruta Ma y Ju" required></label>
        <p class="note" style="margin:10px 0 4px">Días habituales para proponer al generar un mes. Déjalos vacíos si la ruta no tiene días fijos.</p>
        <div class="cv-weekdays">@foreach ([1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'] as $weekday=>$label)<label><input type="checkbox" name="weekdays[]" value="{{ $weekday }}">{{ $label }}</label>@endforeach</div>
        <button type="submit">Guardar frecuencia</button>
    </form>
</details>

@if ($routes->isNotEmpty())
<div class="cv-stats"><div class="card"><span class="note">Período</span><strong>{{ $selectedPeriod }}</strong></div><div class="card"><span class="note">Rutas</span><strong>{{ $routes->count() }}</strong></div><div class="card"><span class="note">Proveedores por revisar</span><strong>{{ $unmatched }}</strong></div><div class="card"><span class="note">Total proyectado</span><strong>$ {{ number_format($total,0,',','.') }}</strong></div></div>
@if ($isClosed)
<div class="card cv-close-action"><p><strong>Período cerrado.</strong> Estas rutas ya están grabadas en pagos y no se pueden modificar.</p>
@unless($monthClosed)
<details><summary>Reabrir con clave maestra</summary><form method="post" action="{{ route('provider-payments.courier-movements.rutas-cv.reopen') }}" class="cv-close-action">@csrf<input type="hidden" name="periodo" value="{{ $selectedPeriod }}"><label class="cv-field">Clave maestra<input type="password" name="password" required autocomplete="off"></label><button type="submit">Eliminar pagos de Ruta CV y reabrir</button></form></details>
@endunless
</div>
@else
<form method="post" action="{{ route('provider-payments.courier-movements.rutas-cv.close') }}" class="card cv-close-action">@csrf<input type="hidden" name="periodo" value="{{ $selectedPeriod }}"><p>Al cerrar, se grabarán {{ $routes->count() }} pagos por $ {{ number_format($total,0,',','.') }} y el período quedará bloqueado.</p><button type="submit" @disabled($unmatched > 0)>Cerrar período y generar pagos</button></form>
@endif
<p class="note">Abre el calendario de cada ruta para revisar sus días. Se ordenan de lunes a domingo según el período; los días adicionales quedan disponibles al final.</p>
@unless($isClosed)<p><a href="{{ route('provider-payments.maintainers.proveedores', ['return_period' => $selectedPeriod]) }}">¿No encuentras al proveedor? Créalo y vuelve a este período</a> <span class="note">Guarda primero cualquier cambio pendiente en las rutas.</span></p>@endunless
<form method="post" action="{{ route('provider-payments.courier-movements.rutas-cv.update') }}" id="cv-save-form">
    @csrf <input type="hidden" name="periodo" value="{{ $selectedPeriod }}">
    <fieldset class="cv-locked-fields" @disabled($isClosed)>
    @unless ($isClosed)
    <div class="cv-actions"><button type="submit">Guardar todos los cambios</button><span class="note">Los cambios de este período se guardan juntos.</span></div>
    @endunless
    @foreach ($routes as $route)
    @php
        $name = 'rows['.$loop->index.']';
        $selectedDays = old('rows') ? old('rows.'.$loop->index.'.dias', []) : ($route->dias ?? []);
        $selectedProviderId = old('rows.'.$loop->index.'.provider_id', $route->provider_id);
        $selectedProvider = $providers->firstWhere('id', (int) $selectedProviderId);
        $providerTitle = $selectedProvider?->operational_name ?: ($selectedProvider?->legal_name ?: 'Proveedor por asociar');
        $rowMode = old('rows.'.$loop->index.'.tipo_cobro', $route->tipo_cobro);
    @endphp
    <article class="card cv-card {{ $route->provider_id ? '' : 'needs-provider' }}" data-cv-route data-cv-provider-group data-cv-saved-provider-id="{{ $route->provider_id ?? '' }}">
        <input type="hidden" name="{{ $name }}[id]" value="{{ $route->id }}">
        <input type="hidden" name="{{ $name }}[provider_id]" value="{{ $selectedProviderId }}" data-cv-provider-id>
        <div class="cv-head"><div><strong><span data-cv-provider-title>{{ $providerTitle }}</span> · {{ $route->detalle_ruta }}</strong><small style="display:block">Facturador en la planilla: {{ $route->facturador }}</small></div><div class="cv-head-actions"><span class="cv-amount">$ <span data-cv-total>{{ number_format($route->total_mensual,0,',','.') }}</span></span>@unless($isClosed)<button type="submit" name="delete_route_id" value="{{ $route->id }}" class="cv-delete" formnovalidate onclick="return confirm('¿Guardar los cambios de las otras rutas y eliminar esta ruta del período?');">Eliminar ruta</button>@endunless</div></div>
        <input type="hidden" name="{{ $name }}[facturador]" value="{{ old('rows.'.$loop->index.'.facturador', $route->facturador) }}">
        <input type="hidden" name="{{ $name }}[usuario]" value="{{ old('rows.'.$loop->index.'.usuario', $route->usuario) }}">
        <input type="hidden" name="{{ $name }}[detalle_ruta]" value="{{ old('rows.'.$loop->index.'.detalle_ruta', $route->detalle_ruta) }}">
        <input type="hidden" name="{{ $name }}[comuna]" value="{{ old('rows.'.$loop->index.'.comuna', $route->comuna) }}">
        <input type="hidden" name="{{ $name }}[producto]" value="{{ old('rows.'.$loop->index.'.producto', $route->producto) }}">
        <input type="hidden" name="{{ $name }}[agente]" value="{{ old('rows.'.$loop->index.'.agente', $route->agente) }}">
        <input type="hidden" name="{{ $name }}[tipo_cobro]" value="{{ $rowMode }}" data-cv-mode>
        <input type="hidden" name="{{ $name }}[observacion]" value="{{ old('rows.'.$loop->index.'.observacion', $route->observacion) }}">
        <div class="cv-fields">
            <label class="cv-field">Proveedor / nombre de pila<select data-cv-provider-choice aria-label="Nombre de pila para {{ $route->usuario }}"><option value="">Por asociar</option>@foreach ($providers as $provider)<option value="{{ $provider->id }}" @selected((string) $selectedProviderId === (string) $provider->id)>{{ $provider->operational_name ?: $provider->legal_name }}</option>@endforeach</select></label>
            <label class="cv-field">RUT proveedor<select data-cv-provider-choice aria-label="RUT proveedor para {{ $route->usuario }}"><option value="">Por RUT</option>@foreach ($providers as $provider)<option value="{{ $provider->id }}" @selected((string) $selectedProviderId === (string) $provider->id)>{{ $provider->tax_id }}</option>@endforeach</select></label>
            <label class="cv-field">Razón social<select data-cv-provider-choice aria-label="Razón social para {{ $route->usuario }}"><option value="">Por razón social</option>@foreach ($providers as $provider)<option value="{{ $provider->id }}" @selected((string) $selectedProviderId === (string) $provider->id)>{{ $provider->legal_name }}</option>@endforeach</select></label>
            <label class="cv-field">Zona<input name="{{ $name }}[zona]" value="{{ old('rows.'.$loop->index.'.zona', $route->zona) }}" required></label>
            <label class="cv-field">Frecuencia<select name="{{ $name }}[frecuencia]" required>@foreach ($frequencies as $frequency)<option value="{{ $frequency->name }}" @selected(old('rows.'.$loop->parent->index.'.frecuencia', $route->frecuencia) === $frequency->name)>{{ $frequency->name }}</option>@endforeach</select></label>
            @if ($rowMode === 'fijo')
                <input type="hidden" name="{{ $name }}[valor]" value="{{ old('rows.'.$loop->index.'.valor', $route->valor) }}" data-cv-rate>
                <label class="cv-field">Monto fijo mensual ($)<input type="number" min="0" name="{{ $name }}[monto_fijo]" value="{{ old('rows.'.$loop->index.'.monto_fijo', $route->monto_fijo) }}" data-cv-fixed required></label>
            @else
                <label class="cv-field">Valor por día ($)<input type="number" min="0" name="{{ $name }}[valor]" value="{{ old('rows.'.$loop->index.'.valor', $route->valor) }}" data-cv-rate required></label>
                <input type="hidden" name="{{ $name }}[monto_fijo]" value="{{ old('rows.'.$loop->index.'.monto_fijo', $route->monto_fijo) }}" data-cv-fixed>
            @endif
        </div>
        <details class="cv-calendar" data-cv-calendar>
            <summary>Calendario de días · <span data-cv-count>{{ count($selectedDays) }}</span> seleccionados</summary>
            <div class="cv-calendar-scroll"><div class="cv-calendar-grid" role="group" aria-label="Calendario de {{ $selectedPeriod }} para {{ $route->usuario }}">
                @foreach (['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sa', 'Do'] as $weekday)<div class="cv-calendar-weekday">{{ $weekday }}</div>@endforeach
                @for ($empty=0; $empty<$firstWeekdayOffset; $empty++)<div class="cv-calendar-empty" aria-hidden="true"></div>@endfor
                @for ($day=1; $day<=$daysInMonth; $day++)<label class="cv-day" title="{{ $dayLabels[$day]['full'] }}"><span>{{ $dayLabels[$day]['date'] }}</span><input type="checkbox" name="{{ $name }}[dias][]" value="{{ $day }}" aria-label="{{ $dayLabels[$day]['full'] }}" @checked(in_array($day, array_map('intval', $selectedDays), true))></label>@endfor
            </div></div>
            @if ($daysInMonth < 31)<div class="cv-extra-days"><span class="note">Días adicionales fuera del calendario:</span>@for ($day=$daysInMonth+1; $day<=31; $day++)<label class="cv-day extra"><span>Extra {{ $day }}</span><input type="checkbox" name="{{ $name }}[dias][]" value="{{ $day }}" @checked(in_array($day, array_map('intval', $selectedDays), true))></label>@endfor</div>@endif
            <label class="cv-field cv-calendar-absence">Inasistencias<input type="number" min="0" max="31" name="{{ $name }}[inasistencia]" value="{{ old('rows.'.$loop->index.'.inasistencia', $route->inasistencia) }}" data-cv-absences required></label>
        </details>
        <div class="cv-footer"><span class="cv-warning" data-cv-provider-status>{{ $route->provider_id ? '' : 'Selecciona el proveedor' }}</span><button type="button" class="cv-reset" data-cv-provider-unlock hidden>Cambiar proveedor</button></div>
    </article>
    @endforeach
    @unless ($isClosed)<div class="cv-actions"><button type="submit">Guardar todos los cambios</button></div>@endunless
    </fieldset>
</form>
@unless ($isClosed)
<details class="card" style="margin-top:16px"><summary><strong>Agregar ruta adicional</strong></summary><form method="post" action="{{ route('provider-payments.courier-movements.rutas-cv.store') }}" style="margin-top:14px" data-cv-provider-group data-cv-saved-provider-id="">@csrf<input type="hidden" name="periodo" value="{{ $selectedPeriod }}"><input type="hidden" name="provider_id" value="" data-cv-provider-id><div class="cv-add-grid">
    <label class="cv-field">Proveedor / nombre de pila<select data-cv-provider-choice><option value="">Por asociar</option>@foreach($providers as $provider)<option value="{{ $provider->id }}">{{ $provider->operational_name ?: $provider->legal_name }}</option>@endforeach</select></label>
    <label class="cv-field">RUT proveedor<select data-cv-provider-choice><option value="">Por RUT</option>@foreach($providers as $provider)<option value="{{ $provider->id }}">{{ $provider->tax_id }}</option>@endforeach</select></label>
    <label class="cv-field">Razón social<select data-cv-provider-choice><option value="">Por razón social</option>@foreach($providers as $provider)<option value="{{ $provider->id }}">{{ $provider->legal_name }}</option>@endforeach</select></label>
    <label class="cv-field">Facturador<input name="facturador" required></label><label class="cv-field">Repartidor / usuario<input name="usuario" required></label><label class="cv-field">Detalle ruta<input name="detalle_ruta" required></label><label class="cv-field">Comuna<input name="comuna" required></label><label class="cv-field">Zona<input name="zona" value="RM" required></label><label class="cv-field">Frecuencia<select name="frecuencia" required><option value="">Selecciona</option>@foreach($frequencies as $frequency)<option value="{{ $frequency->name }}">{{ $frequency->name }}</option>@endforeach</select></label><label class="cv-field">Valor por día ($)<input type="number" min="0" name="valor" value="0" required></label><label class="cv-field">Forma de cobro<select name="tipo_cobro"><option value="diario">Por día</option><option value="fijo">Monto fijo mensual</option></select></label><label class="cv-field">Monto fijo ($)<input type="number" min="0" name="monto_fijo"></label>
</div><div class="cv-actions"><button type="button" class="cv-reset" data-cv-provider-unlock hidden>Cambiar proveedor</button><button type="submit">Agregar ruta</button></div></form></details>
@endunless
@else
<div class="card"><p class="note">Aún no hay rutas cargadas. Sube Base Ruta CV para iniciar y luego genera los próximos meses desde este período.</p></div>
@endif
@endsection
@push('scripts')
<script>
const generationForm = document.querySelector('[data-cv-generate]');
if (generationForm) {
    const monthInput = generationForm.querySelector('[name="periodo_month"]');
    const message = generationForm.querySelector('[data-cv-source-message]');
    const list = generationForm.querySelector('[data-cv-source-list]');
    let requestNumber = 0;
    const loadSourceRoutes = async () => {
        const currentRequest = ++requestNumber;
        list.replaceChildren();
        if (!monthInput.value) { message.textContent = 'Selecciona el período nuevo para ver las rutas de origen.'; return; }
        message.textContent = 'Buscando rutas para el período seleccionado…';
        const url = new URL(generationForm.dataset.sourceUrl, window.location.href);
        url.searchParams.set('periodo_month', monthInput.value);
        try {
            const response = await fetch(url, {headers: {'Accept': 'application/json'}});
            if (!response.ok) throw new Error('No se pudo consultar la base anterior.');
            const data = await response.json();
            if (currentRequest !== requestNumber) return;
            message.textContent = data.source_period
                ? `Base ${data.source_period}: ${data.routes.length} rutas. Marca las que no quieres copiar.`
                : 'No existe una base anterior para este período.';
            for (const route of data.routes) {
                const label = document.createElement('label');
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.name = 'exclude_route_ids[]';
                checkbox.value = route.id;
                const description = document.createElement('span');
                description.textContent = `No copiar · ${route.provider} · ${route.detail} · ${route.frequency}`;
                label.append(checkbox, description);
                list.append(label);
            }
        } catch (error) {
            if (currentRequest === requestNumber) message.textContent = 'No se pudo mostrar la base anterior. Vuelve a seleccionar el período.';
        }
    };
    monthInput.addEventListener('change', loadSourceRoutes);
    loadSourceRoutes();
}
for (const group of document.querySelectorAll('[data-cv-provider-group]')) {
    const choices = [...group.querySelectorAll('[data-cv-provider-choice]')];
    const providerId = group.querySelector('[data-cv-provider-id]');
    const unlock = group.querySelector('[data-cv-provider-unlock]');
    const status = group.querySelector('[data-cv-provider-status]');
    const title = group.querySelector('[data-cv-provider-title]');
    const refreshStatus = () => {
        if (!status) return;
        const selected = providerId.value;
        const saved = group.dataset.cvSavedProviderId || '';
        status.textContent = !selected ? '· Selecciona el proveedor' : selected !== saved ? '· Proveedor pendiente de guardar' : '';
        group.classList.toggle('needs-provider', !selected);
        group.classList.toggle('provider-pending', Boolean(selected && selected !== saved));
    };
    for (const choice of choices) {
        choice.addEventListener('change', () => {
            const selected = choice.value;
            for (const other of choices) {
                other.value = selected;
                other.disabled = Boolean(selected) && other !== choice;
            }
            providerId.value = selected;
            if (title) title.textContent = selected ? choices[0].selectedOptions[0].textContent : 'Proveedor por asociar';
            unlock.hidden = !selected;
            refreshStatus();
        });
    }
    unlock.addEventListener('click', () => {
        for (const choice of choices) choice.disabled = false;
        unlock.hidden = true;
        choices[0].focus();
    });
    if (providerId.value) {
        for (const choice of choices.slice(1)) choice.disabled = true;
        unlock.hidden = false;
    }
    refreshStatus();
}
for (const card of document.querySelectorAll('[data-cv-route]')) {
    const refresh = () => {
        const days = card.querySelectorAll('.cv-day input:checked').length;
        const absences = Number(card.querySelector('[data-cv-absences]').value || 0);
        const rate = Number(card.querySelector('[data-cv-rate]').value || 0);
        const fixed = Number(card.querySelector('[data-cv-fixed]').value || 0);
        const total = card.querySelector('[data-cv-mode]').value === 'fijo' ? fixed : Math.max(0, days - absences) * rate;
        card.querySelector('[data-cv-count]').textContent = days;
        card.querySelector('[data-cv-total]').textContent = new Intl.NumberFormat('es-CL').format(total);
    };
    card.addEventListener('input', refresh);
    card.addEventListener('change', refresh);
    refresh();
}
</script>
@endpush
