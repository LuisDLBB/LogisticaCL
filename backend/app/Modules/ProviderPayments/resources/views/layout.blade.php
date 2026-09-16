<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
        .subnav a { display:block; padding:9px 15px 9px 30px; color:#53696d; font-size:13px; text-decoration:none; }.subnav a:hover{color:var(--turquoise-dark)}
        .content { min-width:0; padding:36px clamp(24px,5vw,84px) 60px; }
        .eyebrow { margin:0 0 8px; color:var(--turquoise-dark); font-size:11px; font-weight:900; letter-spacing:1.6px; text-transform:uppercase; }
        h1 { margin:0; color:#071b1d; font-size:30px; line-height:1.15; } h2{color:#071b1d}.intro{margin:8px 0 26px;color:var(--muted)}
        a{color:var(--turquoise-dark)}.back{display:inline-block;margin-bottom:18px;font-size:14px}
        .card,.box,details.review-group { background:#fff; border:1px solid var(--line); border-radius:14px; box-shadow:0 1px 2px rgb(6 31 32 / 3%); }
        .card{padding:26px}.button,button{display:inline-block;padding:11px 18px;border:0;border-radius:8px;background:var(--turquoise-dark);color:#fff;cursor:pointer;font-weight:750;text-decoration:none}.button:hover,button:hover{background:#00696c}button:disabled{opacity:.6}
        table{width:100%;border-collapse:collapse}th,td{padding:12px 16px;border-bottom:1px solid #e7eeee;text-align:left}th{background:#effafa;color:#087477;font-size:12px;text-transform:uppercase}td:last-child,th:last-child{text-align:right}tr:last-child td{border-bottom:0}.table-wrap{overflow:auto}
        .note{color:var(--muted);font-size:14px}.warning{padding:15px;border-left:4px solid #db9f34;background:#fff6e4}.progress-track{height:9px;overflow:hidden;border-radius:99px;background:#dcecec}.progress-bar{width:35%;height:100%;background:var(--turquoise);animation:loading 1.2s ease-in-out infinite}@keyframes loading{from{transform:translateX(-110%)}to{transform:translateX(310%)}}progress{width:100%;accent-color:var(--turquoise-dark)}
        @media(max-width:760px){.topbar{height:auto;min-height:70px}.user-area{display:none}.shell{display:block}.sidebar{border-right:0;border-bottom:1px solid var(--line)}.content{padding:26px 18px}.sidebar>.nav-link,.sidebar>details{display:inline-block;width:auto;vertical-align:top}.subnav{position:absolute;z-index:10;background:#fff;border:1px solid var(--line);border-radius:8px;padding:5px}}
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
        <a class="nav-link {{ request()->routeIs('provider-payments.courier-movements.upload') || request()->routeIs('provider-payments.courier-movements.validate') || request()->routeIs('provider-payments.courier-movements.review-parameters') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.upload') }}">Carga Movimientos Courier</a>
        <a class="nav-link {{ request()->routeIs('provider-payments.courier-movements.compile') ? 'active' : '' }}" href="{{ route('provider-payments.courier-movements.compile') }}">Compilar Movimientos</a>
        <details {{ request()->routeIs('provider-payments.maintainers.*') ? 'open' : '' }}><summary class="nav-summary">Mantenedores</summary><nav class="subnav">
            @foreach (['Clientes', 'Sucursales', 'Servicios', 'Centro de Costos', 'Pesos', 'Proveedores', 'Bancos', 'Vehículos', 'Coberturas', 'Llave centro costos', 'Estados'] as $name)
                <a href="{{ route('provider-payments.maintainers.'.str($name)->slug()) }}">{{ $name }}</a>
            @endforeach
        </nav></details>
    </aside>
    <main class="content">@yield('content')</main>
</div>
@stack('scripts')
</body>
</html>
