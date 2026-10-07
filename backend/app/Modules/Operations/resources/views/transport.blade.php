@extends('operations::layout')
@section('title','Transporte de troncales y postas')
@section('content')
<h1>Transporte de troncales y postas</h1>
<p class="intro">Selecciona un tramo para cambiar su patente, RUT y chofer. Las Posta 1 de las troncales Sur, V Región y Norte toman sus datos de la troncal correspondiente. Las Posta 2 son independientes y editables.</p>

<p class="note">Pulsa «Editar» para abrir la ventana de una troncal o posta. Las sugerencias incluyen la flota, los usuarios y los transportes externos ya registrados; también puedes escribir una patente o un chofer nuevo. Las flechas y cantidades abren las agencias y coberturas asignadas.</p>

@if($selected)
<dialog class="ope-transport-dialog" id="ope-transport-dialog" aria-labelledby="ope-transport-title">
    <div class="ope-transport-dialog-head">
        <h2 id="ope-transport-title">{{ $selectedType === 'troncal' ? 'Troncal' : 'Posta' }} {{ $selectedType === 'troncal' ? $selected->trunk_code : $selected->post_code }} · {{ $selected->name }}</h2>
        <button type="button" class="ope-dialog-close" id="ope-transport-close" aria-label="Cerrar">×</button>
    </div>
    @if($selectedType === 'troncal')
        <p class="note">Esta troncal figura en {{ number_format($trunkUsage->get($selected->id, 0), 0, ',', '.') }} agencias.</p>
    @else
        <p class="note">Esta posta figura como Posta 1 en {{ number_format($firstPostUsage->get($selected->id, 0), 0, ',', '.') }} agencias y como Posta 2 en {{ number_format($secondPostUsage->get($selected->id, 0), 0, ',', '.') }} agencias.</p>
    @endif
    @if($selectedType === 'posta' && $linkedFirstPosts->has($selected->id))
    <p class="note">Esta Posta 1 recibe su patente y chofer de {{ $linkedFirstPosts->get($selected->id)->trunk_name }}. <a href="{{ route('operations.transport', ['type' => 'troncal', 'record' => $linkedFirstPosts->get($selected->id)->trunk_id]) }}">Editar troncal</a>.</p>
    @elseif($canEdit)
    <form method="POST" action="{{ route('operations.transport.update', ['type' => $selectedType, 'record' => $selected->id]) }}" class="ope-form" id="ope-transport-form">
        @csrf @method('PUT')
        <label>Patente<input name="plate" list="ope-plate-options" value="{{ old('plate', $selected->plate) }}" maxlength="12" placeholder="Selecciona o escribe una patente" autocomplete="off"></label>
        <datalist id="ope-plate-options">@foreach($plates as $plate)<option value="{{ $plate }}"></option>@endforeach</datalist>
        <label>Chofer (nombre y RUT)<input name="driver_name" id="ope-driver-name" list="ope-driver-options" value="{{ old('driver_name', $selected->driver_name) }}" maxlength="160" placeholder="Selecciona o escribe el nombre" autocomplete="off"></label>
        <datalist id="ope-driver-options">@foreach($drivers as $driver)<option value="{{ $driver->name }} · {{ $driver->rut }}" data-name="{{ $driver->name }}" data-rut="{{ $driver->rut }}"></option>@endforeach</datalist>
        <label>RUT del chofer<input name="driver_rut" id="ope-driver-rut" list="ope-rut-options" value="{{ old('driver_rut', $selected->driver_rut) }}" maxlength="15" placeholder="12345678-5" autocomplete="off"></label>
        <datalist id="ope-rut-options">@foreach($drivers as $driver)<option value="{{ $driver->rut }}" label="{{ $driver->name }}"></option>@endforeach</datalist>
        @if($selectedType === 'troncal' && in_array((int) $selected->trunk_code, [1, 2, 3], true))
        <fieldset class="ope-air-route-scope ope-full">
            <legend>¿Extender la patente y el chofer a los otros dos aéreos?</legend>
            <label><input type="radio" name="air_route_scope" value="only" @checked(old('air_route_scope') === 'only') required> No, cambiar solo {{ $selected->name }}</label>
            <label><input type="radio" name="air_route_scope" value="all" @checked(old('air_route_scope') === 'all') required> Sí, actualizar también los otros dos aéreos</label>
        </fieldset>
        @endif
        <div class="ope-actions ope-full"><button type="submit">Guardar transporte</button><button type="button" class="ope-secondary-button" id="ope-transport-cancel">Cancelar</button></div>
    </form>
    <p class="note">Puedes escribir datos externos aunque no existan en Vehículos o Usuarios; al guardar, quedarán disponibles en estas sugerencias. Para un tramo sin vehículo o chofer, deja los campos correspondientes vacíos. Si corriges el nombre de un RUT ya registrado, se actualizará en todos los tramos que usan ese chofer. Las salidas y guías ya guardadas conservan sus datos.</p>
    @else
    <p class="note">Un supervisor o administrador puede editar estos datos.</p>
    @endif
</dialog>
@endif

<dialog class="ope-transport-dialog ope-coverages-dialog" id="ope-coverages-dialog" aria-labelledby="ope-coverages-title">
    <div class="ope-transport-dialog-head">
        <h2 id="ope-coverages-title">Coberturas asignadas</h2>
        <button type="button" class="ope-dialog-close" id="ope-coverages-close" aria-label="Cerrar coberturas">×</button>
    </div>
    <p class="note" id="ope-coverages-status" role="status">Cargando coberturas…</p>
    <div class="table-wrap" id="ope-coverages-list" hidden><table class="ope-table">
        <thead><tr><th>ID cobertura</th><th>Agencia</th><th>Uso</th><th>Comuna</th><th>Proveedor</th><th>Frecuencia</th><th>Estado</th></tr></thead>
        <tbody id="ope-coverages-body"></tbody>
    </table></div>
</dialog>

<dialog class="ope-transport-dialog ope-coverages-dialog" id="ope-agencies-dialog" aria-labelledby="ope-agencies-title">
    <div class="ope-transport-dialog-head">
        <h2 id="ope-agencies-title">Agencias asignadas</h2>
        <button type="button" class="ope-dialog-close" id="ope-agencies-close" aria-label="Cerrar agencias">×</button>
    </div>
    <p class="note" id="ope-agencies-status" role="status">Cargando agencias…</p>
    <div class="table-wrap" id="ope-agencies-list" hidden><table class="ope-table">
        <thead><tr><th>ID agencia</th><th>Agencia</th><th>Dirección matriz origen</th><th>Comuna matriz origen</th><th>Posta 1</th><th>Posta 2</th></tr></thead>
        <tbody id="ope-agencies-body"></tbody>
    </table></div>
</dialog>

<section class="card" id="troncales">
    <h2>Troncales · {{ $trunks->count() }}</h2>
    <div class="table-wrap"><table class="ope-table">
        <thead><tr><th>ID</th><th>Troncal</th><th>Patente</th><th>RUT chofer</th><th>Chofer</th><th>Agencias</th><th>Coberturas</th><th></th></tr></thead>
        <tbody>@forelse($trunks as $trunk)
            <tr>
                <td>{{ $trunk->trunk_code }}</td><td>{{ $trunk->name }}</td><td>{{ $trunk->plate ?: '—' }}</td>
                <td>{{ $trunk->driver_rut ?: '—' }}</td><td>{{ $trunk->driver_name ?: '—' }}</td>
                <td><button type="button" class="ope-list-trigger ope-agency-trigger" data-url="{{ route('operations.transport.agencies', ['type' => 'troncal', 'record' => $trunk->id]) }}" data-title="Troncal {{ $trunk->trunk_code }} · {{ $trunk->name }}" aria-label="Ver {{ $trunkUsage->get($trunk->id, 0) }} agencias de {{ $trunk->name }}"><span aria-hidden="true">▸</span> {{ $trunkUsage->get($trunk->id, 0) }}</button></td>
                <td><button type="button" class="ope-list-trigger ope-coverage-trigger" data-url="{{ route('operations.transport.coverages', ['type' => 'troncal', 'record' => $trunk->id]) }}" data-title="Troncal {{ $trunk->trunk_code }} · {{ $trunk->name }}" aria-label="Ver {{ $trunkCoverageCounts->get($trunk->id, 0) }} coberturas de {{ $trunk->name }}"><span aria-hidden="true">▸</span> {{ $trunkCoverageCounts->get($trunk->id, 0) }}</button></td>
                <td><a href="{{ route('operations.transport', ['type' => 'troncal', 'record' => $trunk->id]) }}">{{ $canEdit ? 'Editar' : 'Ver' }}</a></td>
            </tr>
        @empty<tr><td colspan="8" class="ope-empty">No hay troncales registradas.</td></tr>@endforelse</tbody>
    </table></div>
</section>

<section class="card" id="postas" style="margin-top:16px">
    <h2>Posta 1 y Posta 2 · {{ $posts->count() }} postas</h2>
    <p class="note">Cada posta tiene una sola ficha de transporte. Las que figuran como Posta 2 pueden modificarse sin cambiar la troncal ni las Posta 1.</p>
    <div class="table-wrap"><table class="ope-table">
        <thead><tr><th>ID</th><th>Posta</th><th>Estado</th><th>Patente</th><th>RUT chofer</th><th>Chofer</th><th>Posta 1</th><th>Posta 2</th><th>Coberturas</th><th></th></tr></thead>
        <tbody>@forelse($posts as $post)
            <tr>
                <td>{{ $post->post_code }}</td><td>{{ $post->name }}</td><td>{{ $post->is_active ? 'Activa' : 'Inactiva' }}</td>
                <td>{{ $post->plate ?: '—' }}</td><td>{{ $post->driver_rut ?: '—' }}</td><td>{{ $post->driver_name ?: '—' }}</td>
                <td><button type="button" class="ope-list-trigger ope-agency-trigger" data-url="{{ route('operations.transport.agencies', ['type' => 'posta', 'record' => $post->id, 'role' => 1]) }}" data-title="Posta {{ $post->post_code }} · {{ $post->name }} · Posta 1" aria-label="Ver {{ $firstPostUsage->get($post->id, 0) }} agencias como Posta 1 de {{ $post->name }}"><span aria-hidden="true">▸</span> {{ $firstPostUsage->get($post->id, 0) }}</button></td>
                <td><button type="button" class="ope-list-trigger ope-agency-trigger" data-url="{{ route('operations.transport.agencies', ['type' => 'posta', 'record' => $post->id, 'role' => 2]) }}" data-title="Posta {{ $post->post_code }} · {{ $post->name }} · Posta 2" aria-label="Ver {{ $secondPostUsage->get($post->id, 0) }} agencias como Posta 2 de {{ $post->name }}"><span aria-hidden="true">▸</span> {{ $secondPostUsage->get($post->id, 0) }}</button></td>
                <td><button type="button" class="ope-list-trigger ope-coverage-trigger" data-url="{{ route('operations.transport.coverages', ['type' => 'posta', 'record' => $post->id]) }}" data-title="Posta {{ $post->post_code }} · {{ $post->name }}" aria-label="Ver {{ $postCoverageCounts->get($post->id, 0) }} coberturas de {{ $post->name }}"><span aria-hidden="true">▸</span> {{ $postCoverageCounts->get($post->id, 0) }}</button></td>
                <td>
                    @if($linkedFirstPosts->has($post->id))
                        <a href="{{ route('operations.transport', ['type' => 'troncal', 'record' => $linkedFirstPosts->get($post->id)->trunk_id]) }}">Desde troncal</a>
                    @else
                        <a href="{{ route('operations.transport', ['type' => 'posta', 'record' => $post->id]) }}">{{ $canEdit ? 'Editar' : 'Ver' }}</a>
                    @endif
                </td>
            </tr>
        @empty<tr><td colspan="10" class="ope-empty">No hay postas registradas.</td></tr>@endforelse</tbody>
    </table></div>
</section>
@endsection
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.getElementById('ope-coverages-dialog');
    const title = document.getElementById('ope-coverages-title');
    const status = document.getElementById('ope-coverages-status');
    const list = document.getElementById('ope-coverages-list');
    const body = document.getElementById('ope-coverages-body');
    let requestId = 0;
    let opener = null;

    document.getElementById('ope-coverages-close').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { requestId++; opener?.focus(); });
    document.querySelectorAll('.ope-coverage-trigger').forEach(button => {
        button.addEventListener('click', async () => {
            opener = button;
            const currentRequest = ++requestId;
            title.textContent = `Coberturas · ${button.dataset.title}`;
            status.textContent = 'Cargando coberturas…';
            status.hidden = false;
            list.hidden = true;
            body.replaceChildren();
            dialog.showModal();

            try {
                const response = await fetch(button.dataset.url, { headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('No se pudieron cargar las coberturas.');
                const { coverages } = await response.json();
                if (currentRequest !== requestId || !dialog.open) return;
                if (coverages.length === 0) {
                    status.textContent = 'No hay coberturas asociadas.';
                    return;
                }
                const fragment = document.createDocumentFragment();
                coverages.forEach(coverage => {
                    const row = document.createElement('tr');
                    [coverage.id, coverage.agency, coverage.role, coverage.commune, coverage.provider || '—', coverage.frequency || '—', coverage.active ? 'Activa' : 'Inactiva'].forEach(value => {
                        const cell = document.createElement('td');
                        cell.textContent = value;
                        row.appendChild(cell);
                    });
                    fragment.appendChild(row);
                });
                body.appendChild(fragment);
                status.hidden = true;
                list.hidden = false;
            } catch (error) {
                if (currentRequest === requestId && dialog.open) status.textContent = error.message;
            }
        });
    });

    const agencyDialog = document.getElementById('ope-agencies-dialog');
    const agencyTitle = document.getElementById('ope-agencies-title');
    const agencyStatus = document.getElementById('ope-agencies-status');
    const agencyList = document.getElementById('ope-agencies-list');
    const agencyBody = document.getElementById('ope-agencies-body');
    let agencyRequestId = 0;
    let agencyOpener = null;

    document.getElementById('ope-agencies-close').addEventListener('click', () => agencyDialog.close());
    agencyDialog.addEventListener('close', () => { agencyRequestId++; agencyOpener?.focus(); });
    document.querySelectorAll('.ope-agency-trigger').forEach(button => {
        button.addEventListener('click', async () => {
            agencyOpener = button;
            const currentRequest = ++agencyRequestId;
            agencyTitle.textContent = `Agencias · ${button.dataset.title}`;
            agencyStatus.textContent = 'Cargando agencias…';
            agencyStatus.hidden = false;
            agencyList.hidden = true;
            agencyBody.replaceChildren();
            agencyDialog.showModal();

            try {
                const response = await fetch(button.dataset.url, { headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('No se pudieron cargar las agencias.');
                const { agencies } = await response.json();
                if (currentRequest !== agencyRequestId || !agencyDialog.open) return;
                if (agencies.length === 0) {
                    agencyStatus.textContent = 'No hay agencias asociadas.';
                    return;
                }
                const fragment = document.createDocumentFragment();
                agencies.forEach(agency => {
                    const row = document.createElement('tr');
                    [agency.code, agency.name, agency.address, agency.matrix_origin_commune, agency.first_post || '—', agency.second_post || '—'].forEach(value => {
                        const cell = document.createElement('td');
                        cell.textContent = value;
                        row.appendChild(cell);
                    });
                    fragment.appendChild(row);
                });
                agencyBody.appendChild(fragment);
                agencyStatus.hidden = true;
                agencyList.hidden = false;
            } catch (error) {
                if (currentRequest === agencyRequestId && agencyDialog.open) agencyStatus.textContent = error.message;
            }
        });
    });
});
</script>
@endpush
@if($selected)
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.getElementById('ope-transport-dialog');
    if (!dialog) return;
    dialog.showModal();

    const closeDialog = () => dialog.close();
    document.getElementById('ope-transport-close').addEventListener('click', closeDialog);
    document.getElementById('ope-transport-cancel')?.addEventListener('click', closeDialog);
    dialog.addEventListener('close', () => {
        window.history.replaceState(null, '', '{{ route('operations.transport') }}#{{ $selectedType === 'troncal' ? 'troncales' : 'postas' }}');
    });

    const form = document.getElementById('ope-transport-form');
    if (!form) return;
    const name = document.getElementById('ope-driver-name');
    const rut = document.getElementById('ope-driver-rut');
    const drivers = [...document.querySelectorAll('#ope-driver-options option')];
    const selectedByName = () => {
        const selected = drivers.find(option => option.value === name.value);
        if (selected) {
            name.value = selected.dataset.name;
            rut.value = selected.dataset.rut;
        }
    };
    const selectedByRut = () => {
        const typedRut = rut.value.toUpperCase().replace(/[.\s]/g, '');
        const selected = drivers.find(option => option.dataset.rut.toUpperCase().replace(/[.\s]/g, '') === typedRut);
        if (selected) {
            rut.value = selected.dataset.rut;
            name.value = selected.dataset.name;
        }
    };
    name.addEventListener('input', selectedByName);
    rut.addEventListener('change', selectedByRut);
    form.addEventListener('submit', selectedByName);
});
</script>
@endpush
@endif
