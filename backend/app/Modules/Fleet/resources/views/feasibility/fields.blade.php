<div class="grid">
@foreach($fields as $key => $label)
    @php($type = in_array($key,['packages','weight_kg','km','hours','own_drivers','external_drivers','own_helpers','external_helpers','estimated_cost']) || isset(\App\Http\Controllers\Fleet\RetFeasibilityController::COSTS[$key]) && ($costFields ?? false) ? 'number' : ($key === 'service_date' ? 'date' : (in_array($key,['window_start','window_end']) ? 'time' : 'text')))
    <div><label for="field-{{ $key }}">{{ $label }}</label><input id="field-{{ $key }}" name="{{ $key }}" type="{{ $type }}" @if($type === 'number') min="0" step="any" @endif value="{{ old($key, $values[$key] ?? '') }}" @if(($costFields ?? false) || $key === 'material') required @endif></div>
@endforeach
</div>
