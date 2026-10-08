@extends('operations::layout')
@section('title','Recepción Sistema')
@section('content')
<h1>Recepción Sistema</h1>
<p class="intro">Registra la guía o factura, adjunta su respaldo y escanea los bultos. Al cerrar la recepción, aparecerá como fuente del proceso de Operaciones sin cargar un Excel.</p>
<section class="card">
    <h2>Nueva recepción</h2>
    <form method="POST" action="{{ route('operations.system-receptions.store') }}" class="ope-form">@csrf
        <label>Usuario<input value="{{ auth()->user()->name }}" readonly></label>
        <label>Cliente<select name="client_id" required><option value="">Seleccionar cliente</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(old('client_id') == $client->id)>{{ $client->commercial_name ?: $client->legal_name }} · {{ $client->tax_id }}</option>@endforeach</select></label>
        <label>Documento<select name="document_type" required><option value="guia" @selected(old('document_type','guia') === 'guia')>Guía</option><option value="factura" @selected(old('document_type') === 'factura')>Factura</option></select></label>
        <label>Número de guía o factura<input name="document_number" value="{{ old('document_number') }}" maxlength="100" required autocomplete="off"></label>
        <label class="ope-full">Observaciones adicionales<textarea name="observations" maxlength="2000" placeholder="Opcional">{{ old('observations') }}</textarea></label>
        <div class="ope-full"><button type="submit" @disabled($clients->isEmpty())>Guardar y adjuntar respaldo</button></div>
    </form>
    @if($clients->isEmpty())<p class="warning">No hay clientes activos para esta empresa.</p>@endif
</section>
<h2>Recepciones registradas</h2>
<div class="card table-wrap"><table class="ope-table"><thead><tr><th>N.º</th><th>Fecha</th><th>Usuario</th><th>Cliente</th><th>Documento</th><th>Estado</th><th></th></tr></thead><tbody>
@forelse($receptions as $record)<tr><td>#{{ $record->id }}</td><td>{{ $record->created_at }}</td><td>{{ $record->user_name }}</td><td>{{ $record->client_name }}</td><td>{{ $record->document_type === 'guia' ? 'Guía' : 'Factura' }} {{ $record->document_number }}</td><td>{{ ['awaiting_photo'=>'Falta respaldo','scanning'=>'Escaneando','completed'=>'Cerrada'][$record->status] ?? $record->status }}</td><td><a href="{{ route('operations.system-receptions.show',$record->id) }}">{{ $record->status === 'completed' ? 'Ver' : 'Continuar' }}</a></td></tr>
@empty<tr><td colspan="7" class="ope-empty">Todavía no hay recepciones registradas.</td></tr>@endforelse
</tbody></table></div>
@include('operations::pager',['rows'=>$receptions])
<p class="note">¿Buscas una carga anterior? <a href="{{ route('operations.loads.index','reception') }}">Ver recepciones cargadas desde Excel</a>.</p>
@endsection
