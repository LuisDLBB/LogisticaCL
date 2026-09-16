@extends('provider-payments::layout')
@section('title', $title)
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a>
<p class="eyebrow">Gestión operacional</p><h1>{{ $title }}</h1><p class="intro">Esta pantalla quedará conectada al maestro o proceso correspondiente.</p>
<div class="card"><p class="note">Módulo en preparación.</p></div>
@endsection
