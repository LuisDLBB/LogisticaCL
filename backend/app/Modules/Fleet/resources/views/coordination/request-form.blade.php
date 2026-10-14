@extends('fleet::layout')
@section('title','Nueva solicitud de retiro')
@push('styles')
<style>#ret-complete-point[hidden]{display:none!important}
.ret-time-range{grid-column:1/-1;min-width:0;margin:0;padding:14px 16px;border:1px solid #dce7e9;border-radius:12px;background:#f8fbfc}
.ret-time-range legend{padding:0 6px;font-size:14px;font-weight:700;color:#24616a}
.ret-time-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.ret-time-picker{position:relative;min-width:0}.ret-time-picker>label{display:block;margin:0 0 6px}
.ret-time-input{position:relative}.ret-time-input input{margin:0;width:100%;padding-right:48px;font-variant-numeric:tabular-nums;font-size:16px;letter-spacing:1px}
.ret-time-input .ret-time-toggle{position:absolute;right:3px;top:3px;width:36px;height:32px;min-height:32px;padding:5px;border:0;background:transparent;color:#007f87;border-radius:7px}
.ret-time-toggle:hover{background:#e2f4f3!important;color:#006a72!important}
.ret-time-panel{position:absolute;left:0;top:100%;margin-top:7px;width:300px;max-width:calc(100vw - 64px);z-index:10;padding:14px;background:#fff;border:1px solid #cbdfe2;border-radius:12px;box-shadow:0 10px 30px #17363a24}
.ret-time-picker:last-child .ret-time-panel{left:auto;right:0}.ret-time-panel[hidden]{display:none!important}
.ret-time-panel-heading{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:12px;font-size:13px}.ret-time-panel-heading span{color:#6a8187;font-size:10px}
.ret-time-hours{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:5px}.ret-time-minutes{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px}
.ret-time-panel button{min-height:34px;padding:6px 0;border:1px solid #e0e9eb;background:#f8fafb;color:#294a51;font-size:12px;font-weight:600;border-radius:7px}
.ret-time-panel button:hover{background:#e1f4f3;border-color:#76bfc2;color:#006c72}.ret-time-panel button[aria-pressed=true]{background:#008f92;border-color:#008f92;color:#fff}
.ret-time-panel p{margin:12px 0 7px;font-size:12px;font-weight:700}.ret-time-panel small{display:block;margin-top:12px;font-size:11px;line-height:1.5;color:#687e85}
.ret-time-summary{margin:10px 0 0;font-size:12px;color:#54727b}.ret-time-summary[data-invalid=true]{color:#a83b32}
@media(max-width:600px){.ret-time-fields{gap:10px}.ret-time-range{padding:12px}.ret-time-input input{font-size:15px}}
</style>
@endpush
@section('content')
@php($fleetTenant=app(\App\Fleet\FleetAccess::class)->tenant(request()))
<div class="ret-window"><h1>Nueva solicitud de retiro</h1><form method="post" action="{{ route('fleet.requests.store') }}" class="ret-form" data-ret-validate>@csrf
<h2 class="ret-section-title">Solicitud</h2><div class="ret-two">
<div><small>Área solicitante</small><p><strong>{{ $requestingArea }}</strong></p></div>
<div><small>Tipo de retiro</small><p><strong>Retiro variable</strong></p></div>
<label>Fecha requerida para el retiro<input type="date" name="service_date" value="{{ old('service_date',now('America/Santiago')->toDateString()) }}" required><small>Esta fecha original quedará conservada en el historial.</small></label>
<label>Comerciante<select id="client" name="client_id" required><option value="">Seleccionar comerciante</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(old('client_id')==$client->id)>{{ $client->commercial_name }}</option>@endforeach</select></label>
<label>Código del retiro<input readonly placeholder="4N-RET-00000-DV · Se asignará al guardar"><small>Correlativo global con dígito verificador módulo 11. Se genera al guardar.</small></label></div><p id="legal-name" class="muted"></p>
<h2 class="ret-section-title">Lugar de retiro</h2><div class="ret-two">
<div><label>Punto recurrente / proveedor<select id="point" name="client_branch_id" required><option value="">Selecciona primero un cliente</option></select></label><button type="button" class="secondary" id="ret-add-point" disabled>Agregar punto de retiro</button><button type="button" id="ret-complete-point" hidden>Completar dirección pendiente</button></div>
<label>Comuna<input id="commune" name="commune_snapshot" value="{{ old('commune_snapshot') }}" required></label>
<label>Dirección del punto<input id="pickup-address" readonly><small id="point-address"></small></label>
<label>Jornada<select name="shift" required>@foreach(['AM','PM','AM/PM'] as $shift)<option @selected(old('shift')===$shift)>{{ $shift }}</option>@endforeach</select></label>
<fieldset class="ret-time-range"><legend>Horario de retiro</legend><div class="ret-time-fields">
@foreach(['window_start'=>'Desde','window_end'=>'Hasta'] as $name=>$label)
<div class="ret-time-picker" data-time-picker>
<label for="{{ $name }}">{{ $label }}</label>
<div class="ret-time-input"><input id="{{ $name }}" type="text" name="{{ $name }}" value="{{ old($name,'00:00') }}" placeholder="HH:mm" maxlength="5" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" autocomplete="off" aria-describedby="ret-time-summary" required><button type="button" class="ret-time-toggle" aria-label="Elegir hora {{ strtolower($label) }}" aria-expanded="false" aria-controls="{{ $name }}-picker"><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></button></div>
<div id="{{ $name }}-picker" class="ret-time-panel" role="group" aria-label="Seleccionar horario {{ strtolower($label) }}" hidden>
<div class="ret-time-panel-heading"><strong>Elige la hora</strong><span>Formato 24 horas</span></div>
<div class="ret-time-hours" role="group" aria-label="Horas">@foreach(range(0,23) as $hour)<button type="button" data-hour="{{ sprintf('%02d',$hour) }}" aria-pressed="false">{{ sprintf('%02d',$hour) }}</button>@endforeach</div>
<p>Minutos</p><div class="ret-time-minutes" role="group" aria-label="Minutos">@foreach(['00','15','30','45'] as $minute)<button type="button" data-minute="{{ $minute }}" aria-pressed="false">{{ $minute }}</button>@endforeach</div>
<small>También puedes escribir una hora exacta, por ejemplo 09:10.</small>
</div></div>
@endforeach
</div><p id="ret-time-summary" class="ret-time-summary" aria-live="polite"></p></fieldset>
<label>Contacto<input id="contact-name" name="contact_name_snapshot" value="{{ old('contact_name_snapshot') }}"></label>
<label>Teléfono<input id="contact-phone" name="contact_phone_snapshot" value="{{ old('contact_phone_snapshot') }}"></label>
<label>Correo para aviso<input id="contact-email" name="operational_emails_snapshot" value="{{ old('operational_emails_snapshot') }}"></label>
</div><label>Referencia de acceso e instrucciones<textarea id="reference" name="access_reference_snapshot" rows="3">{{ old('access_reference_snapshot') }}</textarea></label>
<details><summary>Usar una dirección excepcional para este retiro</summary><div class="ret-two"><label>Dirección efectiva distinta<input name="effective_address" value="{{ old('effective_address') }}"></label><label>Motivo del cambio de dirección<input name="address_override_reason" value="{{ old('address_override_reason') }}"></label></div></details>
<h2 class="ret-section-title">Carga</h2><div class="ret-two"><label>Cantidad de bultos<input id="packages" type="number" min="1" name="packages" value="{{ old('packages',5) }}" required><small>Última cantidad del cliente y punto; sin historial, 5. Puedes modificarla.</small></label><label>Presentación de la carga<select name="load_unit"><option value="loose" @selected(old('load_unit','loose')==='loose')>Bultos sueltos</option><option value="pallets" @selected(old('load_unit')==='pallets')>Pallets</option></select></label><div id="pallet-fields" hidden>
<label>Tipo de pallet<select name="pallet_type"><option value="">Seleccionar tipo de pallet</option><option value="european" @selected(old('pallet_type')==='european')>Europeo · 120 × 80 cm</option><option value="american" @selected(old('pallet_type')==='american')>Americano · 120 × 100 cm</option></select></label>
<label>Altura del pallet con carga (cm)<input type="number" name="pallet_height_cm" min="0.01" max="999999.99" step="0.01" value="{{ old('pallet_height_cm') }}" placeholder="Ingresar altura"></label>
<small id="pallet-measures">Selecciona el tipo e indica la altura en centímetros.</small>
</div><label>Peso total (kg)<input type="number" step="0.01" min="0.01" name="requested_weight_kg" value="{{ old('requested_weight_kg') }}"></label><label>Volumen total (m³)<input type="number" step="0.001" min="0.001" name="requested_volume_m3" value="{{ old('requested_volume_m3') }}"></label></div>
<label>Descripción / detalle de la carga retirada<textarea name="notes" rows="2">{{ old('notes') }}</textarea></label>@include('fleet::coordination.test-flag')
<p class="notice">Los retiros para hoy ingresados después de las {{ app(\App\Fleet\LatePickupAuthorization::class)->cutoff($fleetTenant->id) }} quedan como solicitud de excepción. Operaciones y el gerente comercial deben aprobar antes de programarlos.</p>
<div class="ret-form-footer"><button>Agendar retiro con operaciones</button></div></form></div>
<dialog id="ret-point-dialog" class="ret-change-dialog" aria-labelledby="ret-point-title"><div class="ret-agenda-heading"><h2 id="ret-point-title">Agregar punto de retiro</h2><button type="button" id="ret-point-close" aria-label="Cerrar punto de retiro">×</button></div><form id="ret-point-form" class="ret-form"><label>Nombre del punto<input name="name" required maxlength="255"></label><label>Dirección<input name="address" required maxlength="255" placeholder="Calle y número"></label><label>Comuna<input name="commune" required maxlength="100"></label><p id="ret-point-error" role="alert"></p><p><button>Guardar y usar este punto</button></p></form></dialog>
<script>
const loadUnitSelect = document.querySelector('[name=load_unit]');
const palletFields = document.getElementById('pallet-fields');
const palletTypeSelect = document.querySelector('[name=pallet_type]');
const palletHeightInput = document.querySelector('[name=pallet_height_cm]');
function updatePalletFields() {
    const pallets = loadUnitSelect.value === 'pallets';
    palletFields.hidden = !pallets;
    for (const field of [palletTypeSelect, palletHeightInput]) {field.disabled = !pallets; field.required = pallets;}
    const width = palletTypeSelect.value === 'european' ? 80 : palletTypeSelect.value === 'american' ? 100 : null;
    document.getElementById('pallet-measures').textContent = width ? `Medidas: 120 × ${width} × ${palletHeightInput.value || 'altura pendiente'} cm.` : 'Selecciona el tipo e indica la altura en centímetros.';
}
loadUnitSelect.addEventListener('change', updatePalletFields);
palletTypeSelect.addEventListener('change', updatePalletFields);
palletHeightInput.addEventListener('input', updatePalletFields);
updatePalletFields();

const timePickers = [...document.querySelectorAll('[data-time-picker]')];
const timePattern = /^([01]\d|2[0-3]):[0-5]\d$/;
function closeTimePickers() {
    for (const picker of timePickers) {
        picker.querySelector('.ret-time-panel').hidden = true;
        picker.querySelector('.ret-time-toggle').setAttribute('aria-expanded', 'false');
    }
}
function updateTimeRange() {
    for (const picker of timePickers) {
        const input = picker.querySelector('input');
        const valid = timePattern.test(input.value);
        input.setCustomValidity(!input.value || valid ? '' : 'Escribe una hora válida en formato HH:mm, por ejemplo 09:10.');
        const [hour, minute] = valid ? input.value.split(':') : [];
        for (const button of picker.querySelectorAll('[data-hour], [data-minute]')) {
            button.setAttribute('aria-pressed', String(button.hasAttribute('data-hour') ? button.dataset.hour === hour : button.dataset.minute === minute));
        }
    }
    const start = document.getElementById('window_start');
    const end = document.getElementById('window_end');
    const summary = document.getElementById('ret-time-summary');
    const valid = timePattern.test(start.value) && timePattern.test(end.value);
    const invalidRange = valid && end.value <= start.value;
    if (invalidRange) end.setCustomValidity('La hora de término debe ser posterior a la hora de inicio.');
    summary.dataset.invalid = String(invalidRange);
    summary.textContent = invalidRange ? 'Selecciona una hora de término posterior al inicio.' : valid ? `Retiro entre las ${start.value} y las ${end.value}.` : 'Formato 24 horas. Puedes escribir una hora exacta.';
}
for (const picker of timePickers) {
    const input = picker.querySelector('input');
    const toggle = picker.querySelector('.ret-time-toggle');
    const panel = picker.querySelector('.ret-time-panel');
    toggle.addEventListener('click', () => {
        const open = panel.hidden;
        closeTimePickers();
        if (open) {
            updateTimeRange();
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            (panel.querySelector('[data-hour][aria-pressed=true]') || panel.querySelector('[data-hour]')).focus();
        }
    });
    panel.addEventListener('click', event => {
        const button = event.target.closest('[data-hour], [data-minute]');
        if (!button) return;
        const [hour, minute] = timePattern.test(input.value) ? input.value.split(':') : ['00', '00'];
        input.value = button.hasAttribute('data-hour') ? `${button.dataset.hour}:${minute}` : `${hour}:${button.dataset.minute}`;
        input.dispatchEvent(new Event('input', {bubbles:true}));
        if (button.hasAttribute('data-minute')) {closeTimePickers(); input.focus();}
    });
    input.addEventListener('input', updateTimeRange);
    input.addEventListener('change', updateTimeRange);
    picker.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !panel.hidden) {
            event.preventDefault(); event.stopPropagation(); closeTimePickers(); toggle.focus();
        }
    });
    picker.addEventListener('focusout', event => {if (!picker.contains(event.relatedTarget)) closeTimePickers();});
}
document.addEventListener('click', event => {if (!event.target.closest('[data-time-picker]')) closeTimePickers();});
updateTimeRange();

const clientSelect = document.getElementById('client');
const pointSelect = document.getElementById('point');
const oldFields = @json(session()->getOldInput());
let generation = 0;
const fieldNames = {'commune':'commune_snapshot','reference':'access_reference_snapshot','contact-name':'contact_name_snapshot','contact-phone':'contact_phone_snapshot','contact-email':'operational_emails_snapshot','packages':'packages'};
function updatePoint() {
    const point = JSON.parse(pointSelect.selectedOptions[0]?.dataset.point || 'null');
    document.getElementById('commune').value = point?.commune || '';
    document.getElementById('reference').value = point?.reference || '';
    document.getElementById('pickup-address').value=point?.address || '';
    document.getElementById('packages').value=point?.suggested_packages || 5;
    document.getElementById('contact-email').value = point?.operational_emails || '';
    const contact = point?.contacts?.[0];
    document.getElementById('contact-name').value = point?.suggested_contact_name || contact?.name || '';
    document.getElementById('contact-phone').value = point?.suggested_contact_phone || contact?.phone || '';
    if (!point?.operational_emails && contact?.email) document.getElementById('contact-email').value = contact.email;
    document.querySelector('[name=window_start]').value = point?.suggested_window_start || '00:00';
    document.querySelector('[name=window_end]').value = point?.suggested_window_end || '00:00';
    document.querySelector('[name=shift]').value = point?.suggested_shift || 'AM';
    updateTimeRange();
    for(const id of ['point',...Object.keys(fieldNames)]) document.getElementById(id).dispatchEvent(new Event('input',{bubbles:true}));
    document.getElementById('point-address').textContent = point?.address_status !== 'ready' && point ? 'Dirección pendiente. Complétala para continuar.' : point?.address || '';
    document.getElementById('ret-complete-point').hidden = !point || point.address_status === 'ready';
    pointSelect.setCustomValidity(point && point.address_status !== 'ready' ? 'Completa y guarda la dirección de este punto.' : '');
    document.getElementById('ret-add-point').disabled = !clientSelect.value;
}
async function loadPoints(restore=false) {
    const run = ++generation;
    const client = clientSelect.value;
    pointSelect.replaceChildren(new Option(client ? 'Cargando puntos...' : 'Selecciona primero un cliente',''));
    document.getElementById('legal-name').textContent = '';
    updatePoint();
    if(!client) return;
    try {
        const headers = {'Accept':'application/json'};
        const pointsResponse = await fetch(@json(route('fleet.clients.points',['client'=>'__ID__'])).replace('__ID__',encodeURIComponent(client)), {headers});
        if(run !== generation) return;
        if(!pointsResponse.ok) throw new Error('load');
        const points = await pointsResponse.json();
        if(run !== generation) return;
        pointSelect.replaceChildren(new Option('Seleccionar punto',''));
        for(const point of points) {
            const option = new Option(point.name+' · '+(point.address_status==='ready'?point.address:'Dirección pendiente'),point.id);
            option.dataset.point=JSON.stringify(point); pointSelect.add(option);
        }

        if(restore && oldFields.client_branch_id) pointSelect.value=oldFields.client_branch_id;
        else if(points.length===1) pointSelect.value=String(points[0].id);
        updatePoint();
        if(restore) {
            for(const [id,name] of Object.entries(fieldNames)) if(name in oldFields) document.getElementById(id).value=oldFields[name] || '';
            for(const name of ['window_start','window_end','shift']) if(name in oldFields) document.querySelector(`[name=${name}]`).value=oldFields[name] || '';
            updateTimeRange();
        }
    } catch {
        if(run===generation) pointSelect.replaceChildren(new Option('No se pudo cargar. Vuelve a seleccionar el comerciante.',''));
    }
}
const pointDialog = document.getElementById('ret-point-dialog');
const pointForm = document.getElementById('ret-point-form');
let editingPointId = null;
function openPointDialog(complete=false) {
    const point = complete ? JSON.parse(pointSelect.selectedOptions[0]?.dataset.point || 'null') : null;
    editingPointId = point?.id || null;
    document.getElementById('ret-point-title').textContent = point ? 'Completar dirección del punto' : 'Agregar punto de retiro';
    pointForm.elements.name.value = point?.name || '';
    pointForm.elements.name.readOnly = !!point;
    pointForm.elements.address.value = point?.address || '';
    pointForm.elements.commune.value = point?.commune || '';
    document.getElementById('ret-point-error').textContent = '';
    pointDialog.showModal();
}
document.getElementById('ret-add-point').addEventListener('click',()=>openPointDialog());
document.getElementById('ret-complete-point').addEventListener('click',()=>openPointDialog(true));
document.getElementById('ret-point-close').addEventListener('click',()=>pointDialog.close());
pointForm.addEventListener('submit', async event => {
    event.preventDefault();
    if (!pointForm.checkValidity()) {pointForm.reportValidity(); return;}
    const client = clientSelect.value;
    const button = pointForm.querySelector('button');
    const retained = Object.fromEntries(['window_start','window_end','contact_name_snapshot','contact_phone_snapshot','operational_emails_snapshot'].map(name=>[name,document.querySelector(`[name="${name}"]`).value]));
    button.disabled = true;
    try {
        const response = await fetch(@json(route('fleet.requests.point.save',['client'=>'__ID__'])).replace('__ID__',encodeURIComponent(client)), {method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('.ret-form [name=_token]').value},body:JSON.stringify({point_id:editingPointId,name:pointForm.elements.name.value,address:pointForm.elements.address.value,commune:pointForm.elements.commune.value})});
        const data = await response.json();
        if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'No se pudo guardar el punto.');
        await loadPoints();
        if (clientSelect.value !== client) return;
        pointSelect.value = String(data.id); updatePoint();
        for (const [name,value] of Object.entries(retained)) if (value && value !== '00:00') document.querySelector(`[name="${name}"]`).value=value;
        updateTimeRange(); pointDialog.close();
    } catch (error) {document.getElementById('ret-point-error').textContent = error.message || 'No se pudo guardar. Intenta nuevamente.';}
    finally {button.disabled=false;}
});
clientSelect.addEventListener('change',()=>loadPoints());
pointSelect.addEventListener('change',updatePoint);
if(clientSelect.value) loadPoints(true);
</script>
@endsection
