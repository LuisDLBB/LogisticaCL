@extends('provider-payments::layout')
@section('title', $title)
@push('styles')
<style>
.compile-actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap}.compile-period{display:flex;align-items:end;gap:10px;margin:22px 0}.compile-period label{display:grid;gap:6px;font-size:13px;font-weight:700}.compile-period select{padding:10px;min-width:160px;border:1px solid var(--line);border-radius:8px}.compile-list{max-width:720px}.compile-row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 0;border-bottom:1px solid var(--line)}.compile-row:last-child{border-bottom:0}.compile-row small{display:block;margin-top:3px;color:var(--muted)}.compile-row form{display:flex;align-items:end;gap:8px}.compile-row label{display:grid;gap:4px;font-size:12px;font-weight:700}.compile-row input{padding:8px;border:1px solid var(--line);border-radius:7px}.compile-delete{background:#a33030}.compile-delete:hover{background:#842525}.compile-status{padding:14px;margin:18px 0;background:#e8fbfa;border-left:4px solid var(--turquoise-dark)}
</style>
@endpush
@section('content')
<a class="back" href="{{ route('provider-payments.dashboard') }}">← Pago a Proveedores</a>
<p class="eyebrow">Gestión operacional</p><h1>{{ $title }}</h1>
<p class="intro">Prepara los registros de Courier para el pago a proveedores.</p>
@if(session('status'))<div class="compile-status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="warning">{{ $errors->first() }}</div>@endif
<div class="compile-actions"><a class="button" href="{{ route('provider-payments.courier-movements.compile.work', $period ? ['period' => $period] : []) }}">Trabajar Registros de Courier</a></div>
<h2>Procesos cargados</h2>
<p class="note">Elimina solo los registros trabajados de Pago_Movimientos_Courier. Los movimientos originales se conservan.</p>
@if($periods)
<form class="compile-period" method="get"><label>Período AAAAMM<select name="period" onchange="this.form.submit()">@foreach($periods as $option)<option value="{{ $option }}" @selected($option === $period)>{{ $option }}</option>@endforeach</select></label><button type="submit">Filtrar</button></form>
<div class="card compile-list">@foreach($loadedProcesses as $process)<div class="compile-row"><div><strong>{{ $period }}-{{ $process->nombre_proceso }}</strong><small>{{ number_format($process->total, 0, ',', '.') }} registros cargados</small></div><form method="post" action="{{ route('provider-payments.courier-movements.compile.destroy') }}" onsubmit="return confirm('¿Eliminar los {{ number_format($process->total, 0, ',', '.') }} registros trabajados de {{ $period }}-{{ $process->nombre_proceso }}? Los movimientos originales se conservarán.')">@csrf @method('DELETE')<input type="hidden" name="period" value="{{ $period }}"><input type="hidden" name="process" value="{{ $process->nombre_proceso }}"><label>Clave maestra <input type="password" name="password" required autocomplete="off"></label><button class="compile-delete" type="submit">Eliminar proceso</button></form></div>@endforeach</div>
@else<p class="note">Aún no hay procesos trabajados.</p>@endif
@endsection
