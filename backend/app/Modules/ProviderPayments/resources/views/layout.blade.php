<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <title>@yield('title', 'Pago a Proveedores') · 4N Logística</title>
    <style>
        :root { --navy:#061f20; --ink:#102a2d; --muted:#60757a; --turquoise:#28c9c8; --turquoise-dark:#007f82; --turquoise-soft:#d9f5f4; --paper:#f4f7f7; --line:#d7e1e2; --white:#fff; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; background:var(--paper); color:var(--ink); font-family:system-ui,-apple-system,"Segoe UI",sans-serif; }
        .topbar { position:sticky; top:0; z-index:20; height:76px; display:flex; align-items:center; justify-content:space-between; padding:0 22px; background:var(--navy); color:#fff; }
        .brand { display:flex; align-items:center; gap:12px; color:#fff; text-decoration:none; }
        .brand-mark { width:44px; height:44px; display:grid; place-items:center; border-radius:11px; background:var(--turquoise); color:var(--navy); font-size:20px; font-weight:900; }
        .brand strong,.brand small { display:block; }.brand strong{font-size:16px}.brand small{margin-top:2px;color:#c9eeee;font-size:12px}.topbar-right{display:flex;align-items:center;gap:12px;margin-left:auto;min-width:0}.user-area{font-size:13px;white-space:nowrap}.user-area a{margin-left:18px;color:var(--turquoise);text-decoration:none}
        .shell { display:grid; grid-template-columns:222px minmax(0,1fr); min-height:calc(100vh - 76px); }
        .sidebar { padding:23px 9px; border-right:1px solid var(--line); background:#fff; }
        .nav-link,.nav-summary { display:block; width:100%; padding:12px 15px; border-radius:9px; color:#26474c; cursor:pointer; font-size:14px; font-weight:700; text-decoration:none; }
        .nav-link:hover,.nav-summary:hover,.nav-link.active { background:var(--turquoise-soft); color:#006d70; }
        .nav-summary { list-style:none; }.nav-summary::-webkit-details-marker{display:none}.nav-summary::after{float:right;content:'⌄';color:var(--turquoise-dark)}details[open]>.nav-summary::after{content:'⌃'}
        .subnav a { display:block; padding:9px 15px 9px 30px; color:#53696d; font-size:13px; text-decoration:none; }.subnav a:hover,.subnav a.active{color:var(--turquoise-dark);font-weight:800}
        .content { min-width:0; padding:28px clamp(20px,4vw,64px) 48px; }
        .eyebrow { margin:0 0 8px; color:var(--turquoise-dark); font-size:11px; font-weight:900; letter-spacing:1.6px; text-transform:uppercase; }
        .page-heading{display:flex;align-items:center;gap:10px;max-width:100%;margin:0 0 10px;overflow-x:auto;white-space:nowrap;scrollbar-width:thin}
        .page-heading .back,.page-heading .eyebrow{flex:none;margin:0;font-size:12px;line-height:1.4;letter-spacing:0;text-transform:none}
        .page-heading .eyebrow{font-weight:750}
        .page-heading h1{flex:none;margin:0;font-size:18px;line-height:1.4;font-weight:800}
        .page-heading .eyebrow::before,.page-heading h1::before{content:'–';padding-right:10px;color:var(--muted);font-weight:400}
        .page-heading .compile-period{margin-left:auto}
        @media(max-width:760px){.page-heading h1{font-size:16px}.page-heading{gap:7px}.page-heading .eyebrow::before,.page-heading h1::before{padding-right:7px}}
        h1 { margin:0; color:#071b1d; font-size:26px; line-height:1.2; } h2{color:#071b1d;font-size:20px}h3{font-size:16px}.intro{margin:7px 0 20px;color:var(--muted)}
        a{color:var(--turquoise-dark)}.back{display:inline-block;margin-bottom:18px;font-size:14px}
        .card,.box,details.review-group { background:#fff; border:1px solid var(--line); border-radius:11px; box-shadow:0 1px 2px rgb(6 31 32 / 3%); }
        .card{padding:18px}.button,button{display:inline-flex;align-items:center;justify-content:center;gap:5px;min-height:36px;padding:8px 13px;border:0;border-radius:7px;background:var(--turquoise-dark);color:#fff;cursor:pointer;font-size:13px;line-height:1.3;font-weight:750;text-decoration:none}.button:hover,button:hover{background:#00696c}button:disabled{opacity:.6}
        input:not([type=checkbox]):not([type=radio]):not([type=hidden]),select,textarea{max-width:100%;font-size:13px}select,input:not([type=checkbox]):not([type=radio]):not([type=hidden]){min-height:36px}
        table{width:100%;border-collapse:collapse}th,td{padding:9px 12px;border-bottom:1px solid #e7eeee;text-align:left}th{background:#effafa;color:#087477;font-size:11px;text-transform:uppercase}td:last-child,th:last-child{text-align:right}tr:last-child td{border-bottom:0}.table-wrap{overflow:auto}
        .note{color:var(--muted);font-size:14px}.warning{padding:15px;border-left:4px solid #db9f34;background:#fff6e4}.progress-track{height:9px;overflow:hidden;border-radius:99px;background:#dcecec}.progress-bar{width:35%;height:100%;background:var(--turquoise);animation:loading 1.2s ease-in-out infinite}@keyframes loading{from{transform:translateX(-110%)}to{transform:translateX(310%)}}progress{width:100%;accent-color:var(--turquoise-dark)}
        .pp-pager{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:16px 0;font-size:14px}.pp-pager-current,.pp-pager-button{display:inline-flex;align-items:center;min-height:38px;padding:8px 12px;border:1px solid var(--line);border-radius:7px;background:#fff;white-space:nowrap}.pp-pager-actions{display:flex;gap:8px;margin-left:auto}.pp-pager-button{color:var(--turquoise-dark);text-decoration:none}.pp-pager-button:hover{border-color:var(--turquoise-dark);background:var(--turquoise-soft)}.pp-pager-button.is-disabled{color:var(--muted);opacity:.7;cursor:default}.pp-pager-button.is-disabled:hover{border-color:var(--line);background:#fff}@media(max-width:480px){.pp-pager{flex-wrap:wrap}.pp-pager-actions{margin-left:0}}
        .master-tools{display:grid;grid-template-columns:minmax(240px,1fr) 220px auto;gap:10px;align-items:end;margin:0 0 22px;padding:16px;border:1px solid var(--line);border-radius:12px;background:#fff}.master-tools label{display:grid;gap:6px;color:var(--muted);font-size:12px;font-weight:800;text-transform:uppercase}.master-tools input,.master-tools select{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--ink)}.master-results{padding:10px 0;color:var(--muted);font-size:13px;white-space:nowrap}.new-record-toggle{margin:0 0 18px}.creator-open{display:block!important;grid-column:1/-1}
        @media(max-width:760px){.topbar{height:auto;min-height:70px}.user-area{display:none}.shell{display:block}.sidebar{border-right:0;border-bottom:1px solid var(--line)}.content{padding:26px 18px}.sidebar>.nav-link,.sidebar>details{display:inline-block;width:auto;vertical-align:top}.subnav{position:absolute;z-index:10;background:#fff;border:1px solid var(--line);border-radius:8px;padding:5px}.master-tools{grid-template-columns:1fr}}
    </style>
    @stack('styles')
</head>
<body>
<header class="topbar">
    <a class="brand" href="{{ route('provider-payments.dashboard') }}"><span class="brand-mark">4N</span><span><strong>Pago Proveedores</strong><small>4N Logística · PMBC</small></span></a>
    <div class="topbar-right">@yield('topbar-checklist')<div class="user-area"><a href="{{ route('portal.home') }}">Inicio</a> · <a href="{{ route('portal.profile') }}">{{ auth()->user()?->name }}</a><form method="POST" action="{{ route('logout') }}" style="display:inline">@csrf<button type="submit" style="border:0;background:none;color:inherit;cursor:pointer;font:inherit">Salir</button></form></div></div>
</header>
<div class="shell">
    <aside class="sidebar" aria-label="Menú Pago Proveedores">
        <a class="nav-link" href="{{ route('portal.home') }}" id="provider-back-link">← Volver</a>
        <a class="nav-link {{ request()->routeIs('provider-payments.dashboard') ? 'active' : '' }}" href="{{ route('provider-payments.dashboard') }}">Resumen</a>
        <details {{ (request()->routeIs('provider-payments.courier-movements.*') && ! request()->routeIs('provider-payments.courier-movements.compile')) || request()->routeIs('provider-payments.maintainers.pesos.reales*') ? 'open' : '' }}>
            <summary class="nav-summary">Carga Movimientos Courier</summary>
            <nav class="subnav">
                <a class="{{ request()->routeIs('provider-payments.maintainers.pesos.reales*') ? 'active' : '' }}" href="{{ route('provider-payments.maintainers.pesos.reales') }}">Pesos Reales</a>
                <a class="{{ request()->routeIs('provider-payments.courier-movements.upload', 'provider-payments.courier-movements.lanas', 'provider-payments.courier-movements.retornos', 'provider-payments.courier-movements.validate', 'provider-payments.courier-movements.review-parameters') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.upload') }}">Bases Courier</a>
                <a class="{{ request()->routeIs('provider-payments.courier-movements.externos*') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.externos') }}">Envíos Externos</a>
                <a class="{{ request()->routeIs('provider-payments.courier-movements.especiales') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.especiales') }}">Courier Especiales</a>
                <a class="{{ request()->routeIs('provider-payments.courier-movements.rutas-cv') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.rutas-cv') }}">Rutas CV</a>
                <a class="{{ request()->routeIs('provider-payments.courier-movements.servicios') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.servicios') }}">Servicios</a>
                <a class="{{ request()->routeIs('provider-payments.courier-movements.acuerdos') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.acuerdos') }}">Acuerdos</a>
                <a class="{{ request()->routeIs('provider-payments.courier-movements.apoyo-alza') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.apoyo-alza') }}">Apoyo Alza</a>
                <a class="{{ request()->routeIs('provider-payments.courier-movements.visitas*') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.visitas') }}">Visitas Diarias</a>
            </nav>
        </details>
        <a class="nav-link {{ request()->routeIs('provider-payments.courier-movements.compile') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.compile') }}">Compilar Movimientos</a>
        <details {{ request()->routeIs('provider-payments.maintainers.*') ? 'open' : '' }}><summary class="nav-summary">Mantenedores</summary><nav class="subnav">
            @foreach (['Clientes' => 'clientes', 'Sucursales' => 'sucursales', 'Servicios' => 'servicios', 'Proveedores' => 'proveedores', 'Coberturas' => 'coberturas', 'Centro de Costos' => 'centro-de-costos', 'Tarifas CC' => 'tarifas-cc', 'Llave CC' => 'llave-centro-costos', 'Estados' => 'estados', 'Bancos' => 'bancos', 'Vehículos' => 'vehiculos'] as $label => $routeName)
                <a href="{{ route('provider-payments.maintainers.'.$routeName) }}">{{ $label }}</a>
            @endforeach
            <a href="{{ route('provider-payments.maintainers.pesos.transformados') }}">Peso Transformado</a>
        </nav></details>
    </aside>
    <main class="content">
        @if(request()->routeIs('provider-payments.maintainers.*') && !request()->routeIs('provider-payments.maintainers.pesos.reales', 'provider-payments.maintainers.tarifas-cc*', 'provider-payments.maintainers.llave-centro-costos*'))
            <section class="master-tools" aria-label="Buscar y filtrar registros">
                <label>Buscar<input id="master_search" type="search" placeholder="Buscar por nombre, RUT, código, patente, comuna…" autocomplete="off"></label>
                @if(request()->routeIs('provider-payments.maintainers.proveedores'))
                    <label>Filtrar por tipo operador<select id="master_status"><option value="all">Todos</option>@foreach($providers->pluck('operator_type')->filter()->unique()->sort()->values() as $operatorType)<option value="operator:{{ $operatorType }}">{{ $operatorType }}</option>@endforeach</select></label>
                @elseif(!request()->routeIs('provider-payments.maintainers.clientes'))
                    <label>Filtrar por estado<select id="master_status"><option value="all">Todos</option><option value="active">Activos / PAGAR</option><option value="inactive">Inactivos / NO PAGAR</option></select></label>
                @endif
                <span id="master_results" class="master-results"></span>
            </section>
        @endif
        @yield('content')
    </main>
</div>
@include('provider-payments::partials.system-visual-styles')
@stack('scripts')
<script>
document.getElementById('provider-back-link')?.addEventListener('click', event => {
    if ({{ request()->routeIs('provider-payments.dashboard') ? 'true' : 'false' }}) return;
    if (window.history.length <= 1) return;
    event.preventDefault();
    window.history.back();
});
(() => {
    const main = document.querySelector('main.content');
    if (!main || main.querySelector('.page-heading')) return;
    const title = main.querySelector('h1');
    const eyebrow = main.querySelector('.eyebrow');
    if (!title || !eyebrow) return;
    const back = main.querySelector('.back');
    const first = back || eyebrow;
    const heading = document.createElement('div');
    heading.className = 'page-heading';
    first.before(heading);
    if (back) heading.append(back);
    heading.append(eyebrow, title);
})();
</script>
@if(request()->routeIs('provider-payments.maintainers.*') && !request()->routeIs('provider-payments.maintainers.pesos.reales', 'provider-payments.maintainers.tarifas-cc*', 'provider-payments.maintainers.llave-centro-costos*'))
<script>
(() => {
    const grid = document.querySelector('.master-grid');
    const creator = grid?.firstElementChild;
    if (creator?.classList.contains('card') && creator.querySelector('form')) {
        const title = creator.querySelector('h2')?.textContent.trim() || 'Nuevo registro';
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'new-record-toggle';
        toggle.textContent = title.replace(/^Nuevo\b/i, 'Crear nuevo').replace(/^Nueva\b/i, 'Crear nueva');
        grid.before(toggle);
        const startsOpen = Boolean(creator.querySelector('.warning'));
        creator.hidden = !startsOpen;
        creator.classList.toggle('creator-open', startsOpen);
        toggle.addEventListener('click', () => {
            creator.hidden = !creator.hidden;
            creator.classList.toggle('creator-open', !creator.hidden);
            toggle.textContent = creator.hidden ? title.replace(/^Nuevo\b/i, 'Crear nuevo').replace(/^Nueva\b/i, 'Crear nueva') : 'Cerrar formulario';
        });
    }
    const search = document.getElementById('master_search');
    const status = document.getElementById('master_status');
    const results = document.getElementById('master_results');
    const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const candidates = () => {
        const pageRecords = [...document.querySelectorAll('[data-master-record]')];
        return pageRecords.length ? pageRecords : [...document.querySelectorAll('.record, table tbody tr')];
    };
    const apply = () => {
        const query = normalize(search.value.trim());
        let visible = 0;
        const rows = candidates();
        rows.forEach(item => {
            const text = normalize(item.textContent);
            const inactive = item.dataset.state ? item.dataset.state === 'inactive' : text.includes('inactivo') || text.includes('inactiva') || text.includes('no pagar');
            const active = item.dataset.state ? item.dataset.state === 'active' : !inactive && (text.includes('activo') || text.includes('activa') || text.includes('pagar'));
            const selectedStatus = status?.value || 'all';
            const matchesOperator = selectedStatus.startsWith('operator:')
                ? normalize(item.dataset.operator) === normalize(selectedStatus.substring(9))
                : true;
            const matchesStatus = matchesOperator && (selectedStatus === 'all' || selectedStatus.startsWith('operator:') || (selectedStatus === 'active' && active) || (selectedStatus === 'inactive' && inactive));
            const show = text.includes(query) && matchesStatus;
            item.hidden = !show;
            if (show) visible++;
        });
        const filtering = query !== '' || (status?.value || 'all') !== 'all';
        document.querySelectorAll('.subgroup').forEach(group => {
            const hasVisibleRows = [...group.querySelectorAll('[data-master-record]')].some(row => !row.hidden);
            group.hidden = !hasVisibleRows;
            if (filtering && hasVisibleRows) group.open = true;
        });
        document.querySelectorAll('.group').forEach(group => {
            const hasVisibleRows = [...group.querySelectorAll('[data-master-record]')].some(row => !row.hidden);
            group.hidden = !hasVisibleRows;
            if (filtering && hasVisibleRows) group.open = true;
        });
        results.textContent = rows.length ? `${visible} de ${rows.length} registros` : 'Sin listado en esta pantalla';
    };
    search.addEventListener('input', apply);
    status?.addEventListener('change', apply);
    apply();
})();
</script>
@endif
</body>
</html>
