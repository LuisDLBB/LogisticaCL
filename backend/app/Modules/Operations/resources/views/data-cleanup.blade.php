@extends('operations::layout')
@section('title', 'Limpiar datos')
@section('content')
<style>
.cleanup-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin:20px 0}
.cleanup-card{padding:20px;border:1px solid var(--line);border-radius:12px;background:#fff}
.cleanup-card h2{margin:0 0 7px;font-size:19px}.cleanup-card p{margin:0 0 16px;color:var(--muted);font-size:13px}
.cleanup-form{display:grid;gap:9px;margin:14px 0}.cleanup-form label{display:grid;gap:5px;font-size:13px;font-weight:700}
.cleanup-form input,.cleanup-form select{width:100%;padding:8px;border:1px solid var(--line);border-radius:7px;background:#fff}
.cleanup-form button{justify-self:start}.cleanup-form button:disabled{cursor:not-allowed}
.cleanup-preview{padding:20px;border:1px solid #ddb86b;border-radius:12px;background:#fff9ed}
.cleanup-preview h2{margin:0 0 8px}.cleanup-counts{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:9px;margin:16px 0}
.cleanup-counts span{padding:10px;border:1px solid #efdfb8;border-radius:8px;background:#fff;font-size:13px}
.cleanup-counts strong{display:block;font-size:20px;color:#735016}.cleanup-confirm{display:flex;align-items:flex-start;gap:8px;margin:14px 0;font-size:13px;font-weight:700}
.cleanup-confirm input{margin-top:2px}.cleanup-delete{background:#a63725}.cleanup-delete:hover{background:#84291d}
</style>
<h1>Limpiar datos</h1>
<p class="intro">Selecciona qué datos quieres retirar. Primero verás un resumen de los procesos, bultos y salidas que dependen de esa selección. La configuración de agencias, coberturas, troncales y postas se conserva.</p>

<div class="cleanup-grid">
    <section class="cleanup-card">
        <h2>Recepción Sistema</h2>
        <p>{{ $systemCount }} recepciones registradas. Puedes limpiar las que pertenecen a un proceso o las creadas en un día.</p>
        <form class="cleanup-form" method="get" action="{{ route('operations.cleanup.index') }}">
            <input type="hidden" name="source" value="system"><input type="hidden" name="selector" value="process">
            <label>Por proceso
                <select name="value" required>
                    <option value="">Seleccionar proceso</option>
                    @foreach($processes as $process)
                        <option value="{{ $process->id }}" @selected(($plan['source'] ?? '') === 'system' && ($plan['selector'] ?? '') === 'process' && ($plan['value'] ?? '') === (string) $process->id)>#{{ $process->id }} · {{ $process->name }} · {{ $process->operation_date }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" @disabled($processes->isEmpty())>Revisar proceso</button>
        </form>
        <form class="cleanup-form" method="get" action="{{ route('operations.cleanup.index') }}">
            <input type="hidden" name="source" value="system"><input type="hidden" name="selector" value="date">
            <label>Por día de recepción
                <input type="date" name="value" value="{{ ($plan['source'] ?? '') === 'system' && ($plan['selector'] ?? '') === 'date' ? $plan['value'] : '' }}" required>
            </label>
            <button type="submit" @disabled($systemCount === 0)>Revisar día</button>
        </form>
    </section>
    <section class="cleanup-card">
        <h2>Recepciones importadas desde Excel</h2>
        <p>{{ $excelLoads->count() }} archivos de recepción. Elige uno o revisa todos.</p>
        <form class="cleanup-form" method="get" action="{{ route('operations.cleanup.index') }}">
            <input type="hidden" name="source" value="excel"><input type="hidden" name="selector" value="load">
            <label>Archivo
                <select name="value" required>
                    <option value="">Seleccionar archivo</option>
                    @foreach($excelLoads as $load)
                        <option value="{{ $load->id }}" @selected(($plan['source'] ?? '') === 'excel' && ($plan['selector'] ?? '') === 'load' && ($plan['value'] ?? '') === (string) $load->id)>#{{ $load->id }} · {{ $load->filename }} · {{ $load->row_count }} filas</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" @disabled($excelLoads->isEmpty())>Revisar archivo</button>
        </form>
        <form class="cleanup-form" method="get" action="{{ route('operations.cleanup.index') }}">
            <input type="hidden" name="source" value="excel"><input type="hidden" name="selector" value="all"><input type="hidden" name="value" value="all">
            <button type="submit" @disabled($excelLoads->isEmpty())>Revisar todos los Excel</button>
        </form>
    </section>
    <section class="cleanup-card">
        <h2>Maestro Geolize</h2>
        <p>{{ $masterLoads->count() }} archivos Maestro. Sus procesos relacionados también se retirarán; las recepciones se conservarán.</p>
        <form class="cleanup-form" method="get" action="{{ route('operations.cleanup.index') }}">
            <input type="hidden" name="source" value="master"><input type="hidden" name="selector" value="load">
            <label>Archivo
                <select name="value" required>
                    <option value="">Seleccionar archivo</option>
                    @foreach($masterLoads as $load)
                        <option value="{{ $load->id }}" @selected(($plan['source'] ?? '') === 'master' && ($plan['selector'] ?? '') === 'load' && ($plan['value'] ?? '') === (string) $load->id)>#{{ $load->id }} · {{ $load->filename }} · {{ $load->row_count }} filas</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" @disabled($masterLoads->isEmpty())>Revisar Maestro</button>
        </form>
        <form class="cleanup-form" method="get" action="{{ route('operations.cleanup.index') }}">
            <input type="hidden" name="source" value="master"><input type="hidden" name="selector" value="all"><input type="hidden" name="value" value="all">
            <button type="submit" @disabled($masterLoads->isEmpty())>Revisar todos los Maestro</button>
        </form>
    </section>
</div>

@if($plan)
<section class="cleanup-preview" aria-labelledby="cleanup-preview-title">
    <h2 id="cleanup-preview-title">Vista previa: {{ ['system' => 'Recepción Sistema', 'excel' => 'Recepción Excel', 'master' => 'Maestro Geolize'][$plan['source']] }}</h2>
    <p>Estos registros se retirarán juntos para que no queden datos incompletos. La eliminación de datos y archivos no se puede deshacer desde esta pantalla; guarda un respaldo antes de confirmar.</p>
    <div class="cleanup-counts">
        @foreach(['system' => 'Recepciones sistema', 'loads' => 'Archivos/cargas', 'rows' => 'Filas importadas', 'scans' => 'Escaneos', 'processes' => 'Procesos', 'packages' => 'Bultos', 'issues' => 'Incidencias', 'departures' => 'Salidas', 'guides' => 'Guías', 'reservations' => 'Reservas vinculadas'] as $key => $label)
            <span><strong>{{ number_format($plan['counts'][$key], 0, ',', '.') }}</strong>{{ $label }}</span>
        @endforeach
    </div>
    @if($plan['counts']['reservations'])
        <p class="warning">Estos procesos tienen reservas pendientes o incluidas. Se conservan para mantener la trazabilidad de la carga.</p>
    @elseif($plan['counts']['loads'] || $plan['counts']['system'])
        <form method="post" action="{{ route('operations.cleanup.destroy') }}">
            @csrf
            <input type="hidden" name="source" value="{{ $plan['source'] }}">
            <input type="hidden" name="selector" value="{{ $plan['selector'] }}">
            <input type="hidden" name="value" value="{{ $plan['value'] }}">
            <input type="hidden" name="fingerprint" value="{{ $plan['fingerprint'] }}">
            <label class="cleanup-confirm"><input type="checkbox" name="confirm" value="1" required> He revisado el resumen y entiendo que estos datos se retirarán.</label>
            <button class="cleanup-delete" type="submit">Limpiar selección</button>
        </form>
    @else
        <p>No hay registros para esta selección.</p>
    @endif
</section>
@endif
<script>
document.querySelector('.cleanup-preview form')?.addEventListener('submit', event => {
    const button = event.currentTarget.querySelector('button[type="submit"]');
    button.disabled = true;
    button.textContent = 'Limpiando datos…';
});
</script>
@endsection
