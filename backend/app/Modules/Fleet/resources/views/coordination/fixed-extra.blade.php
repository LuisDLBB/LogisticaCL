<div class="grid">
<label>Categoría real (pendiente de validar catálogo)<input name="category" value="{{ $fixed?->category }}" maxlength="100"></label>
<label>Instrucciones<input name="instructions" value="{{ $fixed?->instructions }}" maxlength="2000"></label>
<label>Conductor habitual<select name="usual_driver_user_id"><option value="">Sin preferencia</option>@foreach($drivers as $driver)<option value="{{ $driver->id }}" @selected($fixed?->usual_driver_user_id === $driver->id)>{{ $driver->name }}</option>@endforeach</select></label>
<label>Vehículo habitual<select name="usual_vehicle_id"><option value="">Sin preferencia</option>@foreach($vehicles as $item)<option value="{{ $item['vehicle']->id }}" @selected($fixed?->usual_vehicle_id === $item['vehicle']->id)>{{ $item['vehicle']->plate }} · {{ $item['owner']->code }}</option>@endforeach</select></label>
</div>
