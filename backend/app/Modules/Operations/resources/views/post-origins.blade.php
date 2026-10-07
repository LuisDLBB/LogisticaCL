@extends('operations::layout')
@section('title','Orígenes de postas')
@section('content')
<h1>Orígenes de postas</h1>
<p class="intro">Registra el lugar desde donde sale cada posta. La dirección de destino sigue siendo la de la agencia; la patente y el chofer se mantienen en el catálogo de transporte.</p>
<p class="note">Las postas aéreas ya tienen su aeropuerto de origen. Para las demás, si no se registra uno, se utiliza el destino de la troncal. Los cambios se aplican a salidas pendientes y a guías nuevas; las guías aprobadas conservan sus datos.</p>
<div class="card table-wrap">
    <table class="ope-table">
        <thead><tr><th>ID</th><th>Posta</th><th>Estado</th><th>Dirección o lugar de origen</th><th>Comuna de origen</th><th>Acción</th></tr></thead>
        <tbody>
        @foreach($posts as $post)
            <tr id="posta-{{ $post->id }}" style="scroll-margin-top:100px">
                <td>{{ $post->post_code }}</td>
                <td>{{ $post->name }}</td>
                <td>{{ $post->is_active ? 'Activa' : 'Inactiva' }}</td>
                <td>{{ $post->origin_address ?: 'Destino de troncal (por defecto)' }}</td>
                <td>{{ $post->origin_commune ?: 'Según troncal' }}</td>
                <td>@if($supervisor)<details><summary>Editar origen</summary>
                    <form method="POST" action="{{ route('operations.post-origins.update', $post->id) }}" class="ope-form" style="min-width:360px;padding:12px 0">
                        @csrf @method('PUT')
                        <label class="ope-full">Dirección o lugar de origen<input name="origin_address" value="{{ $post->origin_address }}" required maxlength="255" placeholder="Ej.: Terminal o aeropuerto"></label>
                        <label class="ope-full">Comuna de origen<input name="origin_commune" value="{{ $post->origin_commune }}" required maxlength="150"></label>
                        <button class="ope-full">Guardar origen</button>
                    </form>
                </details>@else—@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
