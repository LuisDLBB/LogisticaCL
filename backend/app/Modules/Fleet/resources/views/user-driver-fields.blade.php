@php
    $restoreDriverInput = old('driver_form_key') === $driverFormKey;
    $driverValue = fn ($field) => $restoreDriverInput ? old($field) : data_get($driverDetails, $field);
@endphp
<input type="hidden" name="driver_form_key" value="{{ $driverFormKey }}">
<div data-driver-details>
    <label>Tipo de conductor
        <select name="driver_type">
            <option value="" @selected(!$driverValue('driver_type'))>Sin definir</option>
            <option value="own" @selected($driverValue('driver_type') === 'own')>Interno</option>
            <option value="external" @selected($driverValue('driver_type') === 'external')>Externo</option>
        </select>
    </label>
    <p data-internal-vehicle hidden>El conductor interno trabaja con los vehículos de 4N; la patente se asigna al programar el retiro.</p>
    <div data-external-vehicle @if($driverValue('driver_type') !== 'external') hidden @endif>
        <p>Vehículo de trabajo del conductor externo · todos los campos son opcionales.</p>
        <label>Patente<input name="external_vehicle_plate" maxlength="20" value="{{ $driverValue('external_vehicle_plate') }}" placeholder="Ej.: ABCD12"></label>
        <label>Tipo de vehículo<input name="external_vehicle_type" maxlength="80" value="{{ $driverValue('external_vehicle_type') }}" placeholder="Ej.: Furgón, camioneta, camión"></label>
        <label>Carga útil (kg)<input type="number" name="external_payload_capacity_kg" min="0.01" step="0.01" value="{{ $driverValue('external_payload_capacity_kg') }}"></label>
        <label>Volumen disponible (m³)<input type="number" name="external_capacity_m3" min="0.001" step="0.001" value="{{ $driverValue('external_capacity_m3') }}"></label>
        <small>La carga útil es la capacidad de carga; no corresponde al peso bruto del vehículo.</small>
    </div>
</div>
