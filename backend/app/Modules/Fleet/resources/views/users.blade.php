@extends('fleet::layout')
@section('title', 'Usuarios')
@section('content')
    @php($canManage = app(\App\Fleet\FleetAccess::class)->allows(auth()->user(), $tenant, 'admin.users', 3))
    <header class="users-page-header">
        <h1>Usuarios y acceso</h1>
        @if($canManage)
            <div class="users-page-actions">
                <button type="button" data-edit-user="user-create" aria-controls="user-create" aria-haspopup="dialog">+ Crear usuario</button>
            </div>
        @endif
    </header>
    <p class="muted">Administra los usuarios y sus perfiles de acceso a la plataforma.</p>
    <p class="muted">Para conductores externos puedes registrar su vehículo de trabajo. Patente, tipo y capacidades son opcionales por ahora.</p>
    <div class="card table-wrap">
        <div class="users-list-toolbar">
            <h2>Usuarios registrados</h2>
            <div class="users-search">
                <label for="users-search-input">Buscar usuarios</label>
                <div class="users-search-controls"><input id="users-search-input" type="search" autocomplete="off" placeholder="Nombre, correo, perfil o patente…" aria-controls="users-list"><button type="button" id="users-search-clear" class="user-editor-cancel" hidden>Limpiar</button></div>
            </div>
        </div>
        <p id="users-search-count" class="muted" role="status" aria-live="polite">{{ $users->count() }} usuarios</p>
        <table id="users-list"><thead><tr><th>Usuario</th><th>Correo</th><th>Perfil</th><th>Acceso</th><th>Acción</th></tr></thead><tbody>
        @forelse($users as $user)
            @php($membership = $memberships->get($user->id))
            @php($profileLabel = $membership ? ($profiles->firstWhere('code', $membership->profile_code)?->name ?? $membership->role_code) : 'Sin asociación')
            @php($accessLabel = $membership ? ($membership->is_active ? 'Activo' : 'Inactivo') : 'Sin acceso')
            <tr data-user-search="{{ implode(' ', [$user->name, $user->email, $user->username, $user->tax_id, $user->phone, $profileLabel, $accessLabel, $membership?->role_code, $membership?->driver_type === 'external' ? 'Externo' : ($membership?->driver_type === 'own' ? 'Interno' : ''), $membership?->external_vehicle_plate, $membership?->external_vehicle_type]) }}"><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ $membership ? ($profiles->firstWhere('code', $membership->profile_code)?->name ?? $membership->role_code) : 'Sin asociación' }}</td><td><span class="tag {{ $membership && ! $membership->is_active ? 'off' : '' }}">{{ $membership ? ($membership->is_active ? 'Activo' : 'Inactivo') : 'Sin acceso' }}</span></td><td>
                @if($membership && $canManage)
                    <button type="button" data-edit-user="user-editor-{{ $membership->id }}" aria-controls="user-editor-{{ $membership->id }}" aria-haspopup="dialog">Editar</button>
                @endif
            </td></tr>
        @empty
            <tr><td colspan="5">No hay usuarios visibles para esta empresa.</td></tr>
        @endforelse
        <tr id="users-search-empty" hidden><td colspan="5">No hay usuarios que coincidan con tu búsqueda.</td></tr>
        </tbody></table>
    </div>
    @if($canManage)
        @foreach($users as $user)
            @php($membership = $memberships->get($user->id))
            @if($membership)
                <dialog id="user-editor-{{ $membership->id }}" class="user-editor" aria-labelledby="user-editor-title-{{ $membership->id }}" data-user-editor @if(old('driver_form_key') === 'membership-'.$membership->id) data-reopen @endif>
                    <header class="user-editor-header">
                        <div><h2 id="user-editor-title-{{ $membership->id }}">Editar usuario</h2><strong>{{ $user->name }}</strong><p class="muted">{{ $user->email }}</p></div>
                        <button type="button" class="user-editor-close" data-close-user aria-label="Cerrar edición">×</button>
                    </header>
                    <form class="ret-form" method="post" action="{{ route('fleet.users.update', $membership->id) }}">@csrf @method('PATCH')
                        <label>Perfil<select name="role_code" aria-label="Perfil de {{ $user->name }}">@foreach($profiles as $profile)<option value="{{ $profile->code }}" @selected((old('driver_form_key') === 'membership-'.$membership->id ? old('role_code') : $membership->profile_code) === $profile->code)>{{ $profile->name }}</option>@endforeach</select></label>
                        <label>Acceso<select name="is_active" aria-label="Acceso de {{ $user->name }}"><option value="1" @selected($membership->is_active)>Activo</option><option value="0" @selected(! $membership->is_active)>Inactivo</option></select></label>
                        @include('fleet::user-driver-fields', ['driverDetails' => $membership, 'driverFormKey' => 'membership-'.$membership->id])
                        <div class="user-editor-actions"><button type="button" class="user-editor-cancel" data-close-user>Cancelar</button><button type="submit">Guardar cambios</button></div>
                    </form>
                </dialog>
            @endif
        @endforeach
    @endif
    @if($canManage)
        <dialog id="user-create" class="user-editor" aria-labelledby="user-create-title" data-user-editor @if(old('driver_form_key') === 'create') data-reopen @endif>
            <header class="user-editor-header">
                <div><h2 id="user-create-title">Crear usuario</h2><p class="muted">La nueva cuenta quedará asociada a {{ $tenant->name }}.</p></div>
                <button type="button" class="user-editor-close" data-close-user aria-label="Cerrar creación">×</button>
            </header>
            @if(old('driver_form_key') === 'create' && $errors->any())<ul class="user-form-errors" role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
            <form class="ret-form" method="post" action="{{ route('fleet.users.create') }}">@csrf
                <label for="new_name">Nombre</label><input id="new_name" name="name" type="text" required value="{{ old('name') }}">
                <label for="new_email">Correo</label><input id="new_email" name="email" type="email" required value="{{ old('email') }}">
                <label for="new_password">Contraseña inicial</label><input id="new_password" name="password" type="password" minlength="12" required autocomplete="new-password">
                <label for="new_password_confirmation">Confirmar contraseña</label><input id="new_password_confirmation" name="password_confirmation" type="password" minlength="12" required autocomplete="new-password">
                <label for="new_role">Perfil</label><select id="new_role" name="role_code" required>@foreach($profiles as $profile)<option value="{{ $profile->code }}" @selected(old('driver_form_key') === 'create' && old('role_code') === $profile->code)>{{ $profile->name }}</option>@endforeach</select>
                @include('fleet::user-driver-fields', ['driverDetails' => null, 'driverFormKey' => 'create'])
                <div class="user-editor-actions"><button type="button" class="user-editor-cancel" data-close-user>Cancelar</button><button type="submit">Crear usuario</button></div>
            </form>
        </dialog>
    @endif
    <style>
        .users-list-toolbar {display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;}
        .users-list-toolbar h2 {margin:0;}
        .users-search {width:min(440px,100%);}
        .users-search label {font-size:13px;margin-bottom:6px;}
        .users-search-controls {display:flex;align-items:center;gap:8px;}
        .users-search-controls input {width:100%;min-width:0;}
        #users-search-count {font-size:13px;margin:12px 0;}
        #users-list tr[hidden], #users-search-clear[hidden] {display:none;}

        .users-page-header {display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:16px;}
        .users-page-header h1 {margin:0;}
        .users-page-actions {display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;}
        .user-form-errors {color:#a02a2a;background:#fff1f1;border-radius:10px;padding:16px 28px;}
        @media(max-width:700px) {.users-page-header {align-items:flex-start;flex-direction:column;}.users-page-actions {width:100%;}}
        .user-editor {position:fixed;inset:0;margin:auto;width:min(580px,calc(100vw - 32px));height:fit-content;max-height:calc(100dvh - 48px);max-width:calc(100vw - 32px);box-sizing:border-box;border:1px solid #d9e5e5;border-radius:18px;padding:28px;overflow-y:auto;color:#20383e;box-shadow:0 20px 80px #071d1f44;}
        .user-editor::backdrop {background:#071d1f66;}
        .user-editor-header {display:flex;justify-content:space-between;gap:16px;border-bottom:1px solid #e1ebeb;padding-bottom:18px;margin-bottom:22px;}
        .user-editor-header h2 {margin:0 0 12px;}
        .user-editor-header p {margin:6px 0 0;overflow-wrap:anywhere;}
        .user-editor-close {align-self:flex-start;background:transparent;color:#526a6e;font-size:28px;padding:0 10px;min-height:36px;}
        .user-editor-actions {display:flex;justify-content:flex-end;gap:12px;border-top:1px solid #e1ebeb;padding-top:20px;margin-top:24px;}
        .user-editor-cancel {background:#eef4f4;color:#20383e;}
    </style>
    <script>
        const userSearch = document.getElementById('users-search-input');
        const userSearchClear = document.getElementById('users-search-clear');
        const normalizeUserSearch = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es').trim();
        const searchableUsers = [...document.querySelectorAll('[data-user-search]')].map(row => ({row, text: normalizeUserSearch(row.dataset.userSearch)}));
        const filterUsers = () => {
            const terms = normalizeUserSearch(userSearch.value).split(/\s+/).filter(Boolean);
            let matches = 0;
            for (const {row, text} of searchableUsers) {
                row.hidden = !terms.every(term => text.includes(term));
                if (!row.hidden) matches++;
            }
            document.getElementById('users-search-count').textContent = terms.length ? `${matches} de ${searchableUsers.length} usuarios` : `${searchableUsers.length} usuarios`;
            document.getElementById('users-search-empty').hidden = matches > 0 || !terms.length;
            userSearchClear.hidden = !userSearch.value;
        };
        userSearch.addEventListener('input', filterUsers);
        userSearchClear.addEventListener('click', () => {userSearch.value = ''; filterUsers(); userSearch.focus();});
        filterUsers();

        for (const button of document.querySelectorAll('[data-edit-user]')) {
            button.addEventListener('click', () => document.getElementById(button.dataset.editUser).showModal());
        }
        for (const editor of document.querySelectorAll('[data-user-editor]')) {
            editor.querySelectorAll('[data-close-user]').forEach(button => button.addEventListener('click', () => editor.close()));
            editor.addEventListener('click', event => {
                const bounds = editor.getBoundingClientRect();
                if (event.target === editor && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) editor.close();
            });
            if (editor.hasAttribute('data-reopen')) editor.showModal();
        }

        for (const section of document.querySelectorAll('[data-driver-details]')) {
            const type = section.querySelector('[name="driver_type"]');
            const profile = section.closest('form').querySelector('[name="role_code"]');
            const internal = section.querySelector('[data-internal-vehicle]');
            const fields = section.querySelector('[data-external-vehicle]');
            const update = () => {
                const role = profile.value.trim().toLowerCase().replaceAll('_', ' ');
                const driver = ['app conductor', 'conductor', 'conductor a5'].includes(role);
                section.hidden = !driver;
                type.disabled = !driver;
                type.required = driver;
                internal.hidden = !driver || type.value !== 'own';
                fields.hidden = !driver || type.value !== 'external';
                fields.querySelectorAll('input').forEach(input => input.disabled = fields.hidden);
            };
            profile.addEventListener('change', update);
            type.addEventListener('change', update);
            update();
        }
    </script>
@endsection
