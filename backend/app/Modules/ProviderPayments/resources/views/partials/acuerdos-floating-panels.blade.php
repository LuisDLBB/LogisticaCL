<style>
.agreements-float{position:fixed;left:238px;top:84px;z-index:30;width:max-content;max-width:calc(100vw - 24px);max-height:calc(100vh - 96px);border:1px solid #b8dbc4;border-radius:10px;background:#ebf8ee;box-shadow:0 10px 26px #102a2d29;color:var(--ink);font-family:system-ui,-apple-system,"Segoe UI",sans-serif;overflow:hidden}
.agreements-float[data-open="false"]{width:var(--agreements-closed-width,max-content)}
.agreements-float[data-open="true"]{width:min(560px,calc(100vw - 24px))}
.agreements-float[data-panel="rules"][data-open="true"]{width:min(850px,calc(100vw - 24px))}
.agreements-float[data-panel="services"][data-open="true"],.agreements-float[data-panel="clients"][data-open="true"]{width:min(550px,calc(100vw - 24px))}
.agreements-float-handle{display:flex;align-items:center;gap:7px;min-height:32px;padding:3px 6px 3px 9px;background:#d8efdf;cursor:grab;touch-action:none;user-select:none}
.agreements-float-handle:active{cursor:grabbing}.agreements-float-handle:focus-visible{outline:2px solid var(--turquoise-dark);outline-offset:-3px}
.agreements-float-handle strong{flex:1;font-size:12px;font-weight:800;white-space:nowrap}.agreements-float-grip{font-size:14px;letter-spacing:-3px;color:#55856a}
.agreements-float-toggle{min-width:24px;min-height:24px;padding:1px 5px;background:#9bd0ad;color:#143b29;font-size:15px}
.agreements-float-body{max-height:calc(100vh - 154px);padding:13px;overflow:auto;background:#ebf8ee}.agreements-float-body[hidden]{display:none}
.agreements-float-body .agreements-note{margin:0 0 10px;color:#49695a}.agreements-float-body .agreements-grid{max-width:none;gap:4px}.agreements-float-body .agreements-day{background:#f9fffb;border-color:#c7e3d0}.agreements-float-body .agreements-day.holiday{background:#fff0e6;border-color:#ecac85}
.agreements-float-body .agreements-rule{grid-template-columns:minmax(170px,2fr) 105px 75px minmax(180px,1fr) auto}.agreements-float-body .agreements-list{max-height:calc(100vh - 208px)}
.agreements-float-body table{width:100%;border-collapse:collapse;font-size:12px}.agreements-float-body th,.agreements-float-body td{padding:7px;border-bottom:1px solid #c7e3d0;text-align:left}.agreements-float-body td:last-child{text-align:right;white-space:nowrap}
.agreements-float-state{display:inline-block;margin-bottom:9px;padding:4px 8px;border-radius:5px;background:#d5eedd;color:#255a3c;font-size:12px;font-weight:700}
.agreements-float-state.locked{background:#e0e6e0;color:#4c5d50}
.agreements-float-create{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:0;align-items:end}.agreements-float-create label{display:grid;gap:3px;font-size:11px;font-weight:750}.agreements-float-create input,.agreements-float-create select{width:100%;min-width:0;padding:6px;border:1px solid var(--line);border-radius:6px;background:#fff}.agreements-float-create button{justify-self:start}
@media(max-width:700px){.agreements-float[data-open="true"]{width:calc(100vw - 24px)}.agreements-float-body .agreements-rule{grid-template-columns:1fr 1fr}.agreements-float-body .agreements-grid{gap:2px}.agreements-float-body .agreements-day{min-height:52px;font-size:10px}.agreements-float-create{grid-template-columns:1fr}}
</style>
<section class="agreements-float" data-panel="calendar" data-open="false" aria-label="Calendario y feriados">
    <div class="agreements-float-handle" tabindex="0" aria-label="Mover o desplegar Calendario y feriados"><span class="agreements-float-grip" aria-hidden="true">⋮⋮</span><strong>Calendario y feriados · {{ $period }}</strong><button class="agreements-float-toggle" type="button" aria-expanded="false" aria-controls="agreement-calendar-panel" aria-label="Desplegar calendario">+</button></div>
    <div class="agreements-float-body" id="agreement-calendar-panel" hidden>
        <p class="agreements-note">Marca los feriados para excluirlos del recuento semanal. Los servicios de monto fijo conservan su cantidad.</p>
        <span class="agreements-float-state {{ $calendarLocked || $isClosed ? 'locked' : '' }}">{{ $isClosed ? 'Período cerrado' : ($calendarLocked ? 'Calendario bloqueado' : 'Calendario editable') }}</span>
        <form method="post" action="{{ route('provider-payments.courier-movements.acuerdos.calendar') }}">@csrf<input type="hidden" name="periodo" value="{{ $period }}">
            <div class="agreements-grid">@foreach(['Lu','Ma','Mi','Ju','Vi','Sa','Do'] as $weekday)<span class="weekday">{{ $weekday }}</span>@endforeach
                @if($calendar->isNotEmpty())@for($i=1;$i<$calendar->first()->fecha->dayOfWeekIso;$i++)<span></span>@endfor@endif
                @foreach($calendar as $day)<label class="agreements-day {{ $day->es_feriado ? 'holiday' : '' }}"><strong>{{ $day->fecha->format('d/m') }}</strong><small>{{ ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'][$day->fecha->dayOfWeekIso - 1] }}</small><span><input type="checkbox" name="feriados[]" value="{{ $day->fecha->toDateString() }}" @checked($day->es_feriado) @disabled($calendarLocked || $isClosed)> Feriado</span></label>@endforeach
            </div>
            <div class="agreements-grid" style="margin-top:9px">@foreach(['Lu','Ma','Mi','Ju','Vi','Sa','Do'] as $index=>$weekday)<div class="agreements-day"><small>{{ $weekday }} no feriados</small><strong>{{ $weekdayCounts[$index+1] ?? 0 }}</strong></div>@endforeach</div>
            <p class="agreements-note" style="margin-top:9px">{{ $calendar->where('es_feriado', true)->count() }} feriados · {{ $calendar->count() - $calendar->where('es_feriado', true)->count() }} días no feriados</p>
            @unless($calendarLocked || $isClosed)<button type="submit">Guardar, recalcular y bloquear</button>@endunless
        </form>
        @if($calendarLocked && ! $isClosed)<form method="post" action="{{ route('provider-payments.courier-movements.acuerdos.calendar.unlock') }}">@csrf<input type="hidden" name="periodo" value="{{ $period }}"><button type="submit">Desbloquear para recalcular</button></form>@endif
    </div>
</section>
<section class="agreements-float" data-panel="rules" data-open="false" aria-label="Matriz de servicios">
    <div class="agreements-float-handle" tabindex="0" aria-label="Mover o desplegar Matriz de servicios"><span class="agreements-float-grip" aria-hidden="true">⋮⋮</span><strong>Matriz de servicios · {{ $rules->count() }}</strong><button class="agreements-float-toggle" type="button" aria-expanded="false" aria-controls="agreement-rules-panel" aria-label="Desplegar matriz">+</button></div>
    <div class="agreements-float-body" id="agreement-rules-panel" hidden>
        <p class="agreements-note">{{ $isClosed ? 'La matriz quedó bloqueada al cerrar el período.' : 'Ajusta los días no feriados de cada servicio o su cantidad fija. Al guardar se recalculan sus acuerdos.' }}</p>
        <div class="agreements-list">@foreach($rules as $rule)<form class="agreements-rule" method="post" action="{{ route('provider-payments.courier-movements.acuerdos.rule') }}">@csrf<input type="hidden" name="periodo" value="{{ $period }}"><input type="hidden" name="servicio" value="{{ $rule->servicio }}">
            <strong>{{ $rule->servicio }}</strong><select name="modo" aria-label="Modalidad de {{ $rule->servicio }}" @disabled($isClosed)><option value="dias_semana" @selected($rule->modo === 'dias_semana')>Por días</option><option value="fijo" @selected($rule->modo === 'fijo')>Fijo</option></select>
            <input type="number" name="cantidad_fija" value="{{ $rule->cantidad_fija }}" min="0" max="366" aria-label="Cantidad fija de {{ $rule->servicio }}" placeholder="Cantidad" @disabled($isClosed)>
            <span class="agreements-weekdays">@foreach(['Lu','Ma','Mi','Ju','Vi','Sa','Do'] as $index=>$weekday)<label><input type="checkbox" name="dias_semana[]" value="{{ $index+1 }}" @checked(in_array($index+1, $rule->dias_semana ?? [])) @disabled($isClosed)>{{ $weekday }}</label>@endforeach</span>@unless($isClosed)<button type="submit">Guardar</button>@endunless
        </form>@endforeach</div>
    </div>
</section>
<section class="agreements-float" data-panel="services" data-open="false" aria-label="Resumen por servicio">
    <div class="agreements-float-handle" tabindex="0" aria-label="Mover o desplegar Resumen por servicio"><span class="agreements-float-grip" aria-hidden="true">⋮⋮</span><strong>Resumen por servicio</strong><button class="agreements-float-toggle" type="button" aria-expanded="false" aria-controls="agreement-services-panel" aria-label="Desplegar resumen por servicio">+</button></div>
    <div class="agreements-float-body" id="agreement-services-panel" hidden><table><thead><tr><th>Servicio</th><th>Acuerdos</th><th>Días</th><th>Monto</th></tr></thead><tbody>@foreach($byService as $item)<tr><td>{{ $item->servicio }}</td><td>{{ $item->registros }}</td><td>{{ $item->dias }}</td><td>$ {{ number_format($item->monto, 0, ',', '.') }}</td></tr>@endforeach</tbody></table></div>
</section>
<section class="agreements-float" data-panel="clients" data-open="false" aria-label="Resumen por cliente">
    <div class="agreements-float-handle" tabindex="0" aria-label="Mover o desplegar Resumen por cliente"><span class="agreements-float-grip" aria-hidden="true">⋮⋮</span><strong>Resumen por cliente</strong><button class="agreements-float-toggle" type="button" aria-expanded="false" aria-controls="agreement-clients-panel" aria-label="Desplegar resumen por cliente">+</button></div>
    <div class="agreements-float-body" id="agreement-clients-panel" hidden><table><thead><tr><th>Cliente de origen</th><th>Acuerdos</th><th>Monto</th></tr></thead><tbody>@foreach($byClient as $item)<tr><td>{{ $item->cliente }}</td><td>{{ $item->registros }}</td><td>$ {{ number_format($item->monto, 0, ',', '.') }}</td></tr>@endforeach</tbody></table></div>
</section>
@unless($isClosed)<section class="agreements-float" data-panel="create" data-open="false" aria-label="Agregar acuerdo al período">
    <div class="agreements-float-handle" tabindex="0" aria-label="Mover o desplegar Agregar acuerdo al período"><span class="agreements-float-grip" aria-hidden="true">⋮⋮</span><strong>Agregar acuerdo al período</strong><button class="agreements-float-toggle" type="button" aria-expanded="false" aria-controls="agreement-create-panel" aria-label="Desplegar nuevo acuerdo">+</button></div>
    <div class="agreements-float-body" id="agreement-create-panel" hidden>
        <form class="agreements-float-create" method="post" action="{{ route('provider-payments.courier-movements.acuerdos.store') }}">@csrf<input type="hidden" name="periodo" value="{{ $period }}">
            <label>Proveedor<select name="provider_id" required><option value="">Selecciona</option>@foreach($providers as $provider)<option value="{{ $provider->id }}">{{ $provider->operational_name ?: $provider->legal_name }} · {{ $provider->tax_id }}</option>@endforeach</select></label>
            <label>Cliente<select name="client_id" required><option value="">Selecciona</option>@foreach($clients as $client)<option value="{{ $client->id }}">{{ $client->commercial_name }} · {{ $client->tax_id }}</option>@endforeach</select></label>
            <label>Servicio<select name="servicio" required><option value="">Selecciona</option>@foreach($rules as $rule)<option value="{{ $rule->servicio }}">{{ $rule->servicio }}</option>@endforeach</select></label>
            <label>Costo<input type="number" name="costo" min="0" required></label><label>Factor<input type="number" name="factor" min="1" value="1" required></label>
            <label>Inasistencias<input type="number" name="inasistencias" min="0" value="0" required></label><label>Adicionales<input type="number" name="adicionales" min="0" value="0" required></label>
            <label>Marca<input name="marca" maxlength="255"></label><label>Agencia<input name="agencia" maxlength="100"></label>
            <label>Tipo servicio<input name="tipo_servicio" maxlength="50"></label><label>Empresa mandante<input name="empresa_mandante" maxlength="100"></label><button type="submit">Agregar y calcular</button>
        </form>
    </div>
</section>@endunless
@push('scripts')
<script>
(() => {
    const panels = [...document.querySelectorAll('.agreements-float')];
    const closedWidth = Math.ceil(Math.max(...panels.map((panel) => panel.getBoundingClientRect().width)));
    document.documentElement.style.setProperty('--agreements-closed-width', `${closedWidth}px`);
    let front = 40;
    const movedPanels = new Set();
    const dockPanels = () => {
        const sidebar = document.querySelector('.sidebar');
        const heading = document.querySelector('.agreements-page-heading');
        const dockLeft = innerWidth > 760 && sidebar ? Math.round(sidebar.getBoundingClientRect().right + 16) : 12;
        const dockTop = Math.max(84, Math.round(document.querySelector('main.content').getBoundingClientRect().top + 8));
        const gap = 6;
        const width = Math.min(closedWidth, innerWidth - dockLeft - 12);
        const rowHeight = Math.ceil(Math.max(...panels.map((panel) => panel.querySelector('.agreements-float-handle').getBoundingClientRect().height)));
        let left = dockLeft;
        let top = dockTop;
        panels.forEach((panel) => {
            if (left > dockLeft && left + width > innerWidth - 12) {
                left = dockLeft;
                top += rowHeight + gap;
            }
            if (!movedPanels.has(panel)) {
                panel.style.left = `${left}px`;
                panel.style.top = `${top}px`;
            }
            left += width + gap;
        });
        if (heading) {
            const headingTop = heading.getBoundingClientRect().top;
            document.documentElement.style.setProperty('--agreements-heading-offset', `${Math.max(30, Math.ceil(top + rowHeight + 12 - headingTop))}px`);
        }
    };
    const keepVisible = (panel) => {
        if (!panel.style.left) return;
        const rect = panel.getBoundingClientRect();
        const left = Math.min(Math.max(8, rect.left), Math.max(8, innerWidth - rect.width - 8));
        const top = Math.min(Math.max(80, rect.top), Math.max(80, innerHeight - 52));
        panel.style.left = `${left}px`;
        panel.style.top = `${top}px`;
    };
    panels.forEach((panel) => {
        const key = `acuerdos-panel-v2-${panel.dataset.panel}`;
        const handle = panel.querySelector('.agreements-float-handle');
        const toggle = panel.querySelector('.agreements-float-toggle');
        const body = panel.querySelector('.agreements-float-body');
        const saved = localStorage.getItem(key);
        if (saved) {
            try {
                const { left, top } = JSON.parse(saved);
                if (Number.isFinite(left) && Number.isFinite(top)) {
                    movedPanels.add(panel);
                    panel.style.left = `${left}px`;
                    panel.style.top = `${top}px`;
                    keepVisible(panel);
                }
            } catch (_) { localStorage.removeItem(key); }
        }
        const setOpen = (open) => {
            panel.dataset.open = String(open);
            body.hidden = !open;
            toggle.textContent = open ? '−' : '+';
            toggle.setAttribute('aria-expanded', String(open));
            toggle.setAttribute('aria-label', `${open ? 'Contraer' : 'Desplegar'} ${panel.getAttribute('aria-label')}`);
            panel.style.zIndex = String(++front);
            keepVisible(panel);
            if (!open && !movedPanels.has(panel)) dockPanels();
        };
        toggle.addEventListener('click', (event) => { event.stopPropagation(); setOpen(panel.dataset.open !== 'true'); });
        handle.addEventListener('keydown', (event) => {
            if (event.target === handle && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault(); setOpen(panel.dataset.open !== 'true');
            }
        });
        let dragging = null;
        handle.addEventListener('pointerdown', (event) => {
            if (event.button !== 0 || event.target.closest('button')) return;
            const rect = panel.getBoundingClientRect();
            dragging = { x: event.clientX, y: event.clientY, left: rect.left, top: rect.top, moved: false };
            panel.style.zIndex = String(++front);
            handle.setPointerCapture(event.pointerId);
        });
        handle.addEventListener('pointermove', (event) => {
            if (!dragging) return;
            const dx = event.clientX - dragging.x;
            const dy = event.clientY - dragging.y;
            if (Math.abs(dx) + Math.abs(dy) > 4) dragging.moved = true;
            if (!dragging.moved) return;
            panel.style.left = `${dragging.left + dx}px`;
            panel.style.top = `${dragging.top + dy}px`;
            keepVisible(panel);
        });
        handle.addEventListener('pointerup', () => {
            if (!dragging) return;
            const moved = dragging.moved;
            dragging = null;
            if (moved) {
                movedPanels.add(panel);
                localStorage.setItem(key, JSON.stringify({ left: panel.offsetLeft, top: panel.offsetTop }));
            }
            else setOpen(panel.dataset.open !== 'true');
        });
        handle.addEventListener('pointercancel', () => { dragging = null; });
    });
    dockPanels();
    addEventListener('resize', () => {
        dockPanels();
        panels.filter((panel) => movedPanels.has(panel)).forEach(keepVisible);
    });
})();
</script>
@endpush
