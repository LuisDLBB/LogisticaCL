@extends('provider-payments::layout')
@section('title', 'Enviar prefacturas · '.$period)
@php
    $defaultSubject = 'Pre-factura servicios {mandantes} {mes} - {proveedor}';
    $defaultBody = <<<'MAIL'
Estimado(a) Proveedor:

Adjuntamos las pre-facturas de servicios correspondientes a {mes} y sus respaldos en Excel. Órdenes de compra: {ocs}.

El Excel detalla los servicios y movimientos considerados. Cuando corresponda, incluye entregas registradas a través de la aplicación y datos del sistema Geolize. Revise la empresa mandante indicada en cada pre-factura antes de emitir su documento.

Agradeceremos que nos haga llegar el documento tributario correspondiente a más tardar el {plazo}, para cerrar la facturación de {mes}. Una vez emitido, envíe el PDF a proveedores@4nlogistica.cl y natalialeyton@4nlogistica.cl.

SOLICITAMOS SU MÁXIMA COLABORACIÓN PARA LA EMISIÓN DE DOCUMENTOS DE {mes_mayusculas}. SI EXISTE ALGUNA DIFERENCIA, POR FAVOR INFÓRMELA; LOS AJUSTES QUE CORRESPONDAN PODRÁN CANALIZARSE EN UN DOCUMENTO POSTERIOR PARA EVITAR ATRASOS EN LOS PAGOS.

Le recordamos que el documento debe emitirse durante {mes} para mantener la correcta gestión administrativa y los plazos de pago comunicados. Si se emite en el mes siguiente, la fecha de pago se ajustará según corresponda. Por favor, no modifique ni retroceda la fecha de emisión en el SII para cumplir con este plazo; se considerará igualmente un documento emitido fuera de plazo.

Saludos cordiales,
Equipo de Proveedores
4 Nortes Logística SpA
MAIL;
@endphp
@push('styles')
<style>
.mail-stack{display:grid;gap:16px}.mail-card{padding:18px}.mail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px}.mail-field{display:grid;gap:6px;font-size:13px;font-weight:700}.mail-field input,.mail-field textarea,.mail-field select{width:100%;padding:10px;border:1px solid var(--line);border-radius:7px;background:#fff;color:var(--ink)}.mail-field textarea{min-height:105px;resize:vertical}.mail-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px}.mail-table{overflow:auto}.mail-table table{min-width:850px}.mail-table td:last-child,.mail-table th:last-child{text-align:left}.mail-table details{min-width:180px}.mail-table summary{cursor:pointer;color:var(--turquoise-dark);font-weight:700}.mail-table form{margin-top:12px;min-width:350px}.mail-table .mail-field{margin:9px 0}.mail-good{color:#126a42;font-weight:800}.mail-missing{color:#ab521f;font-weight:800}.mail-progress{margin-top:12px}.mail-progress progress{display:block;width:100%;margin:6px 0}.mail-preview{margin-top:14px;padding:14px;border:1px solid var(--line);border-radius:7px;background:#f7fbfb}.mail-preview pre{white-space:pre-wrap;font:inherit;margin:8px 0 0}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.courier-movements.compile.purchase-orders', ['period' => $period]) }}">← Órdenes de compra</a>
<p class="eyebrow">Gestión operacional</p><h1>Enviar prefacturas · {{ $period }}</h1>
<p class="intro">Un correo por proveedor, con el PDF y Excel de todas sus órdenes de compra. Puedes enviarlo individualmente o a todos los proveedores con correo registrado.</p>
@if(session('status'))<p class="warning">{{ session('status') }}</p>@endif
@if($errors->any())<p class="warning">{{ $errors->first() }}</p>@endif
<div class="mail-stack">
    <section class="card mail-card">
        <h2>1. Configurar correo Microsoft 365</h2>
        <p class="note">Remitente: <strong>proveedores@4nlogistica.cl</strong>. La clave se guarda cifrada y solo se puede ingresar desde este equipo; nunca se vuelve a mostrar.</p>
        <p class="{{ $configured ? 'mail-good' : 'mail-missing' }}">{{ $configured ? 'Credencial configurada' : 'Falta ingresar la credencial' }}</p>
        <form method="post" action="{{ route('provider-payments.courier-movements.compile.purchase-orders.mail.configure') }}">
            @csrf<input type="hidden" name="period" value="{{ $period }}">
            <label class="mail-field" style="max-width:420px">Clave de proveedores@4nlogistica.cl
                <input type="password" name="password" autocomplete="new-password" required>
            </label>
            <div class="mail-actions"><button type="submit">{{ $configured ? 'Cambiar credencial' : 'Guardar credencial' }}</button></div>
        </form>
    </section>
    <section class="card mail-card">
        <h2>2. Probar con DS GROUP SPA</h2>
        <p>La prueba se enviará solo a <strong>luisdelabarra@gmail.com</strong>, con todas las OC de DS GROUP del período y sus PDF y Excel.</p>
        <div class="mail-actions">
            <form method="post" action="{{ route('provider-payments.courier-movements.compile.purchase-orders.mail.test') }}">
                @csrf<input type="hidden" name="period" value="{{ $period }}">
                <button type="submit" @disabled(! $configured)>Enviar prueba</button>
            </form>
            @if($test?->status === 'sent')
                <form method="post" action="{{ route('provider-payments.courier-movements.compile.purchase-orders.mail.confirm') }}">
                    @csrf<input type="hidden" name="period" value="{{ $period }}">
                    <button type="submit" @disabled($test?->confirmed_at)>{{ $test?->confirmed_at ? 'Recepción confirmada' : 'Confirmo que recibí y revisé la prueba' }}</button>
                </form>
            @endif
        </div>
        <p class="note">{{ $test?->confirmed_at ? 'Prueba confirmada. Los envíos a proveedores están habilitados.' : ($test?->status === 'sent' ? 'Prueba enviada. Confirma su recepción antes de continuar.' : 'Aún no se ha confirmado una prueba para este período.') }}</p>
        @if($test?->status === 'failed_smtp_auth')
            <p class="warning">Microsoft 365 informó que <strong>SMTP autenticado está desactivado</strong> para proveedores@4nlogistica.cl. Un administrador debe habilitar «Authenticated SMTP» para ese buzón y luego repetir la prueba. <a href="https://learn.microsoft.com/es-es/exchange/clients-and-mobile-in-exchange-online/authenticated-client-smtp-submission" target="_blank" rel="noopener">Ver instrucciones de Microsoft</a>.</p>
        @endif
    </section>
    <section class="card mail-card">
        <h2>3. Título y mensaje</h2>
        <p class="note">Puedes usar {proveedor}, {mandantes}, {mes}, {mes_mayusculas}, {periodo}, {plazo} y {ocs}; se reemplazarán por los datos de cada proveedor. Estos textos se usan en el envío masivo y se pueden editar en cada envío individual.</p>
        <p class="note">En todos los envíos a proveedores se copiará a <strong>marcelo@4nlogistica.cl</strong>, <strong>hansdelabarra@4nlogistica.cl</strong>, <strong>natalialeyton@4nlogistica.cl</strong> y <strong>luisdelabarra@4nlogistica.cl</strong>.</p>
        <div class="mail-grid">
            <label class="mail-field">Asunto
                <input id="mail-subject" value="{{ $defaultSubject }}" maxlength="200">
            </label>
            <label class="mail-field">Mensaje
                <textarea id="mail-body">{{ $defaultBody }}</textarea>
            </label>
        </div>
        <div class="mail-preview">
            <label class="mail-field" style="max-width:420px">Vista previa para
                <select id="mail-preview-provider">
                    @foreach($groups as $group)
                        <option value="{{ $group['rut'] }}" @selected($group['rut'] === '77201525-9')>{{ $group['name'] }}</option>
                    @endforeach
                </select>
            </label>
            <p><strong>Para:</strong> <span id="mail-preview-to"></span></p>
            <p><strong>Copia:</strong> marcelo@4nlogistica.cl, hansdelabarra@4nlogistica.cl, natalialeyton@4nlogistica.cl, luisdelabarra@4nlogistica.cl</p>
            <p><strong>Asunto:</strong> <span id="mail-preview-subject"></span></p>
            <strong>Mensaje:</strong><pre id="mail-preview-body"></pre>
        </div>
        <div class="mail-actions">
            <button id="mail-send-all" type="button" @disabled(! $test?->confirmed_at)>Enviar a todos los proveedores pendientes con correo</button>
            <span class="note">{{ $groups->count() }} proveedores · {{ $missingCount }} sin correo registrado · {{ $sentCount }} ya enviados</span>
        </div>
        <div class="mail-progress" id="mail-progress" hidden><progress id="mail-progress-bar" max="1" value="0"></progress><span id="mail-progress-text"></span></div>
        <ul id="mail-results"></ul>
    </section>
    <section class="card mail-card">
        <h2>Proveedores y órdenes de compra</h2>
        <div class="mail-table">
            <table>
                <thead><tr><th>Proveedor</th><th>RUT</th><th>Correo registrado</th><th>OC y adjuntos</th><th>Estado / acción</th></tr></thead>
                <tbody>
                @foreach($groups as $group)
                    <tr>
                        <td>{{ $group['name'] }}</td>
                        <td>{{ $group['rut'] }}</td>
                        <td class="{{ $group['emails'] === [] ? 'mail-missing' : '' }}">{{ $group['emails'] === [] ? 'Sin correo registrado' : implode(', ', $group['emails']) }}@if($group['emails'] === [])<br><a href="{{ route('provider-payments.maintainers.proveedores') }}">Completar en Proveedores</a>@endif</td>
                        <td>{{ implode(', ', $group['ocs']) }}<br><small>{{ count($group['ocs']) * 2 }} archivos</small></td>
                        <td>
                            @if($group['sent_at'])
                                <span class="mail-good">Enviado {{ $group['sent_at'] }}</span>
                            @else
                                <details>
                                    <summary>Enviar individualmente</summary>
                                    <form method="post" action="{{ route('provider-payments.courier-movements.compile.purchase-orders.mail.send') }}" class="mail-individual">
                                        @csrf<input type="hidden" name="period" value="{{ $period }}">
                                        <input type="hidden" name="rut_proveedor" value="{{ $group['rut'] }}">
                                        <label class="mail-field">Asunto<input name="subject" value="{{ $defaultSubject }}" maxlength="200" required></label>
                                        <label class="mail-field">Mensaje<textarea name="body" required>{{ $defaultBody }}</textarea></label>
                                        <label class="mail-field">Otros correos (separados por coma)<input name="additional_emails" type="text" placeholder="correo1@ejemplo.cl, correo2@ejemplo.cl"></label>
                                        <p class="note">Copia fija: marcelo@4nlogistica.cl, hansdelabarra@4nlogistica.cl, natalialeyton@4nlogistica.cl y luisdelabarra@4nlogistica.cl.</p>
                                        <button type="submit" @disabled(! $test?->confirmed_at)>Enviar {{ count($group['ocs']) }} OC</button>
                                    </form>
                                </details>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
<script>
const previewGroups = @json($groups);
const previewProvider = document.getElementById('mail-preview-provider');
function updateMailPreview() {
    const group = previewGroups.find(item => item.rut === previewProvider.value);
    if (!group) return;
    const values = {'{periodo}': @json($period), '{mes}': @json($periodLabel), '{mes_mayusculas}': @json($periodUpper), '{plazo}': @json($deadline), '{mandantes}': group.mandantes, '{proveedor}': group.name, '{ocs}': group.ocs.join(', ')};
    const render = template => Object.entries(values).reduce((text, [placeholder, value]) => text.replaceAll(placeholder, value), template);
    document.getElementById('mail-preview-to').textContent = group.emails.length ? group.emails.join(', ') : 'Sin correo registrado';
    document.getElementById('mail-preview-subject').textContent = render(document.getElementById('mail-subject').value);
    document.getElementById('mail-preview-body').textContent = render(document.getElementById('mail-body').value);
}
previewProvider?.addEventListener('change', updateMailPreview);
document.getElementById('mail-subject')?.addEventListener('input', updateMailPreview);
document.getElementById('mail-body')?.addEventListener('input', updateMailPreview);
updateMailPreview();
document.querySelectorAll('.mail-individual').forEach(form => {
    form.addEventListener('submit', event => {
        if (!window.confirm('¿Enviar ahora todas las OC de este proveedor a los correos indicados?')) event.preventDefault();
    });
});
const sendAll = document.getElementById('mail-send-all');
sendAll?.addEventListener('click', async () => {
    const providers = @json($bulkGroups);
    if (!providers.length) return;
    if (!window.confirm('¿Enviar correos a ' + providers.length + ' proveedores? Cada uno recibirá todas sus OC.')) return;
    sendAll.disabled = true;
    const progress = document.getElementById('mail-progress');
    const bar = document.getElementById('mail-progress-bar');
    const label = document.getElementById('mail-progress-text');
    const results = document.getElementById('mail-results');
    progress.hidden = false;
    bar.max = providers.length;
    for (let index = 0; index < providers.length; index++) {
        const provider = providers[index];
        label.textContent = 'Enviando ' + (index + 1) + ' de ' + providers.length + ': ' + provider.name;
        const data = new FormData();
        data.append('_token', @json(csrf_token()));
        data.append('period', @json($period));
        data.append('rut_proveedor', provider.rut);
        data.append('subject', document.getElementById('mail-subject').value);
        data.append('body', document.getElementById('mail-body').value);
        try {
            const response = await fetch(@json(route('provider-payments.courier-movements.compile.purchase-orders.mail.send')), {
                method: 'POST', body: data, headers: {'Accept': 'application/json'}
            });
            const result = await response.json();
            const item = document.createElement('li');
            item.textContent = provider.name + ': ' + (response.ok ? 'enviado' : (result.message || 'no enviado'));
            results.append(item);
        } catch {
            const item = document.createElement('li');
            item.textContent = provider.name + ': no se pudo completar el envío';
            results.append(item);
        }
        bar.value = index + 1;
    }
    label.textContent = 'Envío finalizado. Actualiza la página para ver el estado de cada proveedor.';
});
</script>
@endsection
