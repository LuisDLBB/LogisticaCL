@forelse($documents as $document)
@php($expires = $document->expires_at ? \Carbon\Carbon::parse($document->expires_at, 'America/Santiago') : null)
@php($starts = $document->starts_at ? \Carbon\Carbon::parse($document->starts_at, 'America/Santiago') : null)
@php($state = $expires && $expires->lt($today) ? 'overdue' : ($starts && $starts->gt($today) ? 'unknown' : ($expires && $expires->lte($today->copy()->addDays(30)) ? 'warn' : '')))
<details class="vehicle-other-modules" style="border-bottom:1px solid #edf1f2;padding:9px 0" @if(! ($compact ?? false) && $document->kind === 'vehicle_policy') open @endif>
<summary>{{ $document->title }} @if($document->policy_number)· {{ $document->policy_number }}@endif @if($document->company_only)<small> · Empresa, sin patente individual</small>@endif</summary>
<dl class="vehicle-facts">
@if($document->insurer)<div><dt>Aseguradora</dt><dd>{{ $document->insurer }}</dd></div>@endif
@if($expires)<div><dt>Vigencia informada</dt><dd>{{ $starts?->format('d/m/Y') ?? 'Inicio no informado' }} → {{ $document->details['Vencimiento impreso'] ?? $expires->format('d/m/Y') }} <span class="vehicle-pill {{ $state }}">{{ $state === 'overdue' ? 'Vencido' : ($state === 'warn' ? 'Por vencer' : ($state === 'unknown' ? 'Próxima vigencia' : 'En plazo')) }}</span></dd></div>@endif
@foreach(array_merge($document->details, $document->vehicle_details) as $label => $value)
@if($label !== 'page' && is_string($value) && $value !== '')<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endif
@endforeach
</dl>
<p class="muted"><a href="{{ route('fleet.vehicles.documents.show', ['vehicle' => $vehicle, 'document' => $document->id]) }}@if(isset($document->vehicle_details['page']))#page={{ $document->vehicle_details['page'] }}@endif" target="_blank" rel="noopener">Abrir PDF ↗</a> · {{ $document->filename }}</p>
</details>
@empty<dl class="vehicle-facts"><div><dt>Número de póliza</dt><dd>Sin registro</dd></div><div><dt>Aseguradora</dt><dd>Sin registro</dd></div></dl><p class="muted">Sin PDF de póliza o permiso registrado para esta patente.</p>@endforelse
