@extends('fleet::layout')
@section('title','RET Limpiar pruebas')
@section('content')
<h1>Eliminar datos de prueba</h1><p>Se eliminarán únicamente los registros marcados explícitamente como prueba de la empresa activa. Se conservan maestros, reglas reales y registros operativos reales.</p>
<div class="card"><table><thead><tr><th>Módulo</th><th>Registros a eliminar</th></tr></thead><tbody>@foreach($counts as $label=>$count)<tr><td>{{ $label }}</td><td>{{ $count }}</td></tr>@endforeach</tbody></table></div>
<form method="post" action="{{ route('fleet.ret.cleanup.destroy') }}" class="card">@csrf<label for="confirmation">Escribe exactamente LIMPIAR DATOS DE PRUEBA</label><input id="confirmation" name="confirmation" autocomplete="off" required><p><button>Limpiar pruebas</button></p></form>
@endsection
