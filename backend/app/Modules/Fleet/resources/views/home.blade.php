@extends('fleet::layout')
@section('title', 'Inicio')
@section('content')
    <h1>Control de Flota</h1>
    <p class="muted">Empresa activa: {{ $tenant->name }}. Selecciona un área para continuar.</p>
    <div class="grid">
        @forelse($visiblePages as $code => $page)
            <div class="card"><span class="tag">{{ $page['area'] }}</span><h2>{{ $page['title'] }}</h2><p class="muted">Módulo en desarrollo.</p><a class="button" href="{{ route('fleet.page.'.str_replace('.', '-', $code)) }}">Ver pantalla</a></div>
        @empty
            <div class="card"><p>Tu perfil todavía no tiene pantallas asignadas en esta empresa.</p></div>
        @endforelse
    </div>
@endsection
