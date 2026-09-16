<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pago a Proveedores</title>
    <style>
        :root { --ink: #201e1f; --turquoise: #5db8bc; --paper: #f6f8f8; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; background: var(--paper); color: var(--ink); font-family: system-ui, sans-serif; }
        .sidebar { width: 280px; flex: 0 0 280px; padding: 24px 16px; background: #fff; border-right: 5px solid var(--turquoise); }
        .sidebar img { display: block; width: 150px; margin: 0 auto 30px; }
        .menu-link, summary { display: block; padding: 14px; border-radius: 7px; color: var(--ink); cursor: pointer; font-weight: 650; text-decoration: none; }
        summary { list-style: none; }
        summary::-webkit-details-marker { display: none; }
        summary::after { float: right; color: #277d80; content: '⌄'; }
        details[open] summary::after { content: '⌃'; }
        details a { display: block; padding: 10px 16px 10px 30px; color: #465154; font-size: 14px; text-decoration: none; }
        details a:hover, .menu-link:hover, summary:hover { background: #eaf7f7; color: #277d80; }
        .content { width: min(1160px, 100%); padding: 46px; }
        h1 { margin: 0; font-size: 34px; }
        .intro { margin: 8px 0 30px; color: #5e6567; font-size: 17px; }
        .summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px; }
        .card { overflow: hidden; border: 1px solid #dce5e5; border-radius: 12px; background: #fff; box-shadow: 0 2px 8px rgb(32 30 31 / 6%); }
        .card h2 { margin: 0; padding: 18px 20px; border-bottom: 3px solid var(--turquoise); font-size: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 13px 20px; border-bottom: 1px solid #edf1f1; text-align: left; }
        th { background: #f4fbfb; color: #277d80; font-size: 13px; text-transform: uppercase; }
        td:last-child, th:last-child { text-align: right; font-variant-numeric: tabular-nums; }
        tr:last-child td { border-bottom: 0; }
        .empty { padding: 22px 20px; margin: 0; color: #687476; }
        @media (max-width: 780px) { body { display: block; } .sidebar { width: 100%; } .content { padding: 28px 18px; } .summary { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <aside class="sidebar">
        <img src="{{ asset('images/logo4n.jpg') }}" alt="4N Logística">
        <a class="menu-link" href="{{ route('provider-payments.courier-movements.upload') }}">Carga Movimientos Courier</a>
        <a class="menu-link" href="{{ route('provider-payments.courier-movements.compile') }}">Compilar Movimientos Courier</a>
        <details>
            <summary>Mantenedor</summary>
            @foreach (['Clientes', 'Sucursales', 'Servicios', 'Centro de Costos', 'Pesos', 'Proveedores', 'Bancos', 'Vehículos', 'Coberturas', 'Llave centro costos', 'Estados'] as $name)
                <a href="{{ route('provider-payments.maintainers.'.str($name)->slug()) }}">{{ $name }}</a>
            @endforeach
        </details>
    </aside>
    <main class="content">
        <h1>Pago a Proveedores</h1>
        <p class="intro">Resumen de los movimientos Courier que ya están cargados en el sistema.</p>
        <section class="summary" aria-label="Resumen de movimientos Courier">
            <article class="card">
                <h2>Registros por cliente</h2>
                @forelse ($merchantCounts as $row)
                    @if ($loop->first)
                        <table><thead><tr><th>Comerciante</th><th>Registros</th></tr></thead><tbody>
                    @endif
                    <tr><td>{{ $row->merchant_name ?: 'Sin comerciante' }}</td><td>{{ number_format($row->total, 0, ',', '.') }}</td></tr>
                    @if ($loop->last)
                        </tbody></table>
                    @endif
                @empty
                    <p class="empty">Aún no hay movimientos Courier cargados.</p>
                @endforelse
            </article>
            <article class="card">
                <h2>Registros por estado</h2>
                @forelse ($statusCounts as $row)
                    @if ($loop->first)
                        <table><thead><tr><th>Estado</th><th>Registros</th></tr></thead><tbody>
                    @endif
                    <tr><td>{{ $row->status ?: 'Sin estado' }}</td><td>{{ number_format($row->total, 0, ',', '.') }}</td></tr>
                    @if ($loop->last)
                        </tbody></table>
                    @endif
                @empty
                    <p class="empty">Aún no hay movimientos Courier cargados.</p>
                @endforelse
            </article>
        </section>
    </main>
</body>
</html>