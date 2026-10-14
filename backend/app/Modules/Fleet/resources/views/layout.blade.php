<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <script>if(window.self!==window.top)document.documentElement.classList.add("ret-embedded");</script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Control de Flota') · LogisticaCL</title>
    <link rel="icon" href="{{ asset('images/logo4n.png') }}" type="image/png">
    <link rel="stylesheet" href="{{ asset('css/fleet.css') }}">
    @stack('styles')
    <link rel="stylesheet" href="{{ asset('css/fleet-theme.css') }}">
</head>
<body>
@php($fleetAccess = app(\App\Fleet\FleetAccess::class))
@php($fleetTenant = $fleetAccess->tenant(request()))
<div class="app-shell">
    <aside class="sidebar" aria-label="Menú Control de Flota">
        <div class="sidebar-brand">
            <a class="fleet-logo" href="{{ route('fleet.home') }}" aria-label="Control de Flota, inicio"><img src="{{ asset('images/logo4n.png') }}" alt="4N Logística" width="76" height="37"></a>
            <span class="sidebar-brand-caption">CONTROL DE FLOTA</span>
        </div>
        <details class="sidebar-navigation" open>
        <summary><span aria-hidden="true">☰</span> Menú de navegación</summary>
        <div class="sidebar-scroll">
            <span class="nav-caption">PLATAFORMA</span>
            @php($vehicleMenu = [
                'operations.fleet' => 'Vehículos y fichas por patente',
                'operations.fuel' => 'Combustible',
                'operations.tag' => 'TAG',
                'operations.maintenance' => 'Histórico de mantenciones y reparaciones',
                'operations.scheduling' => 'Programación de vehículos',
                'admin.procedures' => 'Procedimientos',
            ])
            @php($vehiclePages = collect($vehicleMenu)->filter(fn ($title, $code) => $fleetAccess->allows(auth()->user(), $fleetTenant, $code)))
            @php($vehicleSettings = $fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.permissions', 3))
            @php($fleetManagement = collect(\App\Fleet\FleetAccess::PAGES)->contains(fn ($page, $code) => $page['area'] === 'Gerencia' && $fleetAccess->allows(auth()->user(), $fleetTenant, $code)))
            @if($vehiclePages->isNotEmpty() || $vehicleSettings || $fleetManagement)
            <details class="menu-group fleet-process-menu" open>
                <summary><span class="nav-icon" aria-hidden="true">▥</span>Flota</summary>
                <div class="menu-items">
                    @php($vehicleSubmenuPages = collect(['operations.scheduling', 'operations.maintenance'])->filter(fn ($code) => $vehiclePages->has($code))->mapWithKeys(fn ($code) => [$code => $vehiclePages->get($code)]))
                    @if($vehicleSubmenuPages->isNotEmpty())
                    <details class="menu-group fleet-vehicle-submenu" open>
                        <summary>
                            @if($vehiclePages->has('operations.fleet'))<a href="{{ route('fleet.page.operations-fleet') }}">Vehículos y alertas KM</a>@else<span>Vehículos</span>@endif
                            <button type="button" data-toggle-vehicle-submenu aria-expanded="true" aria-controls="fleet-vehicle-submenu-links" aria-label="Mostrar u ocultar programación e histórico"><span aria-hidden="true">⌄</span></button>
                        </summary>
                        <div class="menu-items" id="fleet-vehicle-submenu-links">
                            @foreach($vehicleSubmenuPages as $code => $title)
                                @php($isVehiclePage = request()->routeIs('fleet.page.'.str_replace('.', '-', $code)) || ($code === 'operations.maintenance' && request()->routeIs('fleet.maintenance.*') && ! request()->routeIs('fleet.maintenance.settings*')) || ($code === 'operations.scheduling' && request()->routeIs('fleet.scheduling.*') && ! request()->routeIs('fleet.scheduling.settings*')))
                                <a href="{{ route('fleet.page.'.str_replace('.', '-', $code)) }}" @if($isVehiclePage) aria-current="page" @endif>{{ $title }}</a>
                            @endforeach
                        </div>
                    </details>
                    @elseif($vehiclePages->has('operations.fleet'))
                        <a href="{{ route('fleet.page.operations-fleet') }}">Vehículos y alertas KM</a>
                    @endif

                    @foreach($vehiclePages->only(['operations.fuel', 'operations.tag']) as $code => $title)
                        <a href="{{ route('fleet.page.'.str_replace('.', '-', $code)) }}" @if(request()->routeIs('fleet.page.'.str_replace('.', '-', $code))) aria-current="page" @endif>{{ $title }}</a>
                    @endforeach
                    @if($vehiclePages->has('admin.procedures'))
                    <details class="menu-group" open>
                        <summary>Procedimientos</summary>
                        <div class="menu-items">
                            @foreach(['venta' => 'Venta de vehículo', 'robo' => 'Robo de vehículo'] as $procedure => $title)
                                <a href="{{ route('fleet.page.admin-procedures', ['procedimiento' => $procedure]) }}" @if(request()->routeIs('fleet.page.admin-procedures') && request()->query('procedimiento') === $procedure) aria-current="page" @endif>{{ $title }}</a>
                            @endforeach
                        </div>
                    </details>
                    @endif
            @foreach(['Gerencia'] as $area)
                @php($pages = collect(\App\Fleet\FleetAccess::PAGES)->filter(fn ($page, $code) => $page['area'] === $area && $code !== 'operations.mail' && ! array_key_exists($code, $vehicleMenu) && $fleetAccess->allows(auth()->user(), $fleetTenant, $code)))
                @php($showAdmin = $area === 'Administrador' && ($fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.users') || $fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.permissions')))
                @if($pages->isNotEmpty() || $showAdmin)
                    @php($areaHasActivePage = $pages->keys()->contains(fn ($code) => request()->routeIs('fleet.page.'.str_replace('.', '-', $code)) || ($code === 'operations.fleet' && request()->routeIs('fleet.vehicles.show')) || ($code === 'operations.maintenance' && request()->routeIs('fleet.maintenance.*') && ! request()->routeIs('fleet.maintenance.settings*')) || ($code === 'coordination.requests' && (request()->routeIs('fleet.requests.*') || request()->routeIs('fleet.reprogramming.*'))) || ($code === 'coordination.fixed-pickups' && request()->routeIs('fleet.fixed.*'))) || ($area === 'Administrador' && (request()->routeIs('fleet.scheduling.settings*', 'fleet.microsoft-mail.*') || request()->routeIs('fleet.merchants.*') || request()->routeIs('fleet.ret.*') || request()->routeIs('fleet.users.*') || request()->routeIs('fleet.permissions.*') || request()->routeIs('fleet.maintenance.settings*'))))
                    <details class="menu-group" @if($areaHasActivePage) open @endif>
                        <summary><span class="nav-icon" aria-hidden="true">{{ ['Administrador' => '⚙', 'Gerencia' => '◫', 'Coordinación' => '▤', 'Operaciones' => '▥', 'Comercial' => '◇'][$area] }}</span>{{ $area }}</summary>
                        <div class="menu-items">
                            @if($showAdmin)
                                @if($fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.masters', 3))<a href="{{ route('fleet.ret.settings') }}" @if(request()->routeIs('fleet.ret.settings*')) aria-current="page" @endif>Contactos y correos</a>@endif
                                @if(app()->environment('local') && config('fleet.test_tools_enabled') && $fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.permissions', 3))<a href="{{ route('fleet.ret.cleanup') }}" @if(request()->routeIs('fleet.ret.cleanup*')) aria-current="page" @endif>Borrar pruebas</a>@endif
                                @if($fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.permissions'))<a href="{{ route('fleet.permissions.index') }}" @if(request()->routeIs('fleet.permissions.*')) aria-current="page" @endif>Permisos</a>@endif
                            @endif
                            @foreach($pages as $code => $page)
                                @php($isCurrentPage = ($code === 'admin.masters' && request()->routeIs('fleet.merchants.*')) || request()->routeIs('fleet.page.'.str_replace('.', '-', $code)) || ($code === 'operations.fleet' && request()->routeIs('fleet.vehicles.show')) || ($code === 'operations.maintenance' && request()->routeIs('fleet.maintenance.*') && ! request()->routeIs('fleet.maintenance.settings*')) || ($code === 'coordination.requests' && (request()->routeIs('fleet.requests.*') || request()->routeIs('fleet.reprogramming.*'))) || ($code === 'coordination.fixed-pickups' && request()->routeIs('fleet.fixed.*')))
                                <a href="{{ route('fleet.page.'.str_replace('.', '-', $code)) }}" @if($isCurrentPage) aria-current="page" @endif>{{ $page['title'] }}</a>
                            @endforeach
                        </div>
                    </details>
                @endif
            @endforeach
                </div>
            </details>
            @endif
            @php($pickupMonitor = collect(['coordination.requests', 'coordination.feasibility', 'commercial.feasibility', 'finance.feasibility', 'operations.feasibility', 'finance.feasibility.validate', 'commercial.feasibility.approve'])->contains(fn ($scope) => $fleetAccess->allows(auth()->user(), $fleetTenant, $scope)))
            @php($pickupPages = collect(\App\Fleet\FleetAccess::PAGES)->filter(fn ($page, $code) => $page['area'] === 'Coordinación' && $fleetAccess->allows(auth()->user(), $fleetTenant, $code)))
            @php($pickupAdmin = $fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.users') || $fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.permissions', 3))
            @php($pickupApprover = app(\App\Fleet\LatePickupAuthorization::class)->canDecide(auth()->user(), $fleetTenant, 'operations') || app(\App\Fleet\LatePickupAuthorization::class)->canDecide(auth()->user(), $fleetTenant, 'commercial'))
            @if($pickupPages->isNotEmpty() || $pickupApprover || $pickupMonitor)
            <details class="menu-group pickup-process-menu" open>
                <summary><span class="nav-icon" aria-hidden="true">▤</span>Procesos de retiros</summary>
                <div class="pickup-process-items">
                    @if($pickupMonitor)
                    <a class="nav-link" href="{{ route('fleet.monitor.index') }}"><span class="nav-icon" aria-hidden="true">▤</span>Pantalla de monitoreo</a>
                    @endif
                    @if($pickupPages->isNotEmpty() || $pickupApprover)
                    <details class="menu-group" @if(request()->routeIs('fleet.requests.*', 'fleet.reprogramming.*', 'fleet.fixed.*', 'fleet.page.coordination-*', 'fleet.page.finance-feasibility')) open @endif>
                        <summary><span class="nav-icon" aria-hidden="true">▤</span>Coordinación</summary>
                        <div class="menu-items">
                            @foreach($pickupPages as $code => $page)
                                @php($isPickupPage = request()->routeIs('fleet.page.'.str_replace('.', '-', $code)) || ($code === 'coordination.requests' && request()->routeIs('fleet.requests.*', 'fleet.reprogramming.*')) || ($code === 'coordination.fixed-pickups' && request()->routeIs('fleet.fixed.*')))
                                <a href="{{ route('fleet.page.'.str_replace('.', '-', $code)) }}" @if($isPickupPage) aria-current="page" @endif>{{ $page['title'] }}</a>
                            @endforeach
                            @if($fleetAccess->allows(auth()->user(),$fleetTenant,'coordination.requests') || app(\App\Fleet\LatePickupAuthorization::class)->canDecide(auth()->user(),$fleetTenant,'operations') || app(\App\Fleet\LatePickupAuthorization::class)->canDecide(auth()->user(),$fleetTenant,'commercial'))
            <a class="nav-link" href="{{ route('fleet.requests.late.index') }}">Excepciones de horario</a>
            @endif
                        </div>
                    </details>
                    @endif
            @foreach(['Operaciones','Comercial'] as $area)
                @php($pages = collect(\App\Fleet\FleetAccess::PAGES)->filter(fn ($page, $code) => $page['area'] === $area && $code !== 'operations.mail' && ! array_key_exists($code, $vehicleMenu) && $fleetAccess->allows(auth()->user(), $fleetTenant, $code)))
                @php($showAdmin = $area === 'Administrador' && ($fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.users') || $fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.permissions')))
                @if($pages->isNotEmpty() || $showAdmin)
                    @php($areaHasActivePage = $pages->keys()->contains(fn ($code) => request()->routeIs('fleet.page.'.str_replace('.', '-', $code)) || ($code === 'operations.fleet' && request()->routeIs('fleet.vehicles.show')) || ($code === 'operations.maintenance' && request()->routeIs('fleet.maintenance.*') && ! request()->routeIs('fleet.maintenance.settings*')) || ($code === 'coordination.requests' && (request()->routeIs('fleet.requests.*') || request()->routeIs('fleet.reprogramming.*'))) || ($code === 'coordination.fixed-pickups' && request()->routeIs('fleet.fixed.*'))) || ($area === 'Administrador' && (request()->routeIs('fleet.scheduling.settings*', 'fleet.microsoft-mail.*') || request()->routeIs('fleet.merchants.*') || request()->routeIs('fleet.ret.*') || request()->routeIs('fleet.users.*') || request()->routeIs('fleet.permissions.*') || request()->routeIs('fleet.maintenance.settings*'))))
                    <details class="menu-group" @if($areaHasActivePage) open @endif>
                        <summary><span class="nav-icon" aria-hidden="true">{{ ['Administrador' => '⚙', 'Gerencia' => '◫', 'Coordinación' => '▤', 'Operaciones' => '▥', 'Comercial' => '◇'][$area] }}</span>{{ $area }}</summary>
                        <div class="menu-items">
                            @if($showAdmin)
                                @if($fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.masters', 3))<a href="{{ route('fleet.ret.settings') }}" @if(request()->routeIs('fleet.ret.settings*')) aria-current="page" @endif>Contactos y correos</a>@endif
                                @if(app()->environment('local') && config('fleet.test_tools_enabled') && $fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.permissions', 3))<a href="{{ route('fleet.ret.cleanup') }}" @if(request()->routeIs('fleet.ret.cleanup*')) aria-current="page" @endif>Borrar pruebas</a>@endif
                                @if($fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.permissions'))<a href="{{ route('fleet.permissions.index') }}" @if(request()->routeIs('fleet.permissions.*')) aria-current="page" @endif>Permisos</a>@endif
                            @endif
                            @foreach($pages as $code => $page)
                                @php($isCurrentPage = ($code === 'admin.masters' && request()->routeIs('fleet.merchants.*')) || request()->routeIs('fleet.page.'.str_replace('.', '-', $code)) || ($code === 'operations.fleet' && request()->routeIs('fleet.vehicles.show')) || ($code === 'operations.maintenance' && request()->routeIs('fleet.maintenance.*') && ! request()->routeIs('fleet.maintenance.settings*')) || ($code === 'coordination.requests' && (request()->routeIs('fleet.requests.*') || request()->routeIs('fleet.reprogramming.*'))) || ($code === 'coordination.fixed-pickups' && request()->routeIs('fleet.fixed.*')))
                                <a href="{{ route('fleet.page.'.str_replace('.', '-', $code)) }}" @if($isCurrentPage) aria-current="page" @endif>{{ $page['title'] }}</a>
                            @endforeach
                        </div>
                    </details>
                @endif
            @endforeach
                </div>
            </details>
            @endif
            @if(\Illuminate\Support\Facades\Route::has('wms.index') && $fleetAccess->allows(auth()->user(), $fleetTenant, 'warehouse.inventory'))<a class="nav-link" href="{{ route('wms.index') }}" @if(request()->routeIs('wms.*')) aria-current="page" @endif><span class="nav-icon" aria-hidden="true">▦</span>WMS · Bodega</a>@endif
            <details class="menu-group home-process-menu" @if(request()->routeIs('fleet.home', 'fleet.users.*', 'fleet.permissions.*', 'fleet.merchants.*', 'fleet.ret.*', 'fleet.maintenance.settings*', 'fleet.scheduling.settings*', 'fleet.microsoft-mail.*', 'fleet.page.admin-masters')) open @endif>
                <summary><span class="nav-icon" aria-hidden="true">⌂</span>Inicio</summary>
                <div class="menu-items">
                    <a href="{{ route('fleet.home') }}" @if(request()->routeIs('fleet.home')) aria-current="page" @endif>Resumen de inicio</a>
            @foreach(['Administrador'] as $area)
                @php($pages = collect(\App\Fleet\FleetAccess::PAGES)->filter(fn ($page, $code) => $page['area'] === $area && $code !== 'operations.mail' && ! array_key_exists($code, $vehicleMenu) && $fleetAccess->allows(auth()->user(), $fleetTenant, $code)))
                @php($showAdmin = $area === 'Administrador' && ($fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.users') || $fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.permissions')))
                @if($pages->isNotEmpty() || $showAdmin)
                    @php($areaHasActivePage = $pages->keys()->contains(fn ($code) => request()->routeIs('fleet.page.'.str_replace('.', '-', $code)) || ($code === 'operations.fleet' && request()->routeIs('fleet.vehicles.show')) || ($code === 'operations.maintenance' && request()->routeIs('fleet.maintenance.*') && ! request()->routeIs('fleet.maintenance.settings*')) || ($code === 'coordination.requests' && (request()->routeIs('fleet.requests.*') || request()->routeIs('fleet.reprogramming.*'))) || ($code === 'coordination.fixed-pickups' && request()->routeIs('fleet.fixed.*'))) || ($area === 'Administrador' && (request()->routeIs('fleet.scheduling.settings*', 'fleet.microsoft-mail.*') || request()->routeIs('fleet.merchants.*') || request()->routeIs('fleet.ret.*') || request()->routeIs('fleet.users.*') || request()->routeIs('fleet.permissions.*') || request()->routeIs('fleet.maintenance.settings*'))))
                    <details class="menu-group" @if($areaHasActivePage) open @endif>
                        <summary><span class="nav-icon" aria-hidden="true">{{ ['Administrador' => '⚙', 'Gerencia' => '◫', 'Coordinación' => '▤', 'Operaciones' => '▥', 'Comercial' => '◇'][$area] }}</span>Mantenedores</summary>
                        <div class="menu-items">
                            @if($fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.users'))<a href="{{ route('fleet.users.index') }}" @if(request()->routeIs('fleet.users.*')) aria-current="page" @endif>Usuarios</a>@endif
                            @if($fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.permissions', 3))<a href="{{ route('fleet.scheduling.settings') }}" @if(request()->routeIs('fleet.scheduling.settings*')) aria-current="page" @endif>Parámetros de Programación</a>@endif
                            @if($fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.permissions', 3))<a href="{{ route('fleet.microsoft-mail.index') }}" @if(request()->routeIs('fleet.microsoft-mail.*')) aria-current="page" @endif>Integración Microsoft 365</a>@endif
                    @if($vehicleSettings)
                        <a href="{{ route('fleet.maintenance.settings') }}" @if(request()->routeIs('fleet.maintenance.settings*')) aria-current="page" @endif>Parámetros de Flota</a>
                    @endif

                            @if($showAdmin)
                                @if($fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.masters', 3))<a href="{{ route('fleet.ret.settings') }}" @if(request()->routeIs('fleet.ret.settings*')) aria-current="page" @endif>Contactos y correos</a>@endif
                                @if(app()->environment('local') && config('fleet.test_tools_enabled') && $fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.permissions', 3))<a href="{{ route('fleet.ret.cleanup') }}" @if(request()->routeIs('fleet.ret.cleanup*')) aria-current="page" @endif>Borrar pruebas</a>@endif
                                @if($fleetAccess->allows(auth()->user(), $fleetTenant, 'admin.permissions'))<a href="{{ route('fleet.permissions.index') }}" @if(request()->routeIs('fleet.permissions.*')) aria-current="page" @endif>Permisos</a>@endif
                            @endif
                            @foreach($pages as $code => $page)
                                @php($isCurrentPage = ($code === 'admin.masters' && request()->routeIs('fleet.merchants.*')) || request()->routeIs('fleet.page.'.str_replace('.', '-', $code)) || ($code === 'operations.fleet' && request()->routeIs('fleet.vehicles.show')) || ($code === 'operations.maintenance' && request()->routeIs('fleet.maintenance.*') && ! request()->routeIs('fleet.maintenance.settings*')) || ($code === 'coordination.requests' && (request()->routeIs('fleet.requests.*') || request()->routeIs('fleet.reprogramming.*'))) || ($code === 'coordination.fixed-pickups' && request()->routeIs('fleet.fixed.*')))
                                <a href="{{ route('fleet.page.'.str_replace('.', '-', $code)) }}" @if($isCurrentPage) aria-current="page" @endif>{{ $page['title'] }}</a>
                            @endforeach
                        </div>
                    </details>
                @endif
            @endforeach
                </div>
            </details>


        </div>
        </details>
        <div class="sidebar-footer">
            <a class="nav-link" href="{{ route('portal.home') }}"><span class="nav-icon" aria-hidden="true">⌂</span>Portal 4N</a>
            @if(app(\App\Fleet\FleetAccess::class)->canEnter(auth()->user()) && \Illuminate\Support\Facades\Route::has('provider-payments.dashboard'))<a class="nav-link" href="{{ route('provider-payments.dashboard') }}"><span class="nav-icon" aria-hidden="true">↗</span>Pago Proveedores</a>@endif
            <div class="sidebar-user"><span class="user-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span><span><strong>{{ auth()->user()->name }}</strong><small>{{ $fleetTenant?->name }}</small></span></div>
        </div>
    </aside>
    <div class="workspace">
        @php($compactFleetHeader = request()->routeIs('fleet.kilometers.index', 'fleet.page.operations-fleet', 'fleet.page.operations-fuel', 'fleet.page.operations-tag', 'fleet.page.operations-mail'))
        <header class="workspace-header {{ $compactFleetHeader ? 'fleet-page-header' : '' }}">
            <div class="fleet-page-intro"><span class="eyebrow">{{ request()->routeIs('wms.*') ? 'WMS · BODEGA' : 'CONTROL DE FLOTA · OPERACIONES' }}</span>@if($compactFleetHeader)<h1>@yield('title', 'Inicio')</h1>@else<strong>@yield('title', 'Inicio')</strong>@endif @hasSection('header-description')<p>@yield('header-description')</p>@endif</div>
            <div class="workspace-actions">
                <form method="post" action="{{ route('fleet.tenant.switch') }}" class="tenant-switcher">
                    @csrf
                    <label for="fleet_tenant">Empresa</label>
                    <select id="fleet_tenant" name="tenant_id" aria-label="Empresa activa" onchange="this.form.submit()">
                        @foreach($fleetAccess->availableTenants(auth()->user()) as $option)
                            <option value="{{ $option->id }}" @selected($option->id === $fleetTenant?->id)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                </form>
                <form method="post" action="{{ route('logout') }}">@csrf<button type="submit" class="button-quiet">Salir</button></form>
            </div>
            @yield('header-filters')
            @yield('header-navigation')
        </header>
        <main id="contenido">
            @if(! request()->routeIs('wms.*') && app()->environment(['local','testing']) && config('fleet.test_tools_enabled') && config('fleet.auto_test_mode'))<div class="notice test-mode-notice" role="status"><strong>Modo de pruebas activo.</strong> Los nuevos retiros, mantenciones, kilometrajes y consumos se guardan como pruebas. La base inicial y sus documentos se conservan.</div>@endif
            @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="errors" role="alert"><strong>Revisa los datos:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @yield('content')
        </main>
    </div>
</div>
<script>
const sidebarNavigation = document.querySelector('.sidebar-navigation');
if (window.matchMedia('(max-width: 820px)').matches) sidebarNavigation.open = false;
const vehicleSubmenu = document.querySelector('.fleet-vehicle-submenu');
if (vehicleSubmenu) {
    const vehicleSubmenuToggle = vehicleSubmenu.querySelector('[data-toggle-vehicle-submenu]');
    try {
        const savedState = localStorage.getItem('fleet-vehicle-submenu-open');
        if (savedState !== null) vehicleSubmenu.open = savedState === 'true';
    } catch (_) {}
    function syncVehicleSubmenu() {
        vehicleSubmenuToggle.setAttribute('aria-expanded', String(vehicleSubmenu.open));
    }
    syncVehicleSubmenu();
    vehicleSubmenuToggle.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        vehicleSubmenu.open = !vehicleSubmenu.open;
        syncVehicleSubmenu();
    });
    vehicleSubmenu.addEventListener('toggle', () => {
        syncVehicleSubmenu();
        try { localStorage.setItem('fleet-vehicle-submenu-open', String(vehicleSubmenu.open)); } catch (_) {}
    });
}
</script>
<dialog id="ret-dialog" class="ret-modal" aria-label="Ventana de retiro"><button type="button" id="ret-dialog-close" aria-label="Cerrar ventana">×</button><iframe title="Solicitud de retiro" id="ret-dialog-frame"></iframe></dialog>
<script>
const retDialog=document.getElementById('ret-dialog');
const retFrame=document.getElementById('ret-dialog-frame');
let maintenanceSizeObserver;
function setRetWindowSize(url){const isMaintenance=new URL(url,location.href).pathname.endsWith('/operaciones/mantenciones/nueva');retDialog.classList.toggle('ret-modal-maintenance',isMaintenance);if(!isMaintenance)retDialog.style.removeProperty('height');return isMaintenance;}
function openRet(url){const isVehicle=url.includes('/operaciones/flota/'),isMaintenance=setRetWindowSize(url);retDialog.setAttribute('aria-label',isMaintenance?'Nueva mantención':isVehicle?'Ficha del vehículo':'Ventana de retiro');retFrame.title=isMaintenance?'Nueva mantención':isVehicle?'Ficha del vehículo':'Solicitud de retiro';retFrame.src=url;retDialog.showModal();document.getElementById('ret-dialog-close').focus();}
retFrame.addEventListener('load',()=>{
    maintenanceSizeObserver?.disconnect();
    try{
        if(!setRetWindowSize(retFrame.contentWindow.location.href))return;
        retDialog.setAttribute('aria-label','Nueva mantención');retFrame.title='Nueva mantención';
        const main=retFrame.contentDocument.querySelector('main');if(!main)return;
        const fit=()=>{retDialog.style.height=Math.min(Math.ceil(main.getBoundingClientRect().height)+2,window.innerHeight-32)+'px';};
        maintenanceSizeObserver=new ResizeObserver(fit);maintenanceSizeObserver.observe(main);fit();
    }catch{retDialog.style.removeProperty('height');}
});
window.addEventListener('resize',()=>{if(retDialog.open&&retDialog.classList.contains('ret-modal-maintenance')){const main=retFrame.contentDocument?.querySelector('main');if(main)retDialog.style.height=Math.min(Math.ceil(main.getBoundingClientRect().height)+2,window.innerHeight-32)+'px';}});
document.getElementById('ret-dialog-close').addEventListener('click',()=>retDialog.close());
retDialog.addEventListener('close',()=>location.reload());
document.addEventListener('click',event=>{const link=event.target.closest('a[data-ret-modal]');if(link&&!event.ctrlKey&&!event.metaKey){event.preventDefault();openRet(link.href);return;}const row=event.target.closest('.clickable-row');if(row&&!event.target.closest('a,button,input,select')){if(row.hasAttribute('data-ret-modal'))openRet(row.dataset.href);else location.href=row.dataset.href;}});
document.addEventListener('keydown',event=>{const row=event.target.closest('.clickable-row');if(row&&event.target===row&&event.key==='Enter'){event.preventDefault();if(row.hasAttribute('data-ret-modal'))openRet(row.dataset.href);else location.href=row.dataset.href;}});
document.addEventListener('keydown',event=>{if(event.key!=='Escape')return;const current=document.querySelector('dialog[open]');if(current){event.preventDefault();current.close();return;}if(window.self!==window.top){const parentDialog=window.parent.document.querySelector('dialog[open]');if(parentDialog){event.preventDefault();parentDialog.close();}}});
const serverErrors=@json($errors->messages());
function markRequired(control){if(control.type==='radio')return;const label=control.closest('label') || (control.id?document.querySelector('label[for="'+control.id+'"]'):null);if(!label)return;let mark=label.querySelector('.ret-required');if(control.required&&!mark){mark=document.createElement('span');mark.className='ret-required';mark.textContent=' *';mark.setAttribute('aria-hidden','true');label.insertBefore(mark,control.parentElement===label?control:null);}if(mark&&!control.required)mark.remove();}
document.querySelectorAll('form[data-ret-validate], .ret-window form, .ret-form').forEach(form=>{
    form.noValidate=true;
    const controls=[...form.querySelectorAll('input,select,textarea')];
    function clear(control){if(control.validity.valid){control.classList.remove('ret-invalid');control.removeAttribute('aria-invalid');control.parentElement.querySelector('.ret-field-error')?.remove();}}
    function error(control,message){control.classList.add('ret-invalid');control.setAttribute('aria-invalid','true');let note=control.parentElement.querySelector('.ret-field-error');if(!note){note=document.createElement('small');note.className='ret-field-error';control.after(note);}note.textContent=message;}
    controls.forEach(control=>{markRequired(control);new MutationObserver(()=>markRequired(control)).observe(control,{attributes:true,attributeFilter:['required']});control.addEventListener('input',()=>clear(control));control.addEventListener('change',()=>clear(control));if(serverErrors[control.name])error(control,serverErrors[control.name][0]);});
    form.addEventListener('submit',event=>{let first=null;controls.forEach(control=>{if(control.willValidate&&!control.validity.valid){error(control,control.validity.valueMissing?'Completa este campo obligatorio.':'Revisa el valor de este campo.');first ||=control;}else clear(control);});if(first){event.preventDefault();first.scrollIntoView({block:'center',behavior:'smooth'});first.focus({preventScroll:true});}});
});
</script>
</body>
</html>
