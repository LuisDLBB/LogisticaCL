@extends('portal.layout')
@section('title', $title)
@section('content')<section class="card pending"><span class="badge">Módulo pendiente de integración</span><h1>{{ $title }}</h1><p>Este espacio está reservado para el módulo de {{ $title }}.</p><a class="button" href="{{ route('portal.home') }}">Volver al inicio</a></section>@endsection