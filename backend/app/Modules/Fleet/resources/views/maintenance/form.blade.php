@extends('fleet::layout')
@section('title', 'Nueva mantención')
@push('styles')<style>.maintenance-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}.maintenance-form-grid label{margin:0}.maintenance-form-grid input,.maintenance-form-grid select,.maintenance-form-grid textarea{width:100%}.maintenance-form-grid .wide{grid-column:1/-1}.maintenance-form-grid [hidden]{display:none!important}.maintenance-form-grid label{font-size:12px}.maintenance-form-grid input,.maintenance-form-grid select{min-height:34px;padding:7px 9px}.maintenance-form-grid small{display:block;font-size:11px;color:#60757a}.maintenance-form-meta{display:flex;gap:12px;flex-wrap:wrap;align-items:center;font-size:12px;margin:5px 0 10px}.maintenance-form-meta strong{color:#087f83}textarea{font:inherit;border:1px solid #b7c9ca;border-radius:7px;padding:9px 11px}.maintenance-compact-workspace{max-width:740px;margin:0 auto}.maintenance-compact-workspace .card{padding:16px;margin-top:10px}.maintenance-compact-workspace .maintenance-form-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 14px}.maintenance-compact-workspace textarea{min-height:58px;height:64px}.maintenance-compact-workspace .muted{font-size:12px;margin:10px 0}.maintenance-compact-workspace .card>button{padding:9px 14px}.ret-embedded .maintenance-compact-workspace{margin:0 auto}.ret-embedded main:has(.maintenance-compact-workspace){padding:18px 20px 16px}.ret-embedded main:has(.maintenance-compact-workspace)>.test-mode-notice{display:none}.ret-embedded .maintenance-compact-workspace>a{display:none}.ret-embedded .maintenance-compact-workspace h1{font-size:20px;margin-top:0}@media(max-width:580px){.maintenance-compact-workspace .maintenance-form-grid{grid-template-columns:1fr}.maintenance-compact-workspace .card{padding:12px}}
</style>@endpush
@section('content')
<div class="maintenance-compact-workspace">
<a href="{{ route('fleet.vehicles.show', $vehicle) }}">← Ficha {{ $vehicle->plate }}</a>
<h1 style="font-size:22px;margin:8px 0 4px">Nueva mantención · {{ $vehicle->plate }}</h1><div class="maintenance-form-meta"><strong>Agendada al guardar</strong><span>Responsable: {{ auth()->user()->name }} · automático</span><span>Empresa: {{ $owner->code }}</span></div>
<form class="card" method="post" action="{{ route('fleet.maintenance.store') }}">@csrf<input type="hidden" name="vehicle_id" value="{{ $vehicle->id }}">
    @include('fleet::maintenance._planning_fields')
    <p class="muted">Para una ejecución externa, el proveedor será obligatorio al cerrar. @if($providers->isEmpty())Todavía no hay proveedores registrados para {{ $owner->code }}.@endif</p>
    <button type="submit">Guardar mantención</button>
</form>
</div>
<dialog id="maintenance-provider-dialog" style="width:95vw;height:90vh;border:1px solid #cbdfe0;border-radius:14px;padding:12px"><button type="button" id="maintenance-provider-close">Cerrar proveedores y actualizar lista</button><iframe id="maintenance-provider-frame" title="Crear proveedor" style="width:100%;height:calc(100% - 45px);border:0"></iframe></dialog>
<script>
const providerSelect=document.querySelector('[data-maintenance-provider]'), providerDialog=document.getElementById('maintenance-provider-dialog');
let previousProvider=providerSelect.value, existingProviders=new Set([...providerSelect.options].map(o=>o.value));
providerSelect.addEventListener('change',()=>{if(providerSelect.value!=='other'){previousProvider=providerSelect.value;return;}providerSelect.value=previousProvider;document.getElementById('maintenance-provider-frame').src=@json(route('provider-payments.maintainers.proveedores', ['flota' => '1']));providerDialog.showModal();});
document.getElementById('maintenance-provider-close').addEventListener('click',()=>providerDialog.close());
providerDialog.addEventListener('close',async()=>{try{const response=await fetch(location.href,{credentials:'same-origin'});if(!response.ok)throw new Error();const doc=new DOMParser().parseFromString(await response.text(),'text/html'), fresh=doc.querySelector('[data-maintenance-provider]');const added=[...fresh.options].find(o=>o.value&&o.value!=='other'&&!existingProviders.has(o.value));providerSelect.replaceChildren(...[...fresh.options].map(o=>o.cloneNode(true)));providerSelect.value=added?.value??previousProvider;previousProvider=providerSelect.value;existingProviders=new Set([...providerSelect.options].map(o=>o.value));}catch{alert('No se pudo actualizar la lista de proveedores. Tu mantención sigue sin guardar.');}});
</script>
@endsection
