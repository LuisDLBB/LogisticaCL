@extends('operations::layout')
@section('title','Ajustar cliente')
@section('content')
<a class="back" href="{{ route('operations.lots.index').'#preparar-proceso' }}">← Volver a Procesos Trabajados</a>
<h1>Ajustar cliente · Recepción #{{ $reception->id }}</h1>
<p class="intro">{{ $reception->filename }}. Geolize determina el cliente por el código completo del paquete. El ajuste se ejecuta solo cuando lo guardas aquí.</p>

<section class="card">
    <form method="GET" action="{{ route('operations.receptions.clients',$reception->id) }}" class="ope-form">
        <label>Maestro Geolize para comparar
            <select name="master_load_id" required>
                <option value="">Seleccionar carga</option>
                @foreach($masters as $master)<option value="{{ $master->id }}" @selected($masterId === $master->id)>#{{ $master->id }} · {{ $master->filename }} · {{ $master->created_at }}</option>@endforeach
            </select>
        </label>
        <div><button>Revisar clientes</button></div>
    </form>
    @if($masters->isEmpty())<p class="warning">Primero carga el Maestro Geolize.</p>@endif
</section>

@if($preview)
    @php($labels = ['changed'=>'Por ajustar','unchanged'=>'Ya coinciden','missing'=>'Sin código en Geolize','ambiguous'=>'Cliente ambiguo','invalid'=>'Dato inválido en Geolize'])
    @php($unresolved = $preview['counts']['missing'] + $preview['counts']['ambiguous'] + $preview['counts']['invalid'])
    <section class="card" style="margin-top:18px">
        <h2>Resultado de la comparación</h2>
        <p class="note">La comparación usa el código completo exacto. Los datos originales del archivo o recepción quedan conservados.</p>
        <div class="ope-actions">
            @foreach($labels as $key=>$label)
                <a class="button {{ $status === $key ? '' : 'ope-secondary-button' }}" href="{{ route('operations.receptions.clients',['load'=>$reception->id,'master_load_id'=>$masterId,'status'=>$key]) }}">{{ $label }}: {{ number_format($preview['counts'][$key],0,',','.') }}</a>
            @endforeach
            <a class="button {{ $status === 'all' ? '' : 'ope-secondary-button' }}" href="{{ route('operations.receptions.clients',['load'=>$reception->id,'master_load_id'=>$masterId]) }}">Ver todos</a>
        </div>
        @if($unresolved)<p class="warning">{{ number_format($unresolved,0,',','.') }} códigos no tienen un cliente único y válido en este Maestro. No se modificarán.</p>@endif
        @if(\App\Modules\Operations\Services\OperationAccess::supervisor(request()) && $preview['counts']['changed'] > 0)
            <form method="POST" action="{{ route('operations.receptions.clients.apply',$reception->id) }}" class="ope-actions">@csrf
                <input type="hidden" name="master_load_id" value="{{ $masterId }}">
                <button>Guardar {{ number_format($preview['counts']['changed'],0,',','.') }} ajustes</button>
                <span class="note">Se actualizará el cliente de la recepción. Las guías ya aprobadas conservan sus datos.</span>
            </form>
        @endif
        <div class="table-wrap"><table class="ope-table"><thead><tr><th>Fila</th><th>Código de paquete</th><th>Cliente actual</th><th>Cliente Geolize</th><th>Resultado</th></tr></thead><tbody>
            @forelse($rows as $row)
                <tr><td>{{ $row['line'] }}</td><td>{{ $row['tracking'] ?: 'Sin código' }}</td><td>{{ $row['current'] ?: 'No informado' }}</td><td>{{ $row['geolize'] ?: '—' }}</td><td>{{ $labels[$row['status']] }}</td></tr>
            @empty
                <tr><td colspan="5" class="ope-empty">No hay filas con este resultado.</td></tr>
            @endforelse
        </tbody></table></div>
        @include('operations::pager',['rows'=>$rows])
    </section>
@endif
@endsection
