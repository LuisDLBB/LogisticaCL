@extends('fleet::layout')
@section('title', 'Ingresar KM')
@push('styles')
<style>
.km-capture-head{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;padding-right:40px;margin-bottom:12px}.km-capture-head h1{font-size:22px;margin:0}.km-capture-head form{display:flex;align-items:end;gap:8px}.km-capture-head label{font-size:11px}.km-capture-head input{display:block;padding:6px 9px;margin-top:3px;font-size:12px}.km-capture-table{width:100%;border-collapse:collapse;font-size:12px}.km-capture-table th,.km-capture-table td{padding:8px;border-bottom:1px solid #e0e8e9;text-align:left}.km-capture-table th{background:#eff5f5;position:sticky;top:0}.km-capture-table input[type=number]{width:145px;max-width:100%;min-height:32px;padding:6px 8px;font-size:13px}.km-capture-table small{display:block;font-size:10px;color:#657b81;margin-top:3px}.km-capture-state{display:inline-block;padding:4px 6px;border-radius:5px;background:#e7f7ef;color:#08764f;font-size:10px}.km-capture-state.red{background:#ffedf0;color:#b62541}.km-capture-state.yellow{background:#fff3d3;color:#88610b}.km-capture-import{margin:14px 0;padding:12px;border:1px solid #dce7e9;border-radius:10px;background:#f7fafb}.km-capture-import summary{font-weight:700;font-size:13px;cursor:pointer}.km-capture-import textarea{width:100%;margin-top:5px;min-height:90px;padding:9px;font:inherit;font-size:12px;border:1px solid #bed2d3;border-radius:8px}.km-capture-import label{display:block;font-size:12px;margin:10px 0}.km-capture-import input[type=file]{display:block;margin-top:5px}.km-capture-help{font-size:12px;color:#5d737a;margin:8px 0 12px}.km-capture-save{position:sticky;bottom:0;background:white;padding:12px 0;border-top:1px solid #dce7e9;display:flex;gap:12px;align-items:center;flex-wrap:wrap}.km-capture-wrap{overflow:auto}
.km-capture-controls{display:flex;flex-direction:column;align-items:flex-end;gap:8px}.km-capture-controls>button{align-self:flex-end}.km-capture-table .km-before-workshop{color:#a23148;font-size:10px}
.km-weekly-bars{display:flex;gap:5px;width:240px}.km-weekly-bar{flex:1;text-align:center;font-size:9px;color:#657b81}.km-weekly-track{height:48px;display:flex;align-items:flex-end;justify-content:center;border-bottom:1px solid #ccdfe1;margin:3px 0}.km-weekly-fill{width:19px;border-radius:3px 3px 0 0;background:#00999f;min-height:2px}.km-weekly-missing{color:#94a5aa;font-size:14px}.km-weekly-bar:focus{outline:2px solid #00999f;outline-offset:2px;border-radius:3px}.km-weekly-value{font-size:10px;white-space:nowrap;color:#365a61}
.km-capture-history{margin-top:7px;font-size:11px}.km-capture-history summary{cursor:pointer;color:#087f83;font-weight:650}.km-capture-history>div{max-height:240px;overflow:auto;min-width:260px;margin-top:6px;border:1px solid #dce7e9;border-radius:7px}.km-capture-history table{width:100%;font-size:10px}.km-capture-history th,.km-capture-history td{padding:5px}.km-capture-history small{font-size:9px}.km-capture-history .km-review-note{color:#88610b}
.km-weekly-fill.review{background:#947116}.km-weekly-summary{font-size:10px;color:#45656b;margin-top:5px}.km-weekly-review{color:#88610b;font-size:10px;margin-top:3px}
</style>
@endpush
@section('content')
<div class="km-capture-head"><h1>Ingresar KM · {{ $rows->count() }} patentes</h1><div class="km-capture-controls">@if($rows->contains('can_edit', true))<button type="submit" form="km-capture-readings">Guardar kilometrajes</button>@endif<form method="get"><input type="hidden" name="captura" value="all"><label>Semana del lunes<input type="date" name="semana" value="{{ $week->toDateString() }}" max="{{ today('America/Santiago')->toDateString() }}"></label><button>Ver semana</button></form></div></div>
<p class="km-capture-help">Ingresa los kilometrajes juntos o copia y pega desde Excel. Los vehículos en taller traen su último KM válido. Las barras muestran ocho semanas de recorrido, con una escala por patente para apreciar sus cambios; — indica que no se puede calcular el recorrido; abre «Ver lecturas» para consultar las fechas y los KM informados, incluidos ceros y lecturas por revisar.</p>
@if($rows->contains('can_edit', true))
<details class="km-capture-import" @if(old('pegado') || $errors->has('archivo') || $errors->has('pegado')) open @endif><summary>Pegar desde Excel o subir archivo</summary>
<form method="post" action="{{ route('fleet.kilometers.preview') }}" enctype="multipart/form-data">@csrf<input type="hidden" name="return_to" value="km_capture"><input type="hidden" name="semana" value="{{ $week->toDateString() }}">
<label>Copia las columnas Patente y KM, incluidos sus encabezados<textarea name="pegado" placeholder="Patente&#9;KM&#10;KLLX31&#9;380520&#10;SGSS86&#9;203097">{{ old('pegado') }}</textarea></label>
<label>O selecciona un archivo Excel / CSV<input type="file" name="archivo" accept=".xlsx,.csv,.txt"></label><button>Revisar carga</button><p class="km-capture-help">La vista previa completa las casillas. Revisa y pulsa Guardar kilometrajes para confirmar.</p>
</form></details>
@endif
<form id="km-capture-readings" method="post" action="{{ route('fleet.kilometers.store') }}">@csrf<input type="hidden" name="return_to" value="km_capture"><input type="hidden" name="semana" value="{{ $week->toDateString() }}">
<div class="km-capture-wrap"><table class="km-capture-table"><thead><tr><th>Patente</th><th>Vehículo</th><th>Estado</th><th>Último KM informado</th><th>Recorrido · 8 semanas</th><th>Ingresar KM</th></tr></thead><tbody>
@forelse($rows as $row)
@php($vehicle = $row['vehicle'])
<tr><td><strong>{{ $vehicle->plate }}</strong><small>{{ $row['owner']->code }}</small></td><td>{{ $vehicle->brand }} {{ $vehicle->model }}</td><td><span class="km-capture-state {{ $row['level'] }}">{{ $row['in_workshop'] ? 'En taller · '.str_replace('No operativo: ', '', $row['label']) : $row['label'] }}</span></td><td>{{ $row['current'] !== null ? number_format($row['current'], 0, ',', '.') : 'Sin lectura' }}@if($row['in_workshop'])<small class="km-before-workshop">Último KM válido antes del taller</small>@endif<small>{{ $row['reading'] ? \Carbon\Carbon::parse($row['reading']->week_start)->format('d/m/Y') : '' }}</small></td><td>
<div class="km-weekly-bars" role="group" aria-label="Recorrido semanal de {{ $vehicle->plate }}">
@foreach($weeklyBars->get($vehicle->id, collect()) as $bar)
<div class="km-weekly-bar" tabindex="0" title="{{ $bar['label'] }}: {{ $bar['status'] }}" aria-label="Semana {{ $bar['label'] }}: {{ $bar['status'] }}">
<div class="km-weekly-value">{{ $bar['km'] !== null ? number_format($bar['km'], 0, ',', '.') : '—' }}</div>
<div class="km-weekly-track">@if($bar['km'] !== null)<span class="km-weekly-fill {{ $bar['review'] ? 'review' : '' }}" style="height:{{ max(2, (int) round($bar['km'] / $barMaximums->get($vehicle->id, 1) * 48)) }}px"></span>@else<span class="km-weekly-missing">—</span>@endif</div>
<span>{{ $bar['label'] }}</span>
</div>
@endforeach
</div>
@php($measuredBars = $weeklyBars->get($vehicle->id, collect())->filter(fn ($bar) => $bar['km'] !== null))
@if($measuredBars->isNotEmpty())
<div class="km-weekly-summary">Promedio: <b>{{ number_format($measuredBars->avg('km'), 0, ',', '.') }} km/semana</b> · {{ $measuredBars->count() }} semanas calculables</div>
@endif
@if($measuredBars->contains('review', true))
<div class="km-weekly-review">Barra ocre: lectura con observación; revisa el detalle.</div>
@endif
<details class="km-capture-history" data-history-plate="{{ $vehicle->plate }}"><summary>Ver lecturas · 12 semanas</summary><div><table aria-label="Lecturas informadas de {{ $vehicle->plate }}"><thead><tr><th>Fecha informada</th><th>KM tomado</th><th>Revisión</th></tr></thead><tbody>
@forelse($history->get($vehicle->id, collect())->unique('week_start')->sortByDesc('week_start') as $item)
@php($takenDate = preg_match('/Fecha de planilla: (\d{2}\/\d{2}\/\d{4})/', $item->notes ?? '', $dateParts) ? $dateParts[1] : \Carbon\Carbon::parse($item->week_start)->format('d/m/Y'))
<tr><td>{{ $takenDate }}@if(\Carbon\Carbon::parse($item->week_start)->format('d/m/Y') !== $takenDate)<small>Semana {{ \Carbon\Carbon::parse($item->week_start)->format('d/m/Y') }}</small>@endif</td><td>{{ number_format($item->odometer_km, 0, ',', '.') }}</td><td>
@if($item->accepted)
Válida
@else
<span class="km-review-note">{{ $item->odometer_km === 0 ? 'Cero informado · sin recorrido calculable' : implode(' · ', json_decode($item->issues ?? '[]', true)) }}</span>
@endif
</td></tr>
@empty<tr><td colspan="3">Sin lecturas en este período.</td></tr>@endforelse
</tbody></table></div></details>
</td><td>
@if($row['can_edit'])<input type="hidden" name="readings[{{ $vehicle->id }}][vehicle_id]" value="{{ $vehicle->id }}"><input type="hidden" name="readings[{{ $vehicle->id }}][notes]" value="{{ old('readings.'.$vehicle->id.'.notes', $row['reading']?->notes) }}"><input type="number" min="0" max="2000000000" name="readings[{{ $vehicle->id }}][odometer_km]" aria-label="Ingresar KM {{ $vehicle->plate }}" value="{{ old('readings.'.$vehicle->id.'.odometer_km', $row['in_workshop'] ? $row['current'] : null) }}" placeholder="Nuevo kilometraje">@else<span>Solo consulta</span>@endif
</td></tr>
@empty<tr><td colspan="6">No hay vehículos vigentes para registrar kilometraje.</td></tr>@endforelse
</tbody></table></div>
@if($rows->contains('can_edit', true))<div class="km-capture-save"><span class="km-capture-help">Las alertas se recalculan al guardar. Cierra esta ventana para volver al tablero.</span></div>@endif
</form>
@endsection
