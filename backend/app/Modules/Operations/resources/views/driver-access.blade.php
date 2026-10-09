@extends('operations::layout')
@section('title', 'Accesos de choferes')
@section('content')
<h1>Accesos de choferes</h1>
<p class="intro">Habilita un usuario individual para cada chofer de 4 Nortes o de un proveedor que deba registrar su recorrido. Su acceso quedará limitado a «Mi Ruta».</p>
<div class="ope-grid">
@foreach($drivers as $driver)
<article class="card">
    <h2>{{ $driver->name }}</h2><p class="note">RUT {{ $driver->rut }}</p>
    @if($driver->user_id)
        <p class="ope-badge">Acceso vinculado: {{ $driver->username ?: 'usuario existente' }}</p>
    @else
        <form method="POST" action="{{ route('operations.drivers.access.store', $driver->id) }}" class="ope-form" autocomplete="off">
            @csrf
            <label>Usuario<input name="username" required minlength="4" maxlength="100" placeholder="chofer.nombre" pattern="[A-Za-z0-9._-]+"></label>
            <label>Correo (opcional)<input name="email" type="email" autocomplete="off"></label>
            <label>Contraseña inicial<input name="password" type="password" required minlength="12" autocomplete="new-password"></label>
            <label>Repetir contraseña<input name="password_confirmation" type="password" required minlength="12" autocomplete="new-password"></label>
            <button type="submit">Habilitar acceso</button>
        </form>
    @endif
</article>
@endforeach
</div>
@endsection
