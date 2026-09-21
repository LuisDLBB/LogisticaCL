@extends('provider-payments::layout')
@section('title', 'Carga completada')
@push('styles')<style>.result-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:22px 0}.metric{padding:18px;border:1px solid var(--line);border-radius:10px}.metric strong{display:block;font-size:30px;color:var(--turquoise-dark)}@media(max-width:800px){.result-grid{grid-template-columns:1fr 1fr}}</style>@endpush
@section('content')
<p class="eyebrow">Carga de movimientos</p><h1>Carga completada</h1><p class="intro">El proceso <strong>{{ $processName }}</strong> del archivo {{ $fileName }} fue cargado en movimientos_courier.</p>
<div class="card"><div class="result-grid"><div class="metric"><strong>{{ number_format($result['created'],0,',','.') }}</strong>Nuevos</div><div class="metric"><strong>{{ number_format($result['replaced'],0,',','.') }}</strong>Reemplazados</div><div class="metric"><strong>{{ number_format($result['duplicates'] - $result['replaced'],0,',','.') }}</strong>Duplicados omitidos</div><div class="metric"><strong>{{ number_format($result['excluded'] + $result['invalid'],0,',','.') }}</strong>No cargados</div></div><a class="button" href="{{ route('provider-payments.dashboard') }}">Volver a Pago Proveedores</a></div>
@endsection
