<div class="maintenance-form-grid">
    <label>Tipo de mantención<select name="maintenance_type" required data-maintenance-type><option value="">Seleccionar tipo</option>@foreach($maintenanceTypes as $type)<option value="{{ $type }}" @selected(\Illuminate\Support\Str::upper(\Illuminate\Support\Str::ascii(old('maintenance_type', $maintenance?->maintenance_type) ?? '')) === \Illuminate\Support\Str::upper(\Illuminate\Support\Str::ascii($type)))>{{ $type }}</option>@endforeach<option value="__other__" @selected(old('maintenance_type') === '__other__' || old('new_maintenance_type'))>Otros · agregar tipo</option></select></label>
    <label data-new-maintenance-type hidden>Nuevo tipo<input name="new_maintenance_type" maxlength="100" minlength="2" value="{{ old('new_maintenance_type') }}" placeholder="Nombre del tipo de mantención" disabled><small>Se agregará a la lista al guardar la mantención.</small></label>
    <label>Tipo de ejecución<select name="execution_type" required><option value="internal" @selected(old('execution_type', $maintenance?->execution_type ?? 'internal') === 'internal')>Interna</option><option value="external" @selected(old('execution_type', $maintenance?->execution_type) === 'external')>Externa</option></select></label>
    @if(! $maintenance)<input type="hidden" name="status" value="scheduled"><input type="hidden" name="schedule_on_save" value="1">@else
    <label>Estado<select name="status"><option value="pending" @selected(old('status', $maintenance?->status ?? 'pending') === 'pending')>Pendiente</option><option value="scheduled" @selected(old('status', $maintenance?->status) === 'scheduled')>Agendada</option>@if($maintenance?->status === 'scheduled' || $maintenance?->status === 'in_progress')<option value="in_progress" @selected(old('status', $maintenance?->status) === 'in_progress')>En mantención</option>@endif@if($maintenance)<option value="cancelled">Cancelada</option>@endif</select></label>
    @endif
    <label>Fecha prevista<input type="date" name="scheduled_at" @required(! $maintenance) value="{{ old('scheduled_at', $maintenance?->scheduled_at?->format('Y-m-d')) }}"></label>
    <label>Kilometraje informado<input type="number" name="reported_odometer_km" min="0" value="{{ old('reported_odometer_km', $maintenance?->reported_odometer_km ?? ($vehicle ?? $maintenance?->vehicle)?->odometer_km) }}"></label>
    <label>Proveedor previsto<select name="provider_id" data-maintenance-provider><option value="">Sin asignar / ejecución interna</option>@foreach($providers as $provider)<option value="{{ $provider->id }}" @selected((string) old('provider_id', $maintenance?->provider_id) === (string) $provider->id)>{{ $provider->operational_name ?: $provider->legal_name }}</option>@endforeach<option value="other">Otros / Agregar proveedor</option></select></label>
    <label>Costo estimado ($)<input type="number" name="estimated_cost" min="0" step="0.01" value="{{ old('estimated_cost', $maintenance?->estimated_cost) }}"></label>
    <label>Número de presupuesto / cotización<input name="quotation_number" maxlength="100" value="{{ old('quotation_number', $maintenance?->quotation_number) }}" placeholder="Opcional · código del presupuesto"></label>
    <label class="wide">Observaciones<textarea id="maintenance-notes" name="notes" rows="3">{{ old('notes', $maintenance?->notes) }}</textarea></label>
    @if($maintenance)<label class="wide">Motivo de cambio/cancelación<input type="text" name="change_reason" maxlength="2000" value="{{ old('change_reason') }}" placeholder="Obligatorio si cancelas"></label>@endif
</div>

@once
<script>
document.querySelectorAll('[data-maintenance-type]').forEach(select=>{const label=select.closest('form').querySelector('[data-new-maintenance-type]'),input=label.querySelector('input');const toggle=()=>{const other=select.value==='__other__';label.hidden=!other;input.disabled=!other;input.required=other;};select.addEventListener('change',()=>{toggle();if(!label.hidden)input.focus();});toggle();});
</script>
@endonce
