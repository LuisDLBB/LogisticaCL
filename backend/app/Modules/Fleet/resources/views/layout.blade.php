<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Control de Flota') · LogisticaCL</title>
    <style>
        :root{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#173333;background:#f3f7f7}*{box-sizing:border-box}body{margin:0}a{color:#006e73}button,.button{background:#007f82;color:#fff;border:0;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none}button:hover,.button:hover{background:#00696c}.top{background:#061f20;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 24px}.top a{color:#9ce6e4}.brand{font-weight:800;font-size:18px}.top-actions{display:flex;align-items:center;gap:18px;flex-wrap:wrap}.top-actions form{display:inline-flex;gap:8px;align-items:center}.top select{padding:7px;border-radius:6px}.top button{padding:8px 11px}.shell{display:grid;grid-template-columns:245px minmax(0,1fr);min-height:calc(100vh - 70px)}aside{background:white;border-right:1px solid #d7e1e2;padding:20px 12px}aside h2{font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#60757a;margin:22px 12px 7px}aside a{display:block;text-decoration:none;padding:9px 12px;border-radius:7px;color:#234a4d}aside a:hover,aside a.active{background:#d9f5f4;color:#006e73;font-weight:700}main{padding:32px clamp(18px,5vw,70px);max-width:1400px;width:100%}h1{font-size:30px;margin:0 0 8px}h2{font-size:20px}.muted{color:#60757a}.card{background:white;border:1px solid #d7e1e2;border-radius:12px;padding:22px;margin:18px 0}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px}.grid .card{margin:0}label{display:block;font-weight:700;margin:12px 0 5px}input,select{font:inherit;border:1px solid #b7c9ca;border-radius:7px;padding:9px 11px;max-width:100%}input[type=email],input[type=text],input[type=password]{width:100%}table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #e5eeee;padding:10px;text-align:left;vertical-align:top}th{background:#eef8f8;font-size:13px}form.inline{display:flex;gap:7px;align-items:center;flex-wrap:wrap}form.inline select{min-width:125px}.table-wrap{overflow:auto}.notice{padding:12px 15px;border-radius:8px;background:#e0f6ed;margin:18px 0}.errors{padding:12px 15px;border-radius:8px;background:#fff0e9;color:#973c23;margin:18px 0}.tag{font-size:12px;border-radius:99px;padding:4px 8px;background:#eef3f3}.tag.off{background:#fce9e4}.permission-form details{margin:10px 0;border:1px solid #d7e1e2;border-radius:8px;padding:10px}.permission-form summary{cursor:pointer;font-weight:700}.permission-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid #eef3f3}.permission-row:last-child{border:0}.permission-row select{min-width:145px}@media(max-width:850px){.shell{display:block}aside{border-right:0;border-bottom:1px solid #d7e1e2}.top{align-items:flex-start;flex-direction:column}main{padding:24px 16px}.permission-row{align-items:flex-start;flex-direction:column}}
        aside .menu-group{margin-top:12px}
        aside .menu-group summary{display:flex;align-items:center;justify-content:space-between;gap:10px;list-style:none;cursor:pointer;padding:12px;border-radius:7px;color:#60757a;font-size:12px;font-weight:800;letter-spacing:.1em;text-transform:uppercase}
        aside .menu-group summary::-webkit-details-marker{display:none}
        aside .menu-group summary::after{content:'';width:7px;height:7px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg);transition:transform .15s ease;flex:none}
        aside .menu-group[open] summary::after{transform:rotate(225deg)}
        aside .menu-group[open] summary,aside .menu-group summary:hover,aside .menu-group summary:focus-visible{background:rgba(0,174,178,.1);color:#006e73}
        aside .menu-group summary:focus-visible,aside a:focus-visible{outline:2px solid #008e91;outline-offset:2px}
        aside .menu-group .menu-items{padding:2px 0 6px 8px}
        aside a[aria-current="page"]{background:rgba(0,174,178,.18);color:#006e73;font-weight:700}
    </style>
    @stack('styles')
</head>
<body>
@php($fleetAccess = app(\App\Fleet\FleetAccess::class))
@php($fleetTenant = $fleetAccess->tenant(request()))
<header class="top">
    <div><a class="brand" href="{{ route('fleet.home') }}">Control de Flota 4N</a><div>{{ $fleetTenant?->name }} · LogisticaCL</div></div>
    <div class="top-actions">
        <span>{{ auth()->user()->name }}</span>
        <form method="post" action="{{ route('fleet.tenant.switch') }}">
            @csrf
            <label for="fleet_tenant" class="visually-hidden">Empresa</label>
            <select id="fleet_tenant" name="tenant_id" aria-label="Empresa activa" onchange="this.form.submit()">
                @foreach($fleetAccess->availableTenants(auth()->user()) as $option)
                    <option value="{{ $option->id }}" @selected($option->id === $fleetTenant?->id)>{{ $option->name }}</option>
                @endforeach
            </select>
            <button type="submit">Cambiar</button>
        </form>
        <a href="{{ route('provider-payments.dashboard') }}">Pago Proveedores</a>
        <form method="post" action="{{ route('fleet.logout') }}">@csrf<button type="submit">Salir</button></form>
    </div>
</header>
<div class="shell">
    <aside aria-label="Menú Control de Flota">
        <a href="{{ route('fleet.home') }}" @if(request()->routeIs('fleet.home')) aria-current="page" @endif>Inicio</a>
        @foreach(['Administrador','Gerencia','Coordinación','Operaciones','Comercial'] as $area)
            @php($pages = collect(\App\Fleet\FleetAccess::PAGES)->filter(fn ($page, $code) => $page['area'] === $area && $fleetAccess->allows(auth()->user(), $fleetTenant, $code)))
            @php($showAdmin = $area === 'Administrador' && ($fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.users') || $fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.permissions')))
            @if($pages->isNotEmpty() || $showAdmin)
                @php($areaHasActivePage = $pages->keys()->contains(fn ($code) => request()->routeIs('fleet.page.'.str_replace('.', '-', $code)) || ($code === 'operations.fleet' && request()->routeIs('fleet.vehicles.show')) || ($code === 'operations.maintenance' && request()->routeIs('fleet.maintenance.*') && ! request()->routeIs('fleet.maintenance.settings*')) || ($code === 'coordination.requests' && (request()->routeIs('fleet.requests.*') || request()->routeIs('fleet.reprogramming.*'))) || ($code === 'coordination.fixed-pickups' && request()->routeIs('fleet.fixed.*'))) || ($area === 'Administrador' && (request()->routeIs('fleet.users.*') || request()->routeIs('fleet.permissions.*') || request()->routeIs('fleet.maintenance.settings*'))))
                <details class="menu-group" @if($areaHasActivePage) open @endif>
                    <summary>{{ $area }}</summary>
                    <div class="menu-items">
                        @if($showAdmin)
                            @if($fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.users'))<a href="{{ route('fleet.users.index') }}" @if(request()->routeIs('fleet.users.*')) aria-current="page" @endif>Usuarios</a>@endif
                            @if($fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.permissions'))<a href="{{ route('fleet.permissions.index') }}" @if(request()->routeIs('fleet.permissions.*')) aria-current="page" @endif>Permisos</a>@endif
                            @if($fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.permissions', 3))<a href="{{ route('fleet.maintenance.settings') }}" @if(request()->routeIs('fleet.maintenance.settings*')) aria-current="page" @endif>Alertas de mantenciones</a>@endif
                        @endif
                        @foreach($pages as $code => $page)
                            @php($isCurrentPage = request()->routeIs('fleet.page.'.str_replace('.', '-', $code)) || ($code === 'operations.fleet' && request()->routeIs('fleet.vehicles.show')) || ($code === 'operations.maintenance' && request()->routeIs('fleet.maintenance.*') && ! request()->routeIs('fleet.maintenance.settings*')) || ($code === 'coordination.requests' && (request()->routeIs('fleet.requests.*') || request()->routeIs('fleet.reprogramming.*'))) || ($code === 'coordination.fixed-pickups' && request()->routeIs('fleet.fixed.*')))
                            <a href="{{ route('fleet.page.'.str_replace('.', '-', $code)) }}" @if($isCurrentPage) aria-current="page" @endif>{{ $page['title'] }}</a>
                        @endforeach
                    </div>
                </details>
            @endif
        @endforeach
    </aside>
    <main>
        @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="errors" role="alert"><strong>Revisa los datos:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </main>
</div>
</body>
</html>
