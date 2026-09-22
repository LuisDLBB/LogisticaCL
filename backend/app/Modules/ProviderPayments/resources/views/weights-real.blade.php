@extends('provider-payments::layout')
@section('title', 'Peso Real')
@push('styles')<style>.weight-tabs{display:flex;gap:10px;margin:0 0 22px}.weight-tabs a{padding:10px 15px;border:1px solid var(--line);border-radius:8px;background:#fff;text-decoration:none;font-weight:750}.weight-tabs a.active{background:var(--turquoise-dark);color:#fff}</style>@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a><p class="eyebrow">Mantenedor de pesos</p><h1>Peso Real</h1><p class="intro">Proceso independiente para la administración de Pesos Reales.</p>
<nav class="weight-tabs"><a href="{{ route('provider-payments.maintainers.pesos.transformados') }}">Peso Transformado</a><a class="active" href="{{ route('provider-payments.maintainers.pesos.reales') }}">Peso Real</a></nav>
<section class="card"><p class="note">Módulo de Peso Real en preparación.</p></section>
@endsection
