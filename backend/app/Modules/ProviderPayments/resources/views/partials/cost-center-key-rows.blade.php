<div class="key-table-wrap">
    <table class="key-table">
        <thead><tr><th>{{ $firstColumn }}</th>@if($firstColumn === 'Servicio')<th>Cliente</th>@else<th>Servicio</th>@endif<th>Agencia</th><th>Centro de costo</th><th>Condición</th><th>Estado</th><th>Llave configurada</th></tr></thead>
        <tbody>
        @foreach($rows as $key)
            <tr>
                @if($firstColumn === 'Proveedor')
                    <td>{{ $key->provider?->legal_name ?: ($key->agent_name ?: 'Proveedor sin nombre') }}<br><span class="count">{{ $key->provider?->tax_id ?: $key->provider_tax_id }}</span></td>
                    <td>{{ $key->serviceType?->name ?: $key->service_name }}</td>
                @else
                    <td>{{ $key->serviceType?->name ?: $key->service_name }}</td>
                    <td>{{ $key->client?->source_merchant_name ?: $key->merchant_name }}</td>
                @endif
                <td>{{ $key->agent_name ?: '—' }}</td>
                <td>{{ $key->costCenter?->dispatch_guide_detail ?: ($key->cost_center_code ?: 'Sin centro') }}</td>
                <td>{{ $key->payment_status }}</td>
                <td><span class="badge {{ $key->is_active ? '' : 'off' }}">{{ $key->is_active ? 'Activa' : 'Inactiva' }}</span></td>
                <td>
                    <details class="key-editor">
                        <summary>Ver / modificar</summary>
                        <form class="form-grid" method="post" action="{{ route('provider-payments.maintainers.llave-centro-costos.update', $key) }}">
                            @csrf @method('PUT')
                            <div class="protected wide"><strong>Llave protegida:</strong> {{ $key->provider_tax_id }} / {{ $key->client_tax_id }} / {{ $key->service_code }}</div>
                            <label>Agencia<input name="agent_name" value="{{ $key->agent_name }}"></label>
                            <label>Condición pago<input name="payment_status" value="{{ $key->payment_status }}" required></label>
                            <label class="wide">Centro de costo<select name="cost_center_code"><option value="">Sin centro</option>@foreach($costCenters as $center)<option value="{{ $center->cost_center_code }}" @selected((string)$center->cost_center_code === (string)$key->cost_center_code)>{{ $center->cost_center_code }} · {{ $center->dispatch_guide_detail }}</option>@endforeach</select></label>
                            <label>Estado<select name="is_active"><option value="1" @selected($key->is_active)>Activa</option><option value="0" @selected(!$key->is_active)>Inactiva</option></select></label>
                            <button class="wide">Guardar cambios</button>
                        </form>
                    </details>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
