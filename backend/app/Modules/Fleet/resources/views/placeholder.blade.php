@extends('fleet::layout')
@php($procedureCode = request()->query('procedimiento', ''))
@php($pageTitle = ($page['slug'] === 'administrador/procedimientos' && is_string($procedureCode) ? (['venta' => 'Venta de vehículo', 'robo' => 'Robo de vehículo'][$procedureCode] ?? null) : null) ?? $page['title'])
@section('title', $pageTitle)
@section('content')
    @unless(request()->routeIs('fleet.page.operations-fuel', 'fleet.page.operations-tag', 'fleet.page.operations-mail'))
    <p class="muted">{{ $page['area'] }} / {{ $tenant->name }}</p>
    <h1>{{ $pageTitle }}</h1>
    @endunless
    <div class="card"><h2>Próximamente</h2><p>Este módulo funcional está en desarrollo. Todavía no hay operaciones ni datos simulados en esta pantalla.</p></div>
@endsection
