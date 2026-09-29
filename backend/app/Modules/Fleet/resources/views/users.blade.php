@extends('fleet::layout')
@section('title', 'Usuarios')
@section('content')
    @php($canManage = app(\App\Fleet\FleetAccess::class)->allows(auth()->user(), $tenant, 'admin.users', 3))
    <h1>Usuarios y acceso</h1>
    <p class="muted">Empresa activa: {{ $tenant->name }}. Las cuentas pueden pertenecer a más de una empresa con perfiles distintos.</p>
    <div class="card table-wrap">
        <h2>Usuarios existentes de esta empresa y sin asociación</h2>
        <table><thead><tr><th>Usuario</th><th>Correo</th><th>Perfil</th><th>Acceso</th><th>Acción</th></tr></thead><tbody>
        @forelse($users as $user)
            @php($membership = $memberships->get($user->id))
            <tr><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ $membership ? ($profiles->firstWhere('code', $membership->role_code)?->name ?? $membership->role_code) : 'Sin asociación' }}</td><td><span class="tag {{ $membership && ! $membership->is_active ? 'off' : '' }}">{{ $membership ? ($membership->is_active ? 'Activo' : 'Inactivo') : 'Sin acceso' }}</span></td><td>
                @if($membership && $canManage)
                    <form class="inline" method="post" action="{{ route('fleet.users.update', $membership->id) }}">@csrf @method('PATCH')
                        <select name="role_code" aria-label="Perfil de {{ $user->name }}">@foreach($profiles as $profile)<option value="{{ $profile->code }}" @selected($membership->role_code === $profile->code)>{{ $profile->name }}</option>@endforeach</select>
                        <select name="is_active" aria-label="Acceso de {{ $user->name }}"><option value="1" @selected($membership->is_active)>Activo</option><option value="0" @selected(! $membership->is_active)>Inactivo</option></select>
                        <button type="submit">Guardar</button>
                    </form>
                @endif
            </td></tr>
        @empty
            <tr><td colspan="5">No hay usuarios visibles para esta empresa.</td></tr>
        @endforelse
        </tbody></table>
    </div>
    @if($canManage)
    <div class="grid">
        <section class="card"><h2>Crear usuario</h2><p class="muted">Usa datos reales verificados. Se asociará a {{ $tenant->name }}.</p>
            <form method="post" action="{{ route('fleet.users.create') }}">@csrf
                <label for="new_name">Nombre</label><input id="new_name" name="name" type="text" required value="{{ old('name') }}">
                <label for="new_email">Correo</label><input id="new_email" name="email" type="email" required value="{{ old('email') }}">
                <label for="new_password">Contraseña inicial</label><input id="new_password" name="password" type="password" minlength="12" required autocomplete="new-password">
                <label for="new_password_confirmation">Confirmar contraseña</label><input id="new_password_confirmation" name="password_confirmation" type="password" minlength="12" required autocomplete="new-password">
                <label for="new_role">Perfil</label><select id="new_role" name="role_code" required>@foreach($profiles as $profile)<option value="{{ $profile->code }}">{{ $profile->name }}</option>@endforeach</select>
                <p><button type="submit">Crear y asociar</button></p>
            </form>
        </section>
        <section class="card"><h2>Asociar cuenta existente</h2><p class="muted">Indica el correo exacto de una cuenta ya registrada; no se duplicará el usuario.</p>
            <form method="post" action="{{ route('fleet.users.associate') }}">@csrf
                <label for="existing_email">Correo existente</label><input id="existing_email" name="email" type="email" required>
                <label for="existing_role">Perfil en {{ $tenant->name }}</label><select id="existing_role" name="role_code" required>@foreach($profiles as $profile)<option value="{{ $profile->code }}">{{ $profile->name }}</option>@endforeach</select>
                <p><button type="submit">Asociar</button></p>
            </form>
        </section>
    </div>
    @endif
@endsection
