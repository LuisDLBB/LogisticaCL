@extends('fleet::layout')
@section('title', 'Nueva solicitud')
@section('content')
<h1>Nueva solicitud</h1>
<p>El punto se filtra por el cliente elegido. La fecha solicitada queda fija al crear el RET.</p>
<form method="post" action="{{ route('fleet.requests.store') }}" class="card">
    @csrf
    <div class="grid">
        <div><label for="client">Cliente</label><select id="client" name="client_id" required><option value="">Seleccionar</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(old('client_id')==$client->id)>{{ $client->commercial_name }}</option>@endforeach</select></div>
        <div><label for="point">Punto / dirección</label><select id="point" name="client_branch_id" required><option value="">Selecciona primero un cliente</option></select><p id="point-address" class="muted"></p><p id="point-contacts" class="muted"></p></div>
        <div><label>Tipo de servicio</label><select name="service_type_id" required>@foreach($serviceTypes as $type)<option value="{{ $type->id }}" @selected(old('service_type_id')==$type->id)>{{ $type->name }}</option>@endforeach</select></div>
        <div><label>Fecha solicitada</label><input type="date" name="service_date" value="{{ old('service_date') }}" required></div>
        <div><label>Jornada</label><select name="shift"><option @selected(old('shift')==='AM')>AM</option><option @selected(old('shift')==='PM')>PM</option></select></div>
        <div><label>Desde</label><input type="time" name="window_start" value="{{ old('window_start') }}" required></div>
        <div><label>Hasta</label><input type="time" name="window_end" value="{{ old('window_end') }}" required></div>
        <div><label>Bultos</label><input type="number" name="packages" min="1" value="{{ old('packages') }}" required></div>
        <div><label>Material</label><input type="text" name="material" value="{{ old('material') }}" required></div>
    </div>
    <h2>Dirección excepcional</h2>
    <p class="muted">Déjala vacía para usar la dirección del punto. Una dirección distinta exige motivo y no cambia el maestro.</p>
    <label>Dirección efectiva distinta</label><input type="text" name="effective_address" value="{{ old('effective_address') }}">
    <label>Motivo</label><input type="text" name="address_override_reason" value="{{ old('address_override_reason') }}">
    <label>Observaciones</label><textarea name="notes" rows="3">{{ old('notes') }}</textarea>
    <p><button type="submit">Crear RET</button></p>
</form>
<script>
const clientSelect = document.getElementById('client');
const pointSelect = document.getElementById('point');
const pointAddress = document.getElementById('point-address');
const pointContacts = document.getElementById('point-contacts');
async function loadPoints() {
  pointSelect.replaceChildren(new Option('Cargando puntos...', ''));
  pointAddress.textContent = '';
  pointContacts.textContent = '';
  if (!clientSelect.value) { pointSelect.replaceChildren(new Option('Selecciona primero un cliente', '')); return; }
  const url = @json(route('fleet.clients.points', ['client' => '__ID__'])).replace('__ID__', encodeURIComponent(clientSelect.value));
  const response = await fetch(url, {headers: {'Accept':'application/json'}});
  if (!response.ok) { pointSelect.replaceChildren(new Option('No se pudieron cargar los puntos', '')); return; }
  const points = await response.json();
  pointSelect.replaceChildren(new Option('Seleccionar punto', ''));
  for (const point of points) {
    const option = new Option(`${point.name} · ${point.address_status === 'ready' ? point.address : 'Dirección pendiente'}`, point.id);
    option.disabled = point.address_status !== 'ready';
    option.dataset.address = point.address;
    option.dataset.emails = point.operational_emails || 'Correo operacional pendiente';
    option.dataset.contacts = point.contacts.map(contact => `${contact.name}${contact.email ? ' · ' + contact.email : ''}`).join('; ');
    pointSelect.add(option);
  }
  const oldPoint = @json(old('client_branch_id'));
  if (oldPoint) pointSelect.value = String(oldPoint);
  updatePoint();
}
function updatePoint() {
  const option = pointSelect.selectedOptions[0];
  pointAddress.textContent = option?.value ? `${option.dataset.address} · ${option.dataset.emails}` : '';
  pointContacts.textContent = option?.value ? `Contactos del punto: ${option.dataset.contacts || 'Sin contactos registrados'}` : '';
}
clientSelect.addEventListener('change', loadPoints);
pointSelect.addEventListener('change', updatePoint);
if (clientSelect.value) loadPoints();
</script>
@endsection
