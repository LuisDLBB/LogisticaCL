@extends('operations::driver-layout')
@section('title', $record->name)
@section('content')
<a href="{{ route('operations.driver.index', ['date' => $record->departure_date]) }}" class="muted">← Mis rutas</a>
<span class="eyebrow" style="display:block;margin-top:16px">{{ $record->transport_kind === 'trunk' ? 'Troncal' : 'Posta' }} · {{ $record->departure_date }}</span>
<h1>{{ $record->name }}</h1><p class="muted">Patente {{ $record->plate }} · {{ $stops->count() }} paradas</p>
@if($manualLocationAllowed)<div class="notice manual-location-note" hidden>Prueba local sin HTTPS: escribe la latitud y longitud del lugar. Puedes consultarlas en Google Maps. Quedarán identificadas como <strong>manuales</strong> en el registro, sin presentarlas como GPS capturado.</div>@endif
@if($record->start_location_source === 'manual')<p><span class="status pending">Ubicación inicial ingresada manualmente</span></p>@endif
<p><a class="button full" href="{{ route('operations.driver.guides', $record->id) }}">Ver todas las guías de esta ruta</a></p>
<details class="card"><summary><strong>Guías Bsale disponibles para fiscalización</strong></summary>
@foreach($stops as $stop)
<p><strong>{{ $stop->sequence }}. {{ $stop->name }}</strong><br>
@forelse($bsaleByGuide->get($stop->guide_id, collect())->where('version', $stop->guide_version) as $sheet)
GDE N.º {{ $sheet->numero }} · Hoja {{ $sheet->sheet_number }} de {{ $sheet->sheet_count }}
@if($sheet->url_pdf && in_array(parse_url($sheet->url_pdf, PHP_URL_SCHEME), ['http','https'], true))<a href="{{ $sheet->url_pdf }}" target="_blank" rel="noopener noreferrer">Ver PDF</a>@endif<br>
@empty<span class="muted">Sin GDE Bsale generada.</span>@endforelse</p>
@endforeach
</details>
@php($activeStop = $stops->first(fn ($stop) => $stop->status !== 'completed'))
<section class="card"><div class="row"><h2>Avance del recorrido</h2><span class="status">{{ $stops->where('status', 'completed')->count() }} / {{ $stops->count() }}</span></div>
@foreach($stops as $stop)
<div class="stop"><span class="bubble {{ $stop->status === 'completed' ? 'done' : '' }}">{{ $stop->status === 'completed' ? '✓' : $stop->sequence }}</span><div><strong>{{ $stop->name }}</strong><small>{{ $stop->address }} · {{ $stop->commune }}</small><br><small>{{ ['pending'=>'Pendiente','arrived'=>'Descargando','completed'=>'Entregada'][$stop->status] ?? $stop->status }}{{ $stop->arrival_location_source === 'manual' ? ' · ubicación manual' : '' }}</small><br><a href="{{ route('operations.driver.guides', $record->id) }}#guia-{{ $stop->sequence }}" target="_blank" rel="noopener">Ver guías de esta descarga</a></div></div>
@endforeach</section>
@if($record->status === 'assigned')
<section class="card"><span class="eyebrow">Paso 1</span><h2>Preparar jornada</h2><p class="muted">Comprueba el vehículo antes de cargar y salir. La hora se registra al guardar.</p>
<form method="POST" action="{{ route('operations.driver.start', $record->id) }}" enctype="multipart/form-data" data-offline>@csrf<input type="hidden" name="location_source" value="gps">
<label class="field">Patente asignada<input name="plate" value="{{ $record->plate }}" required maxlength="12" autocapitalize="characters"></label>
<label class="field">Kilometraje inicial<input name="start_odometer" type="number" inputmode="numeric" min="0" required></label>
<div class="grid geo-fields"><label class="field">Latitud<input name="latitude" type="number" step="any" readonly required></label><label class="field">Longitud<input name="longitude" type="number" step="any" readonly required></label></div>
<button type="button" class="secondary locate" style="margin-top:10px">Capturar ubicación</button>
<label class="field">Fotos del estado del vehículo (máximo 5)<input name="vehicle_photos[]" type="file" accept="image/*" capture="environment" multiple></label>
<label class="check"><input type="checkbox" name="vehicle_no_observations" value="1">Sin observaciones</label>
<label class="field">Observación del vehículo<textarea name="vehicle_observation" placeholder="Describe cualquier daño o problema"></textarea></label>
<button class="full">Iniciar jornada</button></form></section>
@elseif($record->status === 'in_progress' && $activeStop)
<section class="card route-card"><span class="eyebrow">Parada {{ $activeStop->sequence }} de {{ $stops->count() }}</span><h2>{{ $activeStop->name }}</h2><p>{{ $activeStop->address }} · {{ $activeStop->commune }}</p>
<p><a class="button secondary full" href="{{ route('operations.driver.guides', $record->id) }}#guia-{{ $activeStop->sequence }}" target="_blank" rel="noopener">Ver guías de esta descarga</a></p>
@if(!$activeStop->leg_started_at)
<form method="POST" action="{{ route('operations.driver.depart', [$record->id, $activeStop->id]) }}" data-offline>@csrf<button class="full">Iniciar trayecto a esta agencia</button></form>
@elseif($activeStop->status === 'pending')
<p class="muted">Trayecto iniciado {{ \Illuminate\Support\Carbon::parse($activeStop->leg_started_at)->timezone('America/Santiago')->format('d/m H:i') }}</p>
<form method="POST" action="{{ route('operations.driver.arrive', [$record->id, $activeStop->id]) }}" data-offline>@csrf<input type="hidden" name="location_source" value="gps">
<div class="grid geo-fields"><label class="field">Latitud<input name="latitude" type="number" step="any" readonly required></label><label class="field">Longitud<input name="longitude" type="number" step="any" readonly required></label></div>
<button type="button" class="secondary locate" style="margin:10px 0">Capturar ubicación</button><button class="full">Llegué a la agencia · iniciar descarga</button></form>
@elseif($activeStop->status === 'arrived')
<p class="muted">Llegada {{ \Illuminate\Support\Carbon::parse($activeStop->arrived_at)->timezone('America/Santiago')->format('d/m H:i') }}. Registra la entrega antes de continuar.</p>
<form method="POST" action="{{ route('operations.driver.complete', [$record->id, $activeStop->id]) }}" enctype="multipart/form-data" data-offline>@csrf
<label class="field">Observación de entrega<textarea name="observation" placeholder="Opcional"></textarea></label>
<label class="field">Foto de entrega (opcional)<input type="file" name="delivery_photo" accept="image/*" capture="environment"></label>
<h3>Firma de guía</h3><p class="muted">Usa una foto de la guía firmada en papel o pide la firma en pantalla.</p>
<label class="field">Foto de la guía firmada<input type="file" name="signed_guide_photo" accept="image/*" capture="environment"></label>
<div class="field"><label>Firma en pantalla</label><canvas class="signature" width="640" height="300" aria-label="Área para firmar"></canvas><input type="hidden" name="signature_data"><button type="button" class="secondary clear-signature">Borrar firma</button></div>
<h3>Devoluciones recogidas</h3>
<label class="field">Cantidad de bultos<input name="return_count" type="number" inputmode="numeric" min="0" value="0" required></label>
<label class="field">Detalle u observación<textarea name="return_observation" placeholder="Opcional"></textarea></label>
<label class="field">Foto de devoluciones (obligatoria si recoges bultos)<input type="file" name="return_photo" accept="image/*" capture="environment"></label>
<label class="field">Chofer responsable de las devoluciones<select name="return_receiver"><option value="self" selected>YO · {{ $driver->name }}</option>@foreach($drivers as $recipient)<option value="{{ $recipient->id }}">{{ $recipient->name }}</option>@endforeach</select></label>
<p class="muted">Por defecto las llevas tú. Si eliges otro chofer, él deberá confirmar el traspaso.</p>
<button class="full">Terminar descarga y continuar</button></form>
@endif</section>
@elseif($record->status === 'in_progress')
<section class="card"><h2>Todos los destinos completados</h2><form method="POST" action="{{ route('operations.driver.finish', $record->id) }}" data-offline>@csrf<label class="field">Kilometraje final<input name="end_odometer" type="number" inputmode="numeric" min="{{ $record->start_odometer }}" required></label><button class="full">Finalizar recorrido</button></form></section>
@else
<div class="success">Recorrido finalizado {{ \Illuminate\Support\Carbon::parse($record->finished_at)->timezone('America/Santiago')->format('d/m/Y H:i') }}. Kilometraje: {{ $record->start_odometer }} → {{ $record->end_odometer }}.</div>
@endif
@if($record->status !== 'completed')
<section class="card offline-only" hidden><span class="eyebrow">Trabajo sin señal</span><h2>Registro temporal por parada</h2><p class="muted">Usa estas acciones en orden. Cada registro quedará pendiente en este teléfono hasta que el servidor confirme la sincronización. No cierres la sesión ni borres los datos del navegador.</p>
@foreach($stops as $stop)
<details class="card"><summary><strong>{{ $stop->sequence }}. {{ $stop->name }}</strong> · {{ $stop->commune }}</summary>
<p><a href="{{ route('operations.driver.guides', $record->id) }}#guia-{{ $stop->sequence }}" target="_blank" rel="noopener">Ver guías de esta descarga</a></p>
<form method="POST" action="{{ route('operations.driver.depart', [$record->id, $stop->id]) }}" data-offline>@csrf<button class="full">1 · Iniciar trayecto</button></form>
<form method="POST" action="{{ route('operations.driver.arrive', [$record->id, $stop->id]) }}" data-offline style="margin-top:10px">@csrf<input type="hidden" name="location_source" value="gps"><div class="grid geo-fields"><label class="field">Latitud<input name="latitude" type="number" step="any" readonly required></label><label class="field">Longitud<input name="longitude" type="number" step="any" readonly required></label></div><button type="button" class="secondary locate" style="margin:10px 0">Capturar ubicación</button><button class="full">2 · Llegada e inicio de descarga</button></form>
<form method="POST" action="{{ route('operations.driver.complete', [$record->id, $stop->id]) }}" enctype="multipart/form-data" data-offline style="margin-top:18px">@csrf
<label class="field">Observación de entrega<textarea name="observation"></textarea></label>
<label class="field">Foto de entrega<input type="file" name="delivery_photo" accept="image/*" capture="environment"></label>
<label class="field">Foto de guía firmada<input type="file" name="signed_guide_photo" accept="image/*" capture="environment"></label>
<div class="field"><label>O firma en pantalla</label><canvas class="signature" width="640" height="300" aria-label="Área para firmar"></canvas><input type="hidden" name="signature_data"><button type="button" class="secondary clear-signature">Borrar firma</button></div>
<label class="field">Devoluciones recogidas<input name="return_count" type="number" inputmode="numeric" min="0" value="0" required></label>
<label class="field">Observación de devoluciones<textarea name="return_observation"></textarea></label>
<label class="field">Foto de devoluciones<input type="file" name="return_photo" accept="image/*" capture="environment"></label>
<label class="field">Chofer responsable de las devoluciones<select name="return_receiver"><option value="self" selected>YO · {{ $driver->name }}</option>@foreach($drivers as $recipient)<option value="{{ $recipient->id }}">{{ $recipient->name }}</option>@endforeach</select></label>
<button class="full">3 · Terminar descarga</button></form>
</details>
@endforeach
<form method="POST" action="{{ route('operations.driver.finish', $record->id) }}" data-offline>@csrf<label class="field">Kilometraje final<input name="end_odometer" type="number" inputmode="numeric" min="0" required></label><button class="full">Finalizar tras la última parada</button></form>
</section>
@endif
@if($stops->where('status', 'completed')->isNotEmpty())
<section class="card"><h2>Entregas registradas</h2>@foreach($stops->where('status', 'completed') as $stop)
<div class="stop"><span class="bubble done">✓</span><div><strong>{{ $stop->name }}</strong><small>{{ \Illuminate\Support\Carbon::parse($stop->arrived_at)->timezone('America/Santiago')->format('H:i') }} llegada · {{ \Illuminate\Support\Carbon::parse($stop->departed_at)->timezone('America/Santiago')->format('H:i') }} salida · {{ $stop->return_count }} devoluciones</small>@if($stop->return_count > 0)<br><small>Asignadas al recoger: {{ $transfers->firstWhere('stop_id', $stop->id)?->recipient_name ?? 'YO · '.$driver->name }}</small>@endif<div class="evidence">@foreach($evidence->get($stop->id, collect()) as $file)<a href="{{ route('operations.driver.evidence', [$record->id, $file->id]) }}" target="_blank" rel="noopener">{{ ['delivery'=>'Entrega','return'=>'Devolución','signed_guide'=>'Guía firmada','signature'=>'Firma digital'][$file->type] ?? 'Foto' }}</a>@endforeach</div></div></div>
@endforeach</section>
@endif
@if($returnBalance['collected'] + $returnBalance['received'] > 0)
<section class="card"><h2>Devoluciones hacia Santiago</h2><p class="muted">Recogidas: {{ $returnBalance['collected'] }} · recibidas de otra ruta: {{ $returnBalance['received'] }} · asignadas a otro chofer: {{ $returnBalance['transferred'] }} · traspasos confirmados: {{ $returnBalance['confirmed'] }} · entregadas en bodega: {{ $returnBalance['warehouse'] }} · aún contigo: {{ $returnBalance['in_custody'] }}</p>
@if($returnBalance['available'] > 0)<div class="success"><strong>{{ $returnBalance['available'] }} bultos asignados a YO.</strong> Si no haces un traspaso, tú los llevarás a la bodega de Santiago.</div>@endif
@foreach($transfers as $transfer)<div class="stop"><span class="bubble">↔</span><div><strong>{{ $transfer->package_count }} bultos → {{ $transfer->recipient_name }}</strong><small>{{ $transfer->stop_name ? 'Recogidos en '.$transfer->stop_name.' · ' : '' }}{{ $transfer->status === 'received' ? 'Recepción confirmada' : 'Esperando confirmación del chofer' }}</small></div></div>@endforeach
@foreach($warehouseReturns as $receipt)<div class="stop"><span class="bubble done">✓</span><div><strong>{{ $receipt->package_count }} bultos entregados en bodega</strong><small>{{ \Illuminate\Support\Carbon::parse($receipt->recorded_at)->timezone('America/Santiago')->format('d/m/Y H:i') }}{{ $receipt->location_source === 'manual' ? ' · ubicación manual' : '' }}</small><br><a href="{{ route('operations.driver.warehouse.photo', [$record->id, $receipt->id]) }}" target="_blank" rel="noopener">Ver respaldo fotográfico</a></div></div>@endforeach
@if($returnBalance['available'] > 0 && $drivers->isNotEmpty())
<details><summary><strong>Entregar bultos a otro chofer</strong></summary>
<form method="POST" action="{{ route('operations.driver.transfers.store', $record->id) }}" data-offline>@csrf
<label class="field">Chofer que recibe<select name="to_driver_id" required><option value="">Seleccionar chofer</option>@foreach($drivers as $recipient)<option value="{{ $recipient->id }}">{{ $recipient->name }}</option>@endforeach</select></label>
<label class="field">Bultos a traspasar<input name="package_count" type="number" inputmode="numeric" min="1" max="{{ $returnBalance['available'] }}" required></label>
<label class="field">Observación<textarea name="observation" placeholder="Punto de encuentro u observación"></textarea></label>
<button class="full">Registrar traspaso</button></form></details>
@endif
@if($returnBalance['available'] > 0 && $record->status === 'completed')
<h3>Entrega final en bodega de Santiago</h3>
<form method="POST" action="{{ route('operations.driver.warehouse.store', $record->id) }}" enctype="multipart/form-data" data-offline>@csrf<input type="hidden" name="location_source" value="gps">
<label class="field">Bultos entregados<input name="package_count" type="number" inputmode="numeric" min="1" max="{{ $returnBalance['available'] }}" required></label>
<label class="field">Foto de entrega en bodega<input type="file" name="photo" accept="image/*" capture="environment" required></label>
<div class="grid geo-fields"><label class="field">Latitud<input name="latitude" type="number" step="any" readonly required></label><label class="field">Longitud<input name="longitude" type="number" step="any" readonly required></label></div>
<button type="button" class="secondary locate" style="margin:10px 0">Capturar ubicación</button>
<label class="field">Observación<input name="observation" maxlength="2000"></label>
<button class="full">Registrar entrega en bodega</button></form>
@endif</section>
@endif
@endsection
@push('scripts')
<script>
const manualLocationMode = {{ $manualLocationAllowed ? 'true' : 'false' }} && !window.isSecureContext;
if (manualLocationMode) {
    document.querySelectorAll('.manual-location-note').forEach(note => note.hidden = false);
    document.querySelectorAll('.geo-fields').forEach(fields => {
        const form = fields.closest('form');
        form.querySelector('[name="location_source"]').value = 'manual';
        fields.querySelectorAll('input').forEach(input => { input.readOnly = false; input.inputMode = 'decimal'; input.placeholder = input.name === 'latitude' ? 'Ej.: -33.4369' : 'Ej.: -70.6908'; });
        form.querySelector('.locate').style.display = 'none';
    });
}
document.querySelectorAll('.locate').forEach(button => button.addEventListener('click', () => {
    if (!window.isSecureContext) { alert('El navegador exige HTTPS para capturar GPS. Esta dirección HTTP solo permite coordenadas manuales durante la prueba local.'); return; }
    if (!navigator.geolocation) { alert('Este dispositivo no permite obtener ubicación.'); return; }
    button.disabled = true;
    navigator.geolocation.getCurrentPosition(position => {
        const form = button.closest('form');
        form.querySelector('[name="latitude"]').value = position.coords.latitude.toFixed(7);
        form.querySelector('[name="longitude"]').value = position.coords.longitude.toFixed(7);
        button.textContent = 'Ubicación capturada'; button.disabled = false;
    }, () => { button.disabled = false; alert('Activa el permiso de ubicación para registrar este paso.'); }, {enableHighAccuracy:true, timeout:15000});
}));
document.querySelectorAll('.signature').forEach(canvas => {
    const context = canvas.getContext('2d'); context.lineWidth = 3; context.lineCap = 'round'; context.strokeStyle = '#12363a';
    let drawing = false; let signed = false;
    const point = event => { const rect = canvas.getBoundingClientRect(); return {x:(event.clientX-rect.left)*canvas.width/rect.width,y:(event.clientY-rect.top)*canvas.height/rect.height}; };
    canvas.addEventListener('pointerdown', event => { drawing = true; canvas.setPointerCapture(event.pointerId); const p=point(event); context.beginPath(); context.moveTo(p.x,p.y); });
    canvas.addEventListener('pointermove', event => { if(!drawing) return; const p=point(event); context.lineTo(p.x,p.y); context.stroke(); signed=true; });
    canvas.addEventListener('pointerup', () => drawing=false);
    canvas.closest('form').querySelector('.clear-signature').addEventListener('click', () => {context.clearRect(0,0,canvas.width,canvas.height);signed=false;canvas.closest('form').querySelector('[name="signature_data"]').value='';});
    canvas.closest('form').addEventListener('submit', () => { if(signed) canvas.closest('form').querySelector('[name="signature_data"]').value=canvas.toDataURL('image/png'); });
});
</script>
@endpush
