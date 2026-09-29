@extends('fleet::layout')
@section('title', 'Permisos')
@section('content')
    @php($canManage = app(\App\Fleet\FleetAccess::class)->allows(auth()->user(), $tenant, 'admin.permissions', 3))
    <h1>Perfiles y permisos</h1>
    <p class="muted">Empresa activa: {{ $tenant->name }}. Nivel 0: sin acceso; 1: lectura; 2: edición; 3: administración. Las excepciones de usuario prevalecen sobre el perfil.</p>
    <div class="grid">
    @foreach($profiles as $profile)
        @php($levels = $profileLevels->get($profile->id, collect())->pluck('access_level', 'permission_code'))
        <section class="card permission-form"><h2>{{ $profile->name }}</h2><p class="muted">Código: {{ $profile->code }}</p>
            <form method="post" action="{{ route('fleet.permissions.profile.update', $profile->id) }}">@csrf @method('PUT')
                <details><summary>Ver permisos del perfil</summary>
                @foreach(\App\Fleet\FleetAccess::SCOPES as $scope => $label)
                    <div class="permission-row"><label for="profile_{{ $profile->id }}_{{ str_replace('.', '_', $scope) }}">{{ $label }}</label>
                    <select id="profile_{{ $profile->id }}_{{ str_replace('.', '_', $scope) }}" name="levels[{{ $scope }}]" @disabled(! $canManage)>
                        @foreach([0 => 'Sin acceso', 1 => 'Solo lectura', 2 => 'Edición', 3 => 'Administración'] as $value => $text)
                            <option value="{{ $value }}" @selected((int) $levels->get($scope, 0) === $value)>{{ $text }}</option>
                        @endforeach
                    </select></div>
                @endforeach
                </details>
                @if($canManage)<button type="submit">Guardar perfil</button>@endif
            </form>
        </section>
    @endforeach
    </div>
    <section class="card permission-form"><h2>Excepciones por usuario</h2><p class="muted">“Heredar” usa el nivel del perfil. Cada excepción corresponde solo a esta empresa.</p>
        @forelse($memberships as $membership)
            @php($userLevels = $overrides->get($membership->id, collect())->pluck('access_level', 'permission_code'))
            <form method="post" action="{{ route('fleet.permissions.user.update', $membership->id) }}">@csrf @method('PUT')
                <details><summary>{{ $membership->name }} · {{ $membership->email }} · {{ $profiles->firstWhere('code', $membership->role_code)?->name ?? $membership->role_code }}</summary>
                @foreach(\App\Fleet\FleetAccess::SCOPES as $scope => $label)
                    <div class="permission-row"><label for="user_{{ $membership->id }}_{{ str_replace('.', '_', $scope) }}">{{ $label }}</label>
                    <select id="user_{{ $membership->id }}_{{ str_replace('.', '_', $scope) }}" name="levels[{{ $scope }}]" @disabled(! $canManage)>
                        @foreach([-1 => 'Heredar perfil', 0 => 'Sin acceso', 1 => 'Solo lectura', 2 => 'Edición', 3 => 'Administración'] as $value => $text)
                            <option value="{{ $value }}" @selected((int) $userLevels->get($scope, -1) === $value)>{{ $text }}</option>
                        @endforeach
                    </select></div>
                @endforeach
                @if($canManage)<p><button type="submit">Guardar excepciones</button></p>@endif
                </details>
            </form>
        @empty<p>No hay usuarios asociados a esta empresa.</p>@endforelse
    </section>
    <section class="card table-wrap"><h2>Auditoría reciente</h2><table><thead><tr><th>Fecha</th><th>Acción</th><th>Actor</th><th>Usuario afectado</th><th>Detalle</th></tr></thead><tbody>
        @forelse($audits as $audit)
            <tr><td>{{ $audit->created_at }}</td><td>{{ $audit->action }}</td><td>{{ $userNames->get($audit->actor_user_id, 'Sistema local') }}</td><td>{{ $userNames->get($audit->target_user_id, 'Perfil') }}</td><td><details><summary>Ver cambios</summary><pre>{{ $audit->previous_values }}</pre><pre>{{ $audit->new_values }}</pre></details></td></tr>
        @empty<tr><td colspan="5">Sin cambios registrados.</td></tr>@endforelse
    </tbody></table></section>
@endsection
