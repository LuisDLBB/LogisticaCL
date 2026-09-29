@extends('fleet::layout')
@section('title', 'Retiros fijos')
@section('content')
<h1>Retiros fijos</h1>
<p class="muted">Matriz completa. Solo los retiros habilitados para la fecha seleccionada se pueden pasar a Solicitudes.</p>
<div class="card">
    <form method="get" action="{{ route('fleet.page.coordination-fixed-pickups') }}" class="inline">
        <label for="fecha">Fecha</label><input id="fecha" type="date" name="fecha" value="{{ $selectedDate->toDateString() }}" required>
        <button type="submit">Ver fecha</button>
        <a class="button" href="{{ route('fleet.page.coordination-requests') }}">Ver solicitudes</a>
    </form>
</div>
<form method="post" action="{{ route('fleet.fixed.convert') }}" id="convert-form" class="card">
    @csrf
    <input type="hidden" name="service_date" value="{{ $selectedDate->toDateString() }}">
    <div class="table-wrap"><table>
        <thead><tr><th>Seleccionar</th><th>Cliente / punto</th><th>Días</th><th>Jornada / ventana</th><th>Material</th><th>Bultos para el RET</th><th>Estado</th><th>Correo</th><th>Acción</th></tr></thead>
        <tbody>
        @foreach($rows as $row)
            @php($state = $statuses[$row->id])
            <tr>
                <td><input type="checkbox" name="ids[]" value="{{ $row->id }}" @disabled($state !== 'Habilitado') aria-label="Seleccionar retiro {{ $row->id }}"></td>
                <td><strong>{{ $row->source_client_name }}</strong><br>{{ $row->source_point_name }}<br><span class="muted">{{ $row->branch?->address ?: 'Punto sin dirección' }}</span></td>
                <td>{{ collect($row->weekdays)->map(fn ($day) => [1=>'Lu',2=>'Ma',3=>'Mi',4=>'Ju',5=>'Vi',6=>'Sa',7=>'Do'][$day] ?? '?')->join(', ') }}</td>
                <td>{{ $row->shift }} · {{ substr($row->window_start,0,5) }}–{{ substr($row->window_end,0,5) }}</td>
                <td>{{ $row->material }}</td>
                <td><input type="number" min="1" name="packages[{{ $row->id }}]" value="{{ $row->usual_packages }}" style="width:85px" @disabled($state !== 'Habilitado') aria-label="Bultos para {{ $row->source_point_name }}"></td>
                <td><span class="tag @if($state !== 'Habilitado') off @endif">{{ $state }}</span></td>
                <td>{{ $row->operational_email_pending ? 'Correo operacional pendiente' : $row->operational_emails }}</td>
                <td><button type="submit" name="single_id" value="{{ $row->id }}" @disabled($state !== 'Habilitado')>Pasar</button></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <p><button type="submit" name="action" value="selected">Pasar seleccionados</button> <button type="submit" name="action" value="all">Pasar habilitados del día</button></p>
</form>
@if($rows->isEmpty())<p class="card">No hay retiros fijos para esta empresa.</p>@endif
<h2>Editar retiros y puntos</h2>
<p class="muted">Las modificaciones del maestro no cambian los RET creados anteriormente.</p>
@foreach($rows as $row)
<details class="card">
    <summary><strong>{{ $row->source_client_name }} · {{ $row->source_point_name }}</strong> — {{ $row->is_active ? 'Activo' : 'Inactivo' }}</summary>
    <form method="post" action="{{ route('fleet.fixed.update', $row) }}">
        @csrf @method('PUT')
        <label>Días</label>
        @foreach([1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'] as $number=>$label)
            <label style="display:inline-block;margin-right:12px"><input type="checkbox" name="weekdays[]" value="{{ $number }}" @checked(in_array($number,$row->weekdays ?? [],true))> {{ $label }}</label>
        @endforeach
        <div class="grid">
            <div><label>Jornada</label><select name="shift"><option @selected($row->shift==='AM')>AM</option><option @selected($row->shift==='PM')>PM</option></select></div>
            <div><label>Desde</label><input type="time" name="window_start" value="{{ substr($row->window_start,0,5) }}"></div>
            <div><label>Hasta</label><input type="time" name="window_end" value="{{ substr($row->window_end,0,5) }}"></div>
            <div><label>Material</label><input type="text" name="material" value="{{ $row->material }}"></div>
            <div><label>Bultos habituales (opcional)</label><input type="number" min="1" name="usual_packages" value="{{ $row->usual_packages }}"></div>
        </div>
        <p><button type="submit">Guardar retiro fijo</button></p>
    </form>
    <form method="post" action="{{ route('fleet.fixed.toggle', $row) }}">@csrf<button type="submit">{{ $row->is_active ? 'Desactivar' : 'Activar' }}</button></form>
    @if($row->branch)
        <h3>Punto {{ $row->branch->name }} @if($row->branch->address_status !== 'ready') · Pendiente completar @endif</h3>
        <form method="post" action="{{ route('fleet.points.update', $row->branch) }}">
            @csrf @method('PUT')
            <div class="grid">
                <div><label>Dirección real</label><input type="text" name="address" value="{{ $row->branch->address }}" required></div>
                <div><label>Comuna</label><input type="text" name="commune_name" value="{{ $row->branch->commune_name }}" required></div>
                <div><label>Correo operacional (separar varios con ;)</label><input type="text" name="operational_emails" value="{{ $row->branch->operational_emails }}"></div>
            </div>
            <p><button type="submit">Actualizar punto</button></p>
        </form>
    @else<p class="errors">Punto pendiente de asociación: requiere la dirección confirmada de Orquídea.</p>@endif
</details>
@endforeach
<script>
document.querySelectorAll('button[name="single_id"]').forEach(button => button.addEventListener('click', () => {
    document.querySelectorAll('#convert-form input[name="ids[]"]').forEach(box => box.checked = box.value === button.value);
}));
</script>
@endsection
