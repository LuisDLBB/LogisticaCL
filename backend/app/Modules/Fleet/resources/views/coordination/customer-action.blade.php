<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><title>Aviso de retiro · 4N</title><link rel="stylesheet" href="{{ asset('css/fleet.css') }}"></head>
<body><main class="card" style="max-width:640px;margin:24px auto;padding:20px">
<h1>{{ $action === 'cancel' ? 'No tengo carga para retirar' : 'Corregir carga del retiro' }}</h1>
<p>{{ $ret->client_name_snapshot }} · <strong>{{ $ret->ret_code }}</strong> · {{ $ret->service_date->format('d-m-Y') }}</p>
@if($action === 'cancel')
<p>Al confirmar se cancelará únicamente el retiro de esta fecha. La programación fija se conserva.</p>
@else
<p>Carga vigente: <strong>{{ $ret->packages }} bultos</strong>. La cantidad se actualizará al enviar tu respuesta y tu ejecutivo recibirá el aviso para coordinar el retiro.</p>
<div class="ret-agenda-notice"><strong>{{ $correctionExpired ? 'El plazo de 20 minutos terminó.' : 'Tienes 20 minutos desde el envío del aviso.' }}</strong> Si no recibimos nuevos antecedentes dentro de ese plazo, se considerará la cantidad de bultos informada en la solicitud.@unless($correctionExpired)<br>Envía tus indicaciones antes de las <strong>{{ $correctionDeadline->timezone('America/Santiago')->format('H:i') }}</strong>.@endunless</div>
@endif
@if($errors->any())<div class="notice" role="alert">{{ $errors->first() }}</div>@endif
@if($action === 'cancel' || ! $correctionExpired)
<form method="post" action="{{ route('fleet.customer.act',['token'=>$token,'action'=>$action]) }}" class="ret-form">@csrf
@if($action === 'correction')
<label>Nueva cantidad de bultos <span class="ret-required">*</span><input type="number" name="packages" id="correction-packages" min="1" max="1000" step="1" value="{{ old('packages', $ret->packages) }}" required></label>
<fieldset style="margin:14px 0"><legend>¿Cómo quieres informar el peso?</legend>
<label style="display:block;margin:8px 0"><input type="radio" name="weight_mode" value="total" @checked(old('weight_mode', 'total') === 'total')> Ingresar el total de kilos de todos los bultos</label>
<label style="display:block;margin:8px 0"><input type="radio" name="weight_mode" value="individual" @checked(old('weight_mode', 'total') === 'individual')> Ingresar el peso de cada bulto</label>
</fieldset>
<div id="total-weight-section" @if(old('weight_mode', 'total') !== 'total') hidden @endif><label>Peso total de todos los bultos (kg, opcional)<input type="number" name="total_weight_kg" id="total-weight-input" min="0.001" max="100000000" step="any" inputmode="decimal" value="{{ old('total_weight_kg') }}" @disabled(old('weight_mode', 'total') !== 'total')></label></div>
<fieldset id="individual-weight-section" @if(old('weight_mode', 'total') !== 'individual') hidden @endif style="margin:14px 0"><legend>Peso de cada bulto (kg, opcional)</legend>
<div id="package-weights" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px">
@for($i = 0; $i < min(1000, max(1, (int) old('packages', $ret->packages))); $i++)
<label>Bulto {{ $i + 1 }}<input type="number" name="package_weights[]" min="0.001" max="100000" step="any" inputmode="decimal" value="{{ old('package_weights.'.$i) }}" @disabled(old('weight_mode', 'total') !== 'individual')></label>
@endfor
</div><p>Peso total informado: <strong id="total-weight">—</strong> kg</p></fieldset>
@endif
<label>{{ $action === 'cancel' ? 'Motivo / detalle' : 'Nuevas indicaciones (opcional)' }}<textarea name="reason" rows="3" maxlength="2000" @required($action === 'cancel')>{{ old('reason') }}</textarea></label>
<label style="display:block;margin-top:12px"><input type="checkbox" name="confirmed" value="1" required> Confirmo que deseo {{ $action === 'cancel' ? 'cancelar este retiro porque no tengo carga' : 'enviar esta solicitud de corrección' }}.</label>
<p><button>{{ $action === 'cancel' ? 'Confirmar cancelación' : 'Enviar carga e indicaciones' }}</button></p>
</form>
@else
<p>Se mantienen <strong>{{ $ret->packages }} bultos</strong>. Si necesitas ayuda, contacta a tu ejecutivo de Postventa.</p>
@endif
</main>
@if($action === 'correction' && ! $correctionExpired)
<script>
const quantity = document.getElementById('correction-packages');
const weights = document.getElementById('package-weights');
const total = document.getElementById('total-weight');
const totalSection = document.getElementById('total-weight-section');
const individualSection = document.getElementById('individual-weight-section');
const totalInput = document.getElementById('total-weight-input');
function updateMode() {
 const isTotal = document.querySelector('input[name="weight_mode"]:checked').value === 'total';
 totalSection.hidden = !isTotal; individualSection.hidden = isTotal;
 totalInput.disabled = !isTotal; totalInput.required = false;
 weights.querySelectorAll('input').forEach(input => { input.disabled = isTotal; input.required = false; });
}
document.querySelectorAll('input[name="weight_mode"]').forEach(input => input.addEventListener('change', updateMode));
function updateTotal() {
 const inputs = [...weights.querySelectorAll('input')];
 total.textContent = inputs.every(input => input.value && Number(input.value) > 0) ? inputs.reduce((sum, input) => sum + Number(input.value), 0).toLocaleString('es-CL', {maximumFractionDigits:3}) : '—';
}
quantity.addEventListener('input', () => {
 const count = Number(quantity.value);
 if (!Number.isInteger(count) || count < 1 || count > 1000) return;
 const previous = [...weights.querySelectorAll('input')].map(input => input.value);
 weights.replaceChildren();
 for (let i = 0; i < count; i++) {
  const label = document.createElement('label'); label.textContent = `Bulto ${i + 1}`;
  const input = document.createElement('input'); input.type = 'number'; input.name = 'package_weights[]'; input.min = '0.001'; input.max = '100000'; input.step = 'any'; input.inputMode = 'decimal'; input.required = false; input.value = previous[i] || '';
  label.append(input); weights.append(label);
 }
 updateMode(); updateTotal();
});
weights.addEventListener('input', updateTotal);
updateMode(); updateTotal();
</script>
@endif
</body></html>
