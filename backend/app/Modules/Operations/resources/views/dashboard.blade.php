@extends('operations::layout')
@section('title', 'Resumen operativo')
@section('content')
<style>
    .ope-dashboard-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:17px}
    .ope-dashboard-head .intro{margin-bottom:0}
    .ope-dashboard-actions{display:flex;flex-wrap:wrap;gap:8px}
    .ope-dashboard-filters{display:flex;flex-wrap:wrap;align-items:end;gap:10px;margin-bottom:17px}
    .ope-dashboard-filters label{display:grid;gap:5px;color:var(--muted);font-size:12px;font-weight:750}
    .ope-dashboard-filters select{min-width:145px;padding:8px 10px;border:1px solid var(--line);border-radius:7px;background:#fff;color:var(--ink)}
    .ope-dashboard-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
    .ope-dashboard-stats .card{padding:15px 17px}
    .ope-dashboard-stats small{display:block;color:var(--muted);font-size:12px}
    .ope-dashboard-stats strong{display:block;margin:5px 0;font-size:25px;font-weight:800;font-variant-numeric:tabular-nums}
    .ope-dashboard-stats span{color:var(--muted);font-size:12px}
    .ope-dashboard-duo{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr);gap:16px;margin-bottom:18px}
    .ope-dashboard-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;margin-bottom:12px}
    .ope-dashboard-section-head h2{margin:0 0 3px}
    .ope-dashboard-section-head p{margin:0}
    .ope-dashboard-week-row{display:grid;grid-template-columns:75px minmax(0,1fr) 80px;align-items:center;gap:10px;margin:8px 0;font-size:13px}
    .ope-dashboard-week-track{height:11px;border-radius:10px;background:var(--turquoise-soft);overflow:hidden}
    .ope-dashboard-week-fill{height:100%;border-radius:10px;background:var(--turquoise-dark)}
    .ope-dashboard-week-row strong{text-align:right;font-variant-numeric:tabular-nums}
    .ope-dashboard-tabs{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:13px}
    .ope-dashboard-tabs a{display:inline-flex;align-items:center;min-height:35px;padding:7px 12px;border-radius:7px;border:1px solid var(--line);background:#fff;text-decoration:none;font-size:13px;font-weight:750}
    .ope-dashboard-tabs a.active{background:var(--turquoise-soft);border-color:var(--turquoise-dark);color:#00696c}
    .ope-dashboard-pivot th:not(:first-child),.ope-dashboard-pivot td:not(:first-child){text-align:right;font-variant-numeric:tabular-nums}
    .ope-dashboard-pivot tr[data-level="0"]{background:#eaf6f6;font-weight:750}
    .ope-dashboard-pivot tr[data-level="1"] td:first-child{padding-left:30px}
    .ope-dashboard-pivot tr[data-level="2"] td:first-child{padding-left:53px;color:#3f6267}
    .ope-dashboard-pivot .ope-dashboard-toggle{display:inline-flex;min-height:28px;padding:3px 5px;border:0;background:transparent;color:var(--ink);text-align:left;font-size:13px;font-weight:750}
    .ope-dashboard-pivot .ope-dashboard-toggle:hover{background:var(--turquoise-soft);color:var(--turquoise-dark)}
    .ope-dashboard-pivot .ope-dashboard-meter{height:5px;margin:4px 0 0 auto;max-width:100px;border-radius:9px;background:var(--turquoise-soft);overflow:hidden}
    .ope-dashboard-pivot .ope-dashboard-meter span{display:block;height:100%;background:var(--turquoise-dark)}
    .ope-dashboard-pivot tfoot{background:var(--turquoise-soft);font-weight:800}
    .ope-dashboard-pivot tfoot td{border-top:2px solid var(--turquoise-dark)}
    .ope-dashboard-reserve{display:inline-block;min-width:25px;padding:3px 7px;border-radius:5px;background:#fff3df;color:#754900}
    .ope-dashboard-pivot-note{margin:13px 0 0}
    .ope-dashboard-receipts td:nth-child(n+3),.ope-dashboard-receipts th:nth-child(n+3){text-align:right;font-variant-numeric:tabular-nums}
    .ope-dashboard-receipts small{display:block;color:var(--muted)}
    @media(max-width:1100px){.ope-dashboard-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.ope-dashboard-duo{grid-template-columns:1fr}}
    @media(max-width:680px){.ope-dashboard-head{display:block}.ope-dashboard-actions{margin-top:12px}.ope-dashboard-filters label{flex:1 1 150px}.ope-dashboard-filters select{width:100%}.ope-dashboard-stats{gap:8px}.ope-dashboard-stats .card{padding:11px}.ope-dashboard-stats strong{font-size:20px}.ope-dashboard-pivot{min-width:650px}.ope-dashboard-receipts{min-width:550px}}
</style>

<div class="ope-dashboard-head">
    <div><h1>Resumen operativo</h1><p class="intro">Recepciones cerradas y carga de troncales, postas y agencias.</p></div>
    <div class="ope-dashboard-actions"><a class="button ope-secondary-button" href="{{ route('operations.lots.index') }}">Procesos Trabajados</a><a class="button" href="{{ route('operations.departures.overview') }}">Ver salidas</a></div>
</div>

<form class="ope-dashboard-filters" method="get" action="{{ route('operations.dashboard') }}">
    <label>Período
        <select name="period">
            <option value="last" @selected($dashboard['period'] === 'last')>Último día trabajado · {{ \Carbon\Carbon::parse($dashboard['lastDate'])->format('d/m/Y') }}</option>
            <option value="today" @selected($dashboard['period'] === 'today')>Hoy · {{ \Carbon\Carbon::parse($dashboard['today'])->format('d/m/Y') }}</option>
            <option value="week" @selected($dashboard['period'] === 'week')>Semana actual</option>
        </select>
    </label>
    <label>Origen
        <select name="source"><option value="all" @selected($dashboard['source'] === 'all')>Sistema y Excel</option><option value="system" @selected($dashboard['source'] === 'system')>Recepción Sistema</option><option value="excel" @selected($dashboard['source'] === 'excel')>Archivo Excel</option></select>
    </label>
    <label>Estado
        <select name="status"><option value="all" @selected($dashboard['status'] === 'all')>Todos</option><option value="approved" @selected($dashboard['status'] === 'approved')>Aprobadas</option><option value="draft" @selected($dashboard['status'] === 'draft')>Programadas</option><option value="reserve" @selected($dashboard['status'] === 'reserve')>En reserva</option></select>
    </label>
    <input type="hidden" name="group" value="{{ $dashboard['grouping'] }}">
    <button type="submit">Aplicar filtros</button>
</form>

<div class="ope-dashboard-stats" aria-label="Totales del período">
    <div class="card"><small>Recepciones cerradas hoy</small><strong>{{ number_format($dashboard['receipts']['count'], 0, ',', '.') }}</strong><span>{{ number_format($dashboard['receipts']['packages'], 0, ',', '.') }} bultos recepcionados</span></div>
    <div class="card"><small>Bultos únicos del período</small><strong>{{ number_format($dashboard['totals']['count'], 0, ',', '.') }}</strong><span>Salidas y reservas, sin duplicar tramos</span></div>
    <div class="card"><small>Peso total</small><strong>{{ $dashboard['totals']['weight'] }} kg</strong><span>Del peso guardado en Operaciones</span></div>
    <div class="card"><small>En reserva</small><strong>{{ number_format($dashboard['totals']['reserved'], 0, ',', '.') }}</strong><span>Pendientes de incluir en otra salida</span></div>
</div>

<div class="ope-dashboard-duo">
    <section class="card">
        <div class="ope-dashboard-section-head"><div><h2>Recepciones del día</h2><p class="note">{{ \Carbon\Carbon::parse($dashboard['today'])->format('d/m/Y') }} · cargas cerradas</p></div><a href="{{ route('operations.lots.index') }}">Preparar proceso</a></div>
        <div class="table-wrap"><table class="ope-table ope-dashboard-receipts"><thead><tr><th>Recepción</th><th>Estado</th><th>Bultos</th></tr></thead><tbody>
            @forelse($dashboard['receipts']['items'] as $receipt)
                <tr><td><a href="{{ $receipt['url'] }}">{{ $receipt['source'] }} · {{ $receipt['filename'] }}</a><small>{{ $receipt['time'] }}</small></td><td>{{ $receipt['processed'] ? 'Incluida en proceso' : 'Lista para proceso' }}</td><td>{{ number_format($receipt['packages'], 0, ',', '.') }}</td></tr>
            @empty
                <tr><td colspan="3" class="ope-empty">Aún no hay recepciones cerradas hoy.</td></tr>
            @endforelse
        </tbody></table></div>
        @if($dashboard['receipts']['count'] > count($dashboard['receipts']['items']))<p class="note">Se muestran las 8 recepciones más recientes del día.</p>@endif
    </section>
    <section class="card">
        <div class="ope-dashboard-section-head"><div><h2>Movimiento semanal</h2><p class="note">Bultos únicos por fecha de salida o reserva · {{ \Carbon\Carbon::parse($dashboard['week'][0]['date'])->format('d/m') }}–{{ \Carbon\Carbon::parse($dashboard['week'][6]['date'])->format('d/m') }}</p></div></div>
        @php($maxWeek = max(1, ...array_column($dashboard['week'], 'count')))
        @foreach($dashboard['week'] as $day)
            <div class="ope-dashboard-week-row"><span>{{ ucfirst($day['label']) }}</span><div class="ope-dashboard-week-track" role="img" aria-label="{{ $day['date'] }}: {{ $day['count'] }} bultos, {{ $day['weight'] }} kilos"><div class="ope-dashboard-week-fill" style="width:{{ round($day['count'] / $maxWeek * 100) }}%"></div></div><strong>{{ number_format($day['count'], 0, ',', '.') }}</strong></div>
        @endforeach
    </section>
</div>

<section class="card">
    <div class="ope-dashboard-section-head"><div><h2>Tabla dinámica de movimientos</h2><p class="note">{{ \Carbon\Carbon::parse($dashboard['periodStart'])->format('d/m/Y') }}–{{ \Carbon\Carbon::parse($dashboard['periodEnd'])->format('d/m/Y') }} · abre una fila para ver el siguiente nivel</p></div></div>
    <nav class="ope-dashboard-tabs" aria-label="Agrupar movimientos">
        <a href="{{ route('operations.dashboard', ['period' => $dashboard['period'], 'source' => $dashboard['source'], 'status' => $dashboard['status'], 'group' => 'route']) }}" class="{{ $dashboard['grouping'] === 'route' ? 'active' : '' }}" @if($dashboard['grouping'] === 'route') aria-current="page" @endif>Por ruta</a>
        <a href="{{ route('operations.dashboard', ['period' => $dashboard['period'], 'source' => $dashboard['source'], 'status' => $dashboard['status'], 'group' => 'client']) }}" class="{{ $dashboard['grouping'] === 'client' ? 'active' : '' }}" @if($dashboard['grouping'] === 'client') aria-current="page" @endif>Por cliente</a>
    </nav>
    <div class="table-wrap"><table class="ope-table ope-dashboard-pivot"><thead><tr><th>{{ $dashboard['grouping'] === 'route' ? 'Troncal → Posta → Agencia' : 'Cliente → Troncal → Posta' }}</th><th>Bultos</th><th>Peso kg</th><th>En reserva</th><th>Aprobados</th></tr></thead><tbody data-dashboard-pivot>
        @forelse($dashboard['rows'] as $row)
            <tr data-dashboard-row="{{ $row['id'] }}" @if($row['parent']) data-dashboard-parent="{{ $row['parent'] }}" hidden @endif data-level="{{ $row['level'] }}">
                <td>@if($row['hasChildren'])<button type="button" class="ope-dashboard-toggle" data-dashboard-toggle="{{ $row['id'] }}" aria-expanded="false"><span aria-hidden="true">▸</span> {{ $row['label'] }}</button>@else{{ $row['label'] }}@endif</td>
                <td>{{ number_format($row['count'], 0, ',', '.') }}</td>
                <td>{{ $row['weight'] }}@if($row['level'] === 0)<div class="ope-dashboard-meter" aria-hidden="true"><span style="width:{{ round($row['weight'] / $dashboard['maxWeight'] * 100) }}%"></span></div>@endif</td>
                <td>@if($row['reserved'])<span class="ope-dashboard-reserve">{{ number_format($row['reserved'], 0, ',', '.') }}</span>@else—@endif</td>
                <td>{{ $row['approved'] ? number_format($row['approved'], 0, ',', '.') : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="ope-empty">No hay salidas ni reservas para esta selección.</td></tr>
        @endforelse
    </tbody><tfoot><tr><td>Total único del período</td><td>{{ number_format($dashboard['totals']['count'], 0, ',', '.') }}</td><td>{{ $dashboard['totals']['weight'] }}</td><td>{{ number_format($dashboard['totals']['reserved'], 0, ',', '.') }}</td><td>{{ number_format($dashboard['totals']['approved'], 0, ',', '.') }}</td></tr></tfoot></table></div>
    <p class="note ope-dashboard-pivot-note">Un bulto puede aparecer en troncal y posta porque recorre ambos tramos. El total cuenta cada código una sola vez; no sumes las filas entre niveles.</p>
</section>

<script>
document.querySelector('[data-dashboard-pivot]')?.addEventListener('click', event => {
    const button = event.target.closest('[data-dashboard-toggle]');
    if (!button) return;
    const table = event.currentTarget;
    const id = button.dataset.dashboardToggle;
    const opening = button.getAttribute('aria-expanded') !== 'true';
    button.setAttribute('aria-expanded', String(opening));
    button.querySelector('span').textContent = opening ? '▾' : '▸';
    const collapse = parent => {
        table.querySelectorAll('[data-dashboard-parent="' + parent + '"]').forEach(row => {
            row.hidden = true;
            const nested = row.querySelector('[data-dashboard-toggle]');
            if (nested) {
                nested.setAttribute('aria-expanded', 'false');
                nested.querySelector('span').textContent = '▸';
                collapse(row.dataset.dashboardRow);
            }
        });
    };
    table.querySelectorAll('[data-dashboard-parent="' + id + '"]').forEach(row => row.hidden = !opening);
    if (!opening) table.querySelectorAll('[data-dashboard-parent="' + id + '"]').forEach(row => collapse(row.dataset.dashboardRow));
});
</script>
@endsection
