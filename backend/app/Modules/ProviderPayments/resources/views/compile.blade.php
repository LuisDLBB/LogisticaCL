@extends('provider-payments::layout')
@section('title', $title)
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a>
<p class="eyebrow">Gestión operacional</p><h1>{{ $title }}</h1>
<p class="intro">Prepara los registros de Courier para el pago a proveedores.</p>
<a class="button" href="{{ route('provider-payments.courier-movements.compile.work') }}">Trabajar Registros de Courier</a>
@endsection
