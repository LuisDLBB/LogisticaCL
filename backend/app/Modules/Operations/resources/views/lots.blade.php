@extends('operations::layout')
@section('title','Procesos Trabajados')
@section('content')
<h1>Procesos Trabajados</h1>
<p class="intro">Asocia las recepciones y el Maestro Geolize que se considerarán en la operación del día para sacar a reparto.</p>

<section class="card" id="preparar-proceso">
    <h2>Preparar proceso</h2>
    <form method="POST" action="{{ route('operations.lots.store') }}" class="ope-form">@csrf
        <label>Nombre del proceso<input name="name" value="{{ old('name','Primera milla') }}" maxlength="160" required></label>
        <label>Fecha del proceso<input type="date" name="operation_date" value="{{ old('operation_date',now()->timezone('America/Santiago')->format('Y-m-d')) }}" required></label>
        <label>Maestro Geolize<select name="master_load_id" required><option value="">Seleccionar carga</option>@foreach($loads->where('source_type','master') as $load)<option value="{{ $load->id }}" @selected(old('master_load_id',$selectedMasterLoadId)==$load->id)>#{{ $load->id }} · {{ $load->filename }} · {{ $load->created_at }}</option>@endforeach</select></label>

        <fieldset class="ope-full">
            <legend>Recepciones cerradas para incluir en el proceso</legend>
            @forelse($loads->where('source_type','reception') as $load)
                @php($profile = json_decode($load->mapping, true)['profile'] ?? '')
                <div class="ope-reception-source">
                    <label class="check"><input type="checkbox" name="reception_load_ids[]" value="{{ $load->id }}" @checked(in_array($load->id,old('reception_load_ids',$selectedReceptionLoadIds)))>
                        <strong>#{{ $load->id }} · {{ $profile === 'system' ? 'Recepción Sistema' : 'Archivo cargado' }}: {{ $load->filename }}</strong>
                    </label>
                    <p class="note">{{ \Carbon\Carbon::parse($load->created_at)->timezone('America/Santiago')->format('d-m-Y H:i') }} · {{ number_format($load->row_count, 0, ',', '.') }} {{ $load->row_count === 1 ? 'bulto' : 'bultos' }}</p>
                    <div class="table-wrap"><table class="ope-table"><thead><tr><th>Cliente</th><th>Operario</th><th>Bultos</th></tr></thead><tbody>
                        @forelse($receptionSummaries[$load->id] ?? [] as $summary)
                            <tr><td>{{ $summary['client'] }}</td><td>{{ $summary['operator'] }}</td><td>{{ number_format($summary['count'], 0, ',', '.') }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="ope-empty">No hay bultos para totalizar.</td></tr>
                        @endforelse
                    </tbody><tfoot><tr><td colspan="2"><strong>Total de la recepción</strong></td><td><strong>{{ number_format($load->row_count, 0, ',', '.') }}</strong></td></tr></tfoot></table></div>
                </div>
            @empty
                <p class="note">Cierra primero una Recepción Sistema o carga un Excel de Recepción.</p>
            @endforelse
        </fieldset>

        @if($loads->where('source_type','reception')->isEmpty())
            <div class="ope-full warning" role="status">Falta cargar Recepción de bultos para preparar el proceso. <a href="{{ route('operations.system-receptions.index') }}">Abrir Recepción Sistema</a></div>
        @endif
        @if($loads->where('source_type','master')->isEmpty())
            <div class="ope-full warning" role="status">Falta cargar el Maestro Geolize. <a href="{{ route('operations.loads.index','master') }}">Cargar Maestro Geolize</a></div>
        @endif
        <div class="ope-full"><button @disabled($loads->where('source_type','master')->isEmpty() || $loads->where('source_type','reception')->isEmpty())>Preparar y cruzar datos</button><p class="note">Se incluyen únicamente los códigos presentes en Recepción. Se usa su peso; si falta, se toma la parte entera del peso de Geolize.</p></div>
    </form>
</section>

<h2>Procesos preparados</h2>
<div class="card table-wrap"><table class="ope-table"><thead><tr><th>Proceso</th><th>Fecha</th><th>Recepciones asociadas</th><th>Bultos para reparto</th><th>Acciones</th></tr></thead><tbody>
    @forelse($lots as $lot)
        <tr><td>#{{ $lot->id }} · {{ $lot->name }}</td><td>{{ $lot->operation_date }}</td><td>
            @foreach($lotSources->get($lot->id, collect()) as $source)
                <span class="ope-source-name">{{ (json_decode($source->mapping, true)['profile'] ?? '') === 'system' ? 'Sistema' : 'Excel' }}: {{ $source->filename }}</span>
            @endforeach
        </td><td>{{ number_format($lotPackageCounts->get($lot->id, 0), 0, ',', '.') }}</td><td><a href="{{ route('operations.lots.show',$lot->id) }}">Bultos e incidencias</a> · <a href="{{ route('operations.departures.index',$lot->id) }}">Salidas</a></td></tr>
    @empty
        <tr><td colspan="5" class="ope-empty">Todavía no hay procesos preparados.</td></tr>
    @endforelse
</tbody></table></div>
@include('operations::pager',['rows'=>$lots])
@endsection
