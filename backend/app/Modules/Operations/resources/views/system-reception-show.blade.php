@extends('operations::layout')
@section('title','Recepción Sistema #'.$reception->id)
@section('content')
<a class="back" href="{{ route('operations.system-receptions.index') }}">← Volver a Recepción Sistema</a>
<h1>Recepción Sistema #{{ $reception->id }}</h1>
<p class="intro">{{ $client->commercial_name ?: $client->legal_name }} · {{ $reception->document_type === 'guia' ? 'Guía' : 'Factura' }} {{ $reception->document_number }}</p>
<section class="card">
    <div class="ope-grid">
        <div><strong>Usuario</strong><p>{{ $creator }}</p></div>
        <div><strong>Cliente</strong><p>{{ $client->commercial_name ?: $client->legal_name }} · {{ $client->tax_id }}</p></div>
        <div><strong>Documento</strong><p>{{ $reception->document_type === 'guia' ? 'Guía' : 'Factura' }} {{ $reception->document_number }}</p></div>
        <div><strong>Estado</strong><p>{{ ['awaiting_photo'=>'Falta respaldo','scanning'=>'Escaneando','completed'=>'Cerrada'][$reception->status] ?? $reception->status }}</p></div>
    </div>
    @if($reception->observations)<p><strong>Observaciones:</strong> {{ $reception->observations }}</p>@endif
    @if($reception->photo_path)<p><a href="{{ route('operations.system-receptions.photo',$reception->id) }}" target="_blank" rel="noopener">Ver respaldo fotográfico</a></p>@endif
</section>

@if($reception->status !== 'completed')
<section class="card" style="margin-top:16px">
    <h2>1. Respaldo de guía o factura</h2>
    <p class="note">Debes adjuntar una fotografía antes del primer escaneo. En el celular puedes tomarla con la cámara.</p>
    <form method="POST" action="{{ route('operations.system-receptions.photo.store',$reception->id) }}" enctype="multipart/form-data" class="ope-form">@csrf
        <label>Fotografía<input type="file" name="photo" accept="image/*" capture="environment" required></label>
        <button type="submit">{{ $reception->photo_path ? 'Reemplazar respaldo' : 'Guardar respaldo' }}</button>
    </form>
</section>
@endif

@if($reception->photo_path)
<section class="card" style="margin-top:16px">
    <h2>2. Escaneo de bultos</h2>
    <p class="note">El QR habitual se interpreta automáticamente: <code>{"o":"4N202610076109","p":"119","t":1}</code> se registra como <strong>4N202610076109-119</strong>.</p>
    @if($reception->status === 'scanning')
        @if($scanCount === 0)<details style="margin:12px 0"><summary>Configurar otro formato de QR</summary><p class="note">Si otra etiqueta trae el código dentro de un texto, indica la primera posición y cuántos caracteres tomar. El QR habitual con campos «o» y «p» no necesita esta configuración.</p>
            <form method="POST" action="{{ route('operations.system-receptions.qr-format',$reception->id) }}" class="ope-form">@csrf @method('PUT')
                <label>Posición inicial<input type="number" min="1" max="500" name="qr_start_position" value="{{ $reception->qr_start_position }}" required></label>
                <label>Cantidad de caracteres<input type="number" min="1" max="100" name="qr_length" value="{{ $reception->qr_length }}" required></label>
                <button type="submit">Guardar formato</button>
            </form>
        </details>@endif
        <div class="ope-actions"><button type="button" id="open-system-scanner">Iniciar escaneo</button><strong>Escaneados: <span id="system-scan-count">{{ $scanCount }}</span></strong></div>
        <form method="POST" action="{{ route('operations.system-receptions.complete',$reception->id) }}" id="system-complete-form">@csrf<button type="submit" id="system-complete-button" @disabled($scanCount === 0)>Cerrar recepción y enviar a Operaciones</button></form>
    @else
        <p><strong>{{ $scanCount }} bultos registrados.</strong> La recepción ya está disponible para preparar un proceso de Operaciones.</p>
        <a class="button" href="{{ route('operations.dashboard',['load'=>$reception->load_id]).'#preparar-proceso' }}">Preparar proceso con esta recepción</a>
    @endif
</section>
@endif

<section class="card" style="margin-top:16px">
    <h2>Bultos escaneados · <span id="system-scan-total">{{ $scanCount }}</span></h2>
    <p class="note">Se muestran los últimos 100. El número de cada bulto se conserva durante toda la recepción.</p>
    <div class="table-wrap"><table class="ope-table"><thead><tr><th>N.º</th><th>Código útil</th><th>QR original</th><th>Peso kg</th><th>Alto cm</th><th>Largo cm</th><th>Ancho cm</th></tr></thead><tbody id="system-scan-rows">
    @forelse($scans as $scan)<tr><td>{{ $scan->sequence }}</td><td>{{ $scan->tracking }}</td><td>{{ $scan->raw_code }}</td><td>{{ $scan->weight }}</td><td>{{ $scan->height_cm }}</td><td>{{ $scan->length_cm }}</td><td>{{ $scan->width_cm }}</td></tr>
    @empty<tr id="system-scan-empty"><td colspan="7" class="ope-empty">Aún no se ha escaneado ningún bulto.</td></tr>@endforelse
    </tbody></table></div>
</section>

@if($reception->status === 'scanning')
<style>
.system-scan-dialog{width:min(760px,calc(100vw - 20px));max-height:calc(100vh - 20px);padding:22px;border:2px solid #e7bd5d;border-radius:14px;background:#fff6d9;color:var(--ink);box-shadow:0 20px 60px rgb(0 0 0 / 25%)}
.system-scan-dialog::backdrop{background:rgb(11 34 36 / 70%)}.system-scan-dialog h2{margin:0}.system-scan-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.system-scan-count{font-size:28px;font-weight:850;color:#755000}.system-scan-dialog input{background:#fff!important}.system-scan-dialog video{display:block;width:100%;max-height:230px;object-fit:cover;border-radius:9px;background:#152426;margin:12px 0}.system-scan-dialog video[hidden]{display:none}.system-scan-feedback{min-height:28px;padding:8px 10px;border-radius:7px;background:#fff0b9;margin:12px 0}.system-scan-feedback.is-error{background:#ffd8b2;color:#852d00;font-weight:750}.system-scan-dialog .ope-form{grid-template-columns:repeat(2,minmax(0,1fr))}.system-scan-dialog .ope-form .ope-full{grid-column:1/-1}.system-scan-dialog .ope-actions{margin:4px 0}.system-scan-dialog .table-wrap{max-height:190px;overflow:auto}@media(max-width:500px){.system-scan-dialog .ope-form{grid-template-columns:1fr}}
</style>
<dialog class="system-scan-dialog" id="system-scan-dialog" aria-labelledby="system-scan-title">
    <div class="system-scan-head"><div><h2 id="system-scan-title">Escaneo de bultos</h2><p class="note" style="margin:4px 0">En el PC, el lector ingresa el código exacto. En Android o iPhone puedes usar la cámara continua o tomar una foto del QR.</p></div><button type="button" class="ope-secondary-button" id="close-system-scanner" aria-label="Cerrar ventana">✕</button></div>
    <p class="system-scan-count">Llevas <span id="modal-scan-count">{{ $scanCount }}</span> bultos</p>
    <p id="system-scan-feedback" class="system-scan-feedback" role="status" aria-live="assertive">Listo para leer el siguiente QR.</p>
    <video id="system-scan-video" playsinline muted hidden></video>
    <div class="ope-actions"><button type="button" id="system-scan-camera">Usar cámara continua</button><button type="button" id="system-scan-stop-camera" class="ope-secondary-button" hidden>Detener cámara</button><label class="button" for="system-scan-photo">Tomar foto del QR</label><input id="system-scan-photo" type="file" accept="image/*" capture="environment" aria-label="Fotografía del QR" style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none" tabindex="-1"></div>
    <form method="POST" action="{{ route('operations.system-receptions.scan',$reception->id) }}" id="system-scan-form" class="ope-form">@csrf
        <input type="hidden" name="scan_source" id="system-scan-source" value="reader">
        <label class="ope-full">Leer QR o escribir código<input id="system-scan-code" name="raw_code" required maxlength="500" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Apunta la pistola y lee el QR"></label>
        <p id="system-scan-code-result" class="ope-full note" hidden></p>
        <div id="system-scan-measures" class="ope-full ope-form" hidden>
            <label>Peso (kg)<input name="weight" type="number" step="0.001" min="0.001" required inputmode="decimal"></label>
            <label>Alto (cm)<input name="height_cm" type="number" step="0.01" min="0.01" required inputmode="decimal"></label>
            <label>Largo (cm)<input name="length_cm" type="number" step="0.01" min="0.01" required inputmode="decimal"></label>
            <label>Ancho (cm)<input name="width_cm" type="number" step="0.01" min="0.01" required inputmode="decimal"></label>
            <label class="ope-full check"><input type="checkbox" id="system-scan-repeat"> Repetir este peso y estas medidas en los siguientes bultos</label>
        </div>
        <div class="ope-full ope-actions"><button type="submit" id="system-scan-save">Verificar código</button><button type="button" id="system-scan-edit-measures" class="ope-secondary-button" hidden>Cambiar medidas repetidas</button></div>
    </form>
    <p class="note">Si un código se repite, verás el número del escaneo anterior y no se registrará otra vez.</p>
    <div class="table-wrap"><table class="ope-table"><thead><tr><th>N.º</th><th>Código</th><th>Peso</th></tr></thead><tbody id="system-scan-recent">@foreach($scans->take(8) as $scan)<tr><td>{{ $scan->sequence }}</td><td>{{ $scan->tracking }}</td><td>{{ $scan->weight }}</td></tr>@endforeach</tbody></table></div>
</dialog>
@push('scripts')
@vite('resources/js/reception-qr.js')
<script>
(() => {
    const dialog = document.getElementById('system-scan-dialog');
    const form = document.getElementById('system-scan-form');
    const code = document.getElementById('system-scan-code');
    const source = document.getElementById('system-scan-source');
    const measures = document.getElementById('system-scan-measures');
    const feedback = document.getElementById('system-scan-feedback');
    const result = document.getElementById('system-scan-code-result');
    const save = document.getElementById('system-scan-save');
    const repeat = document.getElementById('system-scan-repeat');
    const edit = document.getElementById('system-scan-edit-measures');
    const video = document.getElementById('system-scan-video');
    const cameraButton = document.getElementById('system-scan-camera');
    const stopCameraButton = document.getElementById('system-scan-stop-camera');
    const photoInput = document.getElementById('system-scan-photo');
    const scanCanvas = document.createElement('canvas');
    const scanContext = scanCanvas.getContext('2d', {willReadFrequently: true});
    const fields = ['weight', 'height_cm', 'length_cm', 'width_cm'];
    for (const name of fields) form.elements[name].disabled = true;
    const csrf = form.querySelector('input[name="_token"]').value;
    const checkUrl = @json(route('operations.system-receptions.check',$reception->id));
    let phase = 'code', busy = false, repeatedValues = null, stream = null, detector = null, cameraTimer = null, cameraLast = '', emptyFrames = 0;

    function message(text, isError = false) { feedback.textContent = text; feedback.classList.toggle('is-error', isError); }
    function focusCode() { code.focus(); code.select(); }
    function open() { if (!dialog.open) dialog.showModal(); focusCode(); }
    function reset() { phase = 'code'; code.value = ''; source.value = 'reader'; result.hidden = true; measures.hidden = true; for (const name of fields) form.elements[name].disabled = true; save.textContent = 'Verificar código'; focusCode(); }
    async function post(url, body) {
        const response = await fetch(url, {method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body});
        const data = await response.json();
        if (!response.ok) { const error = new Error(data.message || Object.values(data.errors || {})[0]?.[0] || 'No se pudo guardar.'); error.data = data; throw error; }
        return data;
    }
    function values() { return Object.fromEntries(fields.map(name => [name, form.elements[name].value])); }
    async function saveScan() {
        if (busy || !form.reportValidity()) return;
        busy = true;
        try {
            const data = await post(form.action, new FormData(form));
            if (repeat.checked) repeatedValues = values(); else repeatedValues = null;
            for (const id of ['system-scan-count', 'modal-scan-count', 'system-scan-total']) document.getElementById(id).textContent = data.count;
            document.getElementById('system-complete-button').disabled = false;
            document.getElementById('system-scan-empty')?.remove();
            const full = document.createElement('tr');
            for (const value of [data.sequence, data.tracking, code.value, data.weight, data.height_cm, data.length_cm, data.width_cm]) { const cell = document.createElement('td'); cell.textContent = value; full.append(cell); }
            document.getElementById('system-scan-rows').prepend(full);
            const recent = document.createElement('tr');
            for (const value of [data.sequence, data.tracking, data.weight]) { const cell = document.createElement('td'); cell.textContent = value; recent.append(cell); }
            document.getElementById('system-scan-recent').prepend(recent);
            message(`Bulto #${data.sequence} registrado. Lee el siguiente QR.`);
            if (navigator.vibrate) navigator.vibrate(60);
            reset();
            edit.hidden = !repeatedValues;
        } catch (error) {
            message(error.data?.duplicate_sequence ? `Código repetido: corresponde al escaneo #${error.data.duplicate_sequence}.` : error.message, true);
            if (error.data?.duplicate_sequence) reset();
        } finally { busy = false; }
    }
    async function verify(raw, scanSource = 'reader') {
        if (busy || !raw.trim()) return;
        busy = true;
        try {
            const body = new FormData(); body.append('raw_code', raw); body.append('scan_source', scanSource);
            const data = await post(checkUrl, body);
            code.value = raw;
            source.value = scanSource;
            result.textContent = `Bulto #${data.next_number}: ${data.tracking}`;
            result.hidden = false;
            phase = 'measure';
            measures.hidden = false;
            for (const name of fields) form.elements[name].disabled = false;
            save.textContent = 'Guardar bulto';
            message(`Código válido. Se registrará como bulto #${data.next_number}.`);
            if (repeat.checked && repeatedValues) {
                for (const [name, value] of Object.entries(repeatedValues)) form.elements[name].value = value;
                busy = false;
                await saveScan();
                return;
            }
            form.elements.weight.focus();
        } catch (error) {
            message(error.data?.duplicate_sequence ? `Código repetido: ya está en el escaneo #${error.data.duplicate_sequence}. Revisa ese bulto.` : error.message, true);
            phase = 'code';
            focusCode();
        } finally { busy = false; }
    }
    form.addEventListener('submit', event => { event.preventDefault(); if (phase === 'code') verify(code.value); else saveScan(); });
    code.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); if (phase === 'code') verify(code.value); else saveScan(); } });
    edit.addEventListener('click', () => { repeat.checked = false; repeatedValues = null; edit.hidden = true; message('Las próximas medidas se ingresarán manualmente.'); focusCode(); });
    document.getElementById('open-system-scanner').addEventListener('click', open);
    document.getElementById('close-system-scanner').addEventListener('click', () => dialog.close());
    document.getElementById('system-complete-form').addEventListener('submit', event => {
        const count = document.getElementById('system-scan-total').textContent;
        if (!confirm(`¿Cerrar esta recepción con ${count} bultos? Después aparecerán en el proceso de Operaciones.`)) event.preventDefault();
    });

    function stopCamera() { clearTimeout(cameraTimer); stream?.getTracks().forEach(track => track.stop()); stream = null; detector = null; video.srcObject = null; video.hidden = true; stopCameraButton.hidden = true; cameraButton.hidden = !window.isSecureContext; }
    function decodeImage(image, maxWidth = 1600) {
        if (!window.jsQR || !scanContext) return null;
        const width = image.videoWidth || image.naturalWidth || image.width;
        const height = image.videoHeight || image.naturalHeight || image.height;
        if (!width || !height) return null;
        const scale = Math.min(1, maxWidth / width);
        scanCanvas.width = Math.max(1, Math.round(width * scale));
        scanCanvas.height = Math.max(1, Math.round(height * scale));
        scanContext.drawImage(image, 0, 0, scanCanvas.width, scanCanvas.height);
        const pixels = scanContext.getImageData(0, 0, scanCanvas.width, scanCanvas.height);
        return window.jsQR(pixels.data, pixels.width, pixels.height, {inversionAttempts: 'dontInvert'})?.data || null;
    }
    async function cameraLoop() {
        if (!stream) return;
        if (phase === 'code' && !busy && video.readyState >= 2) {
            try {
                let raw;
                if (detector) {
                    const found = await detector.detect(video);
                    raw = found.find(item => item.format === 'qr_code')?.rawValue;
                } else {
                    raw = decodeImage(video, 720);
                }
                if (!raw) { if (++emptyFrames >= 2) cameraLast = ''; }
                else if (raw !== cameraLast) { cameraLast = raw; emptyFrames = 0; await verify(raw, 'camera'); }
            } catch { message('No se pudo leer el QR con esta cámara. Puedes usar la pistola o escribir el código.', true); }
        }
        cameraTimer = setTimeout(cameraLoop, 250);
    }
    cameraButton.addEventListener('click', async () => {
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) { message('La cámara continua necesita HTTPS. En esta conexión usa «Tomar foto del QR», disponible en Android y iPhone.', true); return; }
        try {
            const nativeSupported = 'BarcodeDetector' in window && (await BarcodeDetector.getSupportedFormats()).includes('qr_code');
            if (!nativeSupported && !window.jsQR) { message('El lector QR todavía se está cargando. Intenta otra vez en unos segundos.', true); return; }
            stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: {ideal: 'environment'}}, audio: false});
            detector = nativeSupported ? new BarcodeDetector({formats: ['qr_code']}) : null;
            video.srcObject = stream; video.hidden = false; await video.play();
            cameraButton.hidden = true; stopCameraButton.hidden = false; message('Cámara activa. Acerca el QR al recuadro.'); cameraLoop();
        } catch { stopCamera(); message('No se pudo abrir la cámara. Revisa el permiso del navegador o usa la pistola.', true); }
    });
    photoInput.addEventListener('change', async () => {
        const file = photoInput.files?.[0];
        if (!file) return;
        if (!window.jsQR) { message('El lector QR todavía se está cargando. Intenta otra vez en unos segundos.', true); photoInput.value = ''; return; }
        const url = URL.createObjectURL(file);
        try {
            const image = new Image();
            image.src = url;
            await image.decode();
            const raw = decodeImage(image);
            if (!raw) { message('No se encontró un QR legible en la foto. Acércalo y vuelve a tomarla.', true); return; }
            await verify(raw, 'camera');
        } catch { message('No se pudo leer la imagen. Prueba con otra foto o escribe el código.', true); }
        finally { URL.revokeObjectURL(url); photoInput.value = ''; }
    });
    stopCameraButton.addEventListener('click', stopCamera);
    dialog.addEventListener('close', stopCamera);
    document.addEventListener('visibilitychange', () => { if (document.hidden) stopCamera(); });
    if (!window.isSecureContext) { cameraButton.hidden = true; message('En esta red, toma una foto del QR para cada bulto. La lectura continua necesita HTTPS.'); }
    if (new URLSearchParams(location.search).get('scan') === '1') open();
})();
</script>
@endpush
@endif
@endsection
