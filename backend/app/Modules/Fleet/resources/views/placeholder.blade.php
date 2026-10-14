@extends('fleet::layout')
@section('title', $page['title'])
@section('content')
    <p class="muted">{{ $page['area'] }} / {{ $tenant->name }}</p>
    <h1>{{ $page['title'] }}</h1>
    <div class="card"><h2>Próximamente</h2><p>Este módulo funcional está en desarrollo. Todavía no hay operaciones ni datos simulados en esta pantalla.</p></div>
@endsection
