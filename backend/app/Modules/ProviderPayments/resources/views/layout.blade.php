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
        .brand strong,.brand small { display:block; }.brand strong{font-size:16px}.brand small{margin-top:2px;color:#c9eeee;font-size:12px}.user-area{font-size:13px}.user-area a{margin-left:18px;color:var(--turquoise);text-decoration:none}
        .shell { display:grid; grid-template-columns:222px minmax(0,1fr); min-height:calc(100vh - 76px); }
        .sidebar { padding:23px 9px; border-right:1px solid var(--line); background:#fff; }
        .nav-link,.nav-summary { display:block; width:100%; padding:12px 15px; border-radius:9px; color:#26474c; cursor:pointer; font-size:14px; font-weight:700; text-decoration:none; }
        .nav-link:hover,.nav-summary:hover,.nav-link.active { background:var(--turquoise-soft); color:#006d70; }
        .nav-summary { list-style:none; }.nav-summary::-webkit-details-marker{display:none}.nav-summary::after{float:right;content:'⌄';color:var(--turquoise-dark)}details[open]>.nav-summary::after{content:'⌃'}
        .subnav a { display:block; padding:9px 15px 9px 30px; color:#53696d; font-size:13px; text-decoration:none; }.subnav a:hover,.subnav a.active{color:var(--turquoise-dark);font-weight:800}
        .content { min-width:0; padding:36px clamp(24px,5vw,84px) 60px; }
        .eyebrow { margin:0 0 8px; color:var(--turquoise-dark); font-size:11px; font-weight:900; letter-spacing:1.6px; text-transform:uppercase; }
        h1 { margin:0; color:#071b1d; font-size:30px; line-height:1.15; } h2{color:#071b1d}.intro{margin:8px 0 26px;color:var(--muted)}
        a{color:var(--turquoise-dark)}.back{display:inline-block;margin-bottom:18px;font-size:14px}
        .card,.box,details.review-group { background:#fff; border:1px solid var(--line); border-radius:14px; box-shadow:0 1px 2px rgb(6 31 32 / 3%); }
        .card{padding:26px}.button,button{display:inline-block;padding:11px 18px;border:0;border-radius:8px;background:var(--turquoise-dark);color:#fff;cursor:pointer;font-weight:750;text-decoration:none}.button:hover,button:hover{background:#00696c}button:disabled{opacity:.6}
        table{width:100%;border-collapse:collapse}th,td{padding:12px 16px;border-bottom:1px solid #e7eeee;text-align:left}th{background:#effafa;color:#087477;font-size:12px;text-transform:uppercase}td:last-child,th:last-child{text-align:right}tr:last-child td{border-bottom:0}.table-wrap{overflow:auto}
        .note{color:var(--muted);font-size:14px}.warning{padding:15px;border-left:4px solid #db9f34;background:#fff6e4}.progress-track{height:9px;overflow:hidden;border-radius:99px;background:#dcecec}.progress-bar{width:35%;height:100%;background:var(--turquoise);animation:loading 1.2s ease-in-out infinite}@keyframes loading{from{transform:translateX(-110%)}to{transform:translateX(310%)}}progress{width:100%;accent-color:var(--turquoise-dark)}
        .master-tools{display:grid;grid-template-columns:minmax(240px,1fr) 220px auto;gap:10px;align-items:end;margin:0 0 22px;padding:16px;border:1px solid var(--line);border-radius:12px;background:#fff}.master-tools label{display:grid;gap:6px;color:var(--muted);font-size:12px;font-weight:800;text-transform:uppercase}.master-tools input,.master-tools select{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--ink)}.master-results{padding:10px 0;color:var(--muted);font-size:13px;white-space:nowrap}.new-record-toggle{margin:0 0 18px}.creator-open{display:block!important;grid-column:1/-1}
        @media(max-width:760px){.topbar{height:auto;min-height:70px}.user-area{display:none}.shell{display:block}.sidebar{border-right:0;border-bottom:1px solid var(--line)}.content{padding:26px 18px}.sidebar>.nav-link,.sidebar>details{display:inline-block;width:auto;vertical-align:top}.subnav{position:absolute;z-index:10;background:#fff;border:1px solid var(--line);border-radius:8px;padding:5px}.master-tools{grid-template-columns:1fr}}
    </style>
    @stack('styles')
</head>
<body>
<header class="topbar">
    <a class="brand" href="{{ route('provider-payments.dashboard') }}"><span class="brand-mark">4N</span><span><strong>Pago Proveedores</strong><small>4N Logística · PMBC</small></span></a>
    <div class="user-area">Administrador 4N · Administrador <a href="#">Salir</a></div>
</header>
<div class="shell">
    <aside class="sidebar" aria-label="Menú Pago Proveedores">
        <a class="nav-link {{ request()->routeIs('provider-payments.dashboard') ? 'active' : '' }}" href="{{ route('provider-payments.dashboard') }}">Resumen</a>
        <details {{ request()->routeIs('provider-payments.courier-movements.*') && ! request()->routeIs('provider-payments.courier-movements.compile') ? 'open' : '' }}>
            <summary class="nav-summary">Carga Movimientos Courier</summary>
            <nav class="subnav">
                @php($activeCourierProcess = session('courier_review.process_type', 'variables'))
                <a class="{{ request()->routeIs('provider-payments.courier-movements.upload') || (request()->routeIs('provider-payments.courier-movements.validate', 'provider-payments.courier-movements.review-parameters') && $activeCourierProcess === 'variables') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.upload') }}">Courier Variables</a>
                <a class="{{ request()->routeIs('provider-payments.courier-movements.lanas') || (request()->routeIs('provider-payments.courier-movements.validate', 'provider-payments.courier-movements.review-parameters') && $activeCourierProcess === 'lanas') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.lanas') }}">Courier Lanas</a>
                <a class="{{ request()->routeIs('provider-payments.courier-movements.retornos') || (request()->routeIs('provider-payments.courier-movements.validate', 'provider-payments.courier-movements.review-parameters') && $activeCourierProcess === 'retornos') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.retornos') }}">Courier Retornos</a>
                <a class="{{ request()->routeIs('provider-payments.courier-movements.especiales') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.especiales') }}">Courier Especiales</a>
                <a class="{{ request()->routeIs('provider-payments.courier-movements.rutas-cv') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.rutas-cv') }}">Rutas CV</a>
                <a class="{{ request()->routeIs('provider-payments.courier-movements.servicios') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.servicios') }}">Servicios</a>
                <a class="{{ request()->routeIs('provider-payments.courier-movements.acuerdos') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.acuerdos') }}">Acuerdos</a>
            </nav>
        </details>
        <a class="nav-link {{ request()->routeIs('provider-payments.courier-movements.compile') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.compile') }}">Compilar Movimientos</a>
        <details {{ request()->routeIs('provider-payments.maintainers.*') ? 'open' : '' }}><summary class="nav-summary">Mantenedores</summary><nav class="subnav">
            @foreach (['Clientes', 'Sucursales', 'Servicios', 'Centro de Costos', 'Tarifas CC'] as $name)
                <a href="{{ route('provider-payments.maintainers.'.str($name)->slug()) }}">{{ $name }}</a>
            @endforeach
            @foreach (['Proveedores', 'Bancos', 'Vehículos', 'Coberturas', 'Llave centro costos', 'Estados'] as $name)
                <a href="{{ route('provider-payments.maintainers.'.str($name)->slug()) }}">{{ $name }}</a>
            @endforeach
            <details {{ request()->routeIs('provider-payments.maintainers.pesos*') ? 'open' : '' }}><summary class="nav-summary">Pesos</summary><nav class="subnav"><a href="{{ route('provider-payments.maintainers.pesos.transformados') }}">Peso Transformado</a><a href="{{ route('provider-payments.maintainers.pesos.reales') }}">Peso Real</a></nav></details>
        </nav></details>
    </aside>
    <main class="content">
        @if(request()->routeIs('provider-payments.maintainers.*') && !request()->routeIs('provider-payments.maintainers.pesos.reales', 'provider-payments.maintainers.tarifas-cc*'))
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
@stack('scripts')
@if(request()->routeIs('provider-payments.maintainers.*') && !request()->routeIs('provider-payments.maintainers.pesos.reales', 'provider-payments.maintainers.tarifas-cc*'))
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
