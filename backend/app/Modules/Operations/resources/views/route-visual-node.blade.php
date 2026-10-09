@php
    $nodeType = $nodeType ?? 'truck';
    $routeLabel = $routeLabel ?? $segment['role'].' · '.$segment['name'];
    $nodeLabel = $nodeLabel ?? $segment['destination_name'] ?? $segment['name'];
    $nodeHint = $nodeHint ?? $segment['name'];
    $agencyNames = $agencyNames ?? collect();
    $agencyId = $agencyId ?? null;
@endphp
<button class="route-visual-node route-visual-node-{{ $nodeType }}" type="button" data-route-node
    data-route-stop="{{ $nodeLabel }}"
    data-route-title="{{ $routeLabel }}" data-route-driver="{{ $segment['driver_name'] ?? 'No aplica' }}"
    data-route-rut="{{ $segment['driver_rut'] ?? 'No aplica' }}" data-route-plate="{{ $segment['plate'] ?? 'No aplica' }}"
    data-route-origin="{{ $segment['origin'] }}" data-route-destination="{{ $segment['destination'] }}"
    data-route-agencies="{{ $agencyNames->join(', ') }}" data-route-address="{{ $segment['destination_address'] ?? '' }}"
    data-route-agency-id="{{ $agencyId }}" data-route-segment="{{ $segment['key'] }}"
    data-route-group="{{ $segment['group_code'] ?? '' }}"
    data-route-inherited="{{ !empty($segment['inherited_transport']) ? '1' : '0' }}"
    data-route-air-trunk="{{ !empty($segment['air_trunk']) ? '1' : '0' }}"
    data-route-edit-url="{{ $agencyId ? route('operations.routes.data.update', $agencyId) : '' }}"
    aria-label="Ver datos de {{ $nodeLabel }}">
    <span class="route-visual-symbol" aria-hidden="true">
        @if($nodeType === 'plane')
            @include('operations::route-plane-icon')
        @else
            @include('operations::route-truck-icon')
        @endif
    </span>
    <strong>{{ $nodeLabel }}</strong>
    <small>{{ $nodeHint }}</small>
</button>
