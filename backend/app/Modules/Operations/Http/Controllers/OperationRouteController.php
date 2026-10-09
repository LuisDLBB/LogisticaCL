<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\OperationAccess;
use App\Modules\Operations\Services\OperationGuideRoutePlanner;
use App\Modules\Operations\Services\OperationRouteEstimator;
use App\Modules\Operations\Services\OperationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class OperationRouteController extends Controller
{
    /** @var array<int, array{trunks: Collection, postsById: Collection, postsByCode: Collection, agencies: Collection, drivers: Collection}> */
    private array $routeCatalogs = [];

    public function index(Request $request, OperationRouteEstimator $estimator): View
    {
        $tenant = OperationAccess::tenant($request);
        $trunks = DB::table('Ope_Troncales')->where('tenant_id', $tenant)->orderBy('trunk_code')->get();
        $agencies = DB::table('Ope_Agencias as agency')
            ->join('Ope_Troncales as trunk', 'trunk.id', '=', 'agency.trunk_id')
            ->join('Ope_Postas as post', 'post.id', '=', 'agency.post_id')
            ->leftJoin('Ope_Postas as second_post', 'second_post.id', '=', 'agency.second_post_id')
            ->leftJoin('Ope_Choferes as trunk_driver', 'trunk_driver.id', '=', 'trunk.driver_id')
            ->leftJoin('Ope_Choferes as post_driver', 'post_driver.id', '=', 'post.driver_id')
            ->leftJoin('Ope_Choferes as second_driver', 'second_driver.id', '=', 'second_post.driver_id')
            ->where('agency.tenant_id', $tenant)
            ->orderBy('agency.agency_code')
            ->select('agency.*', 'trunk.trunk_code', 'trunk.name as trunk_name', 'trunk.origin_address as trunk_origin_address',
                'trunk.origin_commune as trunk_origin_commune', 'trunk.destination_address as trunk_destination_address',
                'trunk.destination_commune as trunk_destination_commune', 'trunk.plate as trunk_plate',
                'trunk_driver.rut as trunk_driver_rut', 'trunk_driver.name as trunk_driver_name',
                'post.name as post_name', 'post.plate as post_plate', 'post.origin_address as post_origin_address',
                'post.origin_commune as post_origin_commune', 'post_driver.rut as post_driver_rut', 'post_driver.name as post_driver_name',
                'second_post.name as second_post_name', 'second_post.plate as second_post_plate',
                'second_post.origin_address as second_post_origin_address', 'second_post.origin_commune as second_post_origin_commune',
                'second_driver.rut as second_post_driver_rut', 'second_driver.name as second_post_driver_name')
            ->get();
        $saved = DB::table('Ope_RouteEstimates')->where('tenant_id', $tenant)->get()->keyBy(fn (object $row): string => $row->agency_id.'|'.$row->segment);
        $routes = $agencies->map(function (object $agency) use ($saved): array {
            $segments = $this->segments($agency);
            foreach ($segments as &$segment) {
                $metric = $saved->get($agency->id.'|'.$segment['key']);
                $segment['estimate'] = $metric && $metric->route_hash === $segment['hash'] ? $metric : null;
            }

            return ['agency' => $agency, 'segments' => $segments];
        })->groupBy(fn (array $route): int => (int) $route['agency']->trunk_id);
        $airTrunks = $trunks->filter(fn (object $trunk): bool => in_array((int) $trunk->trunk_code, [1, 2, 3], true));
        $airRoutes = $airTrunks->flatMap(fn (object $trunk) => $routes->get($trunk->id, collect()))
            ->sortBy(fn (array $route): int => (int) $route['agency']->agency_code)->values();
        $sharedAirRoute = $airRoutes->first();
        $sharedAirSegment = $sharedAirRoute['segments']['troncal'] ?? null;
        if ($sharedAirSegment) {
            $airAgencyIds = $airRoutes->map(fn (array $route): int => (int) $route['agency']->id);
            $sharedAirSegment['estimate'] = $saved->filter(fn (object $estimate): bool => $estimate->segment === 'troncal'
                && $estimate->route_hash === $sharedAirSegment['hash'] && $airAgencyIds->contains((int) $estimate->agency_id))
                ->sort(fn (object $first, object $second): int => strcmp($second->updated_at, $first->updated_at)
                    ?: $second->id <=> $first->id)->first();
        }
        $groundTrunks = $trunks->reject(fn (object $trunk): bool => in_array((int) $trunk->trunk_code, [1, 2, 3], true));
        $groundVisuals = $groundTrunks->mapWithKeys(fn (object $trunk): array => [
            $trunk->id => $this->visualLegs($routes->get($trunk->id, collect()), (int) $trunk->trunk_code),
        ]);
        $groundDistances = [];
        $groundDistanceValues = [];
        $groundPairs = [];
        foreach ($groundVisuals as $trunkId => $visualLegs) {
            $groundPairs[$trunkId] = $this->adjacentStops($visualLegs);
            foreach ($groundPairs[$trunkId] as $pair) {
                $metric = $saved->get($pair['agency_id'].'|'.$pair['key']);
                if ($metric && $metric->route_hash === $pair['hash']) {
                    $groundDistances[$trunkId][$pair['key']] = number_format((float) $metric->distance_km, 1, ',', '.').' km';
                    $groundDistanceValues[$trunkId][$pair['key']] = $metric->distance_km;
                }
            }
        }
        $airBranches = collect([
            ['name' => 'Aéreo Norte', 'trunk_code' => 1, 'order' => [1, 3, 4, 2]],
            ['name' => 'Aéreo Pacífico', 'trunk_code' => 2, 'order' => [5]],
            ['name' => 'Aéreo Sur', 'trunk_code' => 3, 'order' => [6, 7]],
        ])->map(function (array $branch) use ($airRoutes): array {
            $branch['routes'] = $airRoutes->filter(fn (array $route): bool => (int) $route['agency']->trunk_code === $branch['trunk_code'])
                ->sortBy(fn (array $route): int => array_search((int) $route['agency']->agency_code, $branch['order'], true))
                ->values();

            return $branch;
        });

        return view('operations::routes', [
            'groundTrunks' => $groundTrunks,
            'groundVisuals' => $groundVisuals,
            'groundDistances' => $groundDistances,
            'groundDistanceValues' => $groundDistanceValues,
            'groundPairs' => $groundPairs,
            'airBranches' => $airBranches,
            'routes' => $routes,
            'airRoutes' => $airRoutes,
            'sharedAirRoute' => $sharedAirRoute,
            'sharedAirSegment' => $sharedAirSegment,
            'canEdit' => OperationAccess::supervisor($request),
            'draftDepartures' => DB::table('Ope_ProgramacionSalidas as departure')
                ->join('Ope_Lotes as lot', 'lot.id', '=', 'departure.lot_id')
                ->join('Ope_SalidaAgencias as assigned', 'assigned.departure_id', '=', 'departure.id')
                ->join('Ope_GuiaConfiguraciones as configuration', 'configuration.id', '=', 'assigned.configuration_id')
                ->join('Ope_Ubicaciones as destination', 'destination.id', '=', 'departure.destination_id')
                ->join('PPR_coverages as coverage', 'coverage.id', '=', 'configuration.coverage_id')
                ->join('Ope_Agencias as agency', function ($join): void {
                    $join->on('agency.agency_code', '=', 'coverage.ID_ComunaMatrizAgencia')
                        ->on('agency.tenant_id', '=', 'coverage.tenant_id');
                })
                ->where('lot.tenant_id', $tenant)->where('departure.status', 'draft')
                ->distinct()->orderBy('departure.id')
                ->get(['departure.id', 'departure.name', 'departure.departure_date', 'departure.role',
                    'departure.plate', 'departure.driver_name', 'departure.driver_rut', 'destination.address as destination_address',
                    'configuration.group_code', 'agency.id as agency_id']),
            'mapsConfigured' => $estimator->configured(),
        ]);
    }

    public function updateData(Request $request, int $agency, OperationWorkflow $workflow): RedirectResponse
    {
        OperationAccess::requireSupervisor($request);
        $tenant = OperationAccess::tenant($request);
        $route = $this->agency($tenant, $agency);
        $segments = $this->segments($route);
        $input = $request->validate([
            'segment' => ['required', 'string', Rule::in(array_keys($segments))],
            'scope' => ['required', Rule::in(['permanent', 'departure'])],
            'departure_id' => ['nullable', 'integer'],
            'plate' => ['nullable', 'string', 'max:12'],
            'driver_rut' => ['nullable', 'string', 'max:15'],
            'driver_name' => ['nullable', 'string', 'max:160'],
            'air_route_scope' => ['nullable', Rule::in(['only', 'all'])],
            'destination_address' => ['required', 'string', 'max:255'],
        ]);
        $segment = $segments[$input['segment']];
        if (! in_array($segment['key'], ['troncal', 'posta1', 'posta2', 'posta3', 'vuelo'], true)) {
            abort(404);
        }
        $address = trim($input['destination_address']);
        if ($address === '' || OperationAccess::key($address) === 'pendiente') {
            throw ValidationException::withMessages(['destination_address' => 'Ingresa una dirección de destino real.']);
        }
        if ($input['scope'] === 'permanent') {
            DB::transaction(function () use ($request, $tenant, $segment, $address, $workflow, $input): void {
                if ($segment['key'] !== 'vuelo') {
                    $request->merge(['air_route_scope' => $input['air_route_scope'] ?? 'only']);
                    $plateChanged = mb_strtoupper(str_replace(['-', ' '], '', (string) ($input['plate'] ?? ''))) !== (string) ($segment['plate'] ?? '');
                    $rutChanged = mb_strtoupper(str_replace(['.', ' '], '', (string) ($input['driver_rut'] ?? ''))) !== (string) ($segment['driver_rut'] ?? '');
                    $nameChanged = trim((string) ($input['driver_name'] ?? '')) !== (string) ($segment['driver_name'] ?? '');
                    if (! $segment['inherited_transport'] || $plateChanged || $rutChanged || $nameChanged) {
                        app(OperationSetupController::class)->updateTransport($request,
                            $segment['transport_kind'] === 'trunk' ? 'troncal' : 'posta',
                            $segment['transport_id']);
                    }
                }
                $this->updatePermanentDestination($request, $tenant, $segment, $address);
                $lotIds = DB::table('Ope_Lotes')->where('tenant_id', $tenant)->pluck('id');
                foreach ($lotIds as $lotId) {
                    $workflow->prepareGuideRoutes($tenant, (int) $lotId);
                }
            });
            $message = 'Recorrido actualizado para las próximas salidas. Las salidas ya programadas conservan sus datos.';
        } else {
            if ($segment['key'] === 'vuelo') {
                throw ValidationException::withMessages(['scope' => 'El vuelo no tiene una guía de transporte propia. Edita su dirección de forma permanente.']);
            }
            $departureId = (int) ($input['departure_id'] ?? 0);
            $this->updateDepartureData($request, $tenant, $agency, $segment, $departureId, $input, $address);
            $message = 'Cambio aplicado solo a la salida #'.$departureId.' y a sus Posta 1 relacionadas, si corresponde.';
        }

        return redirect()->to(route('operations.routes').'#agency-'.$agency)->with('status', $message);
    }

    public function save(Request $request, int $agency): RedirectResponse
    {
        OperationAccess::requireSupervisor($request);
        $tenant = OperationAccess::tenant($request);
        $route = $this->agency($tenant, $agency);
        $input = $request->validate([
            'segment' => ['required', 'string', Rule::in(array_keys($this->segments($route)))],
            'distance_km' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'maps_url' => ['nullable', 'url', 'max:4000', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }

                $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));
                if (parse_url((string) $value, PHP_URL_SCHEME) !== 'https'
                    || (! preg_match('/(^|\\.)google\\.(cl|com)$/', $host) && ! in_array($host, ['maps.app.goo.gl', 'goo.gl'], true))) {
                    $fail('Ingresa un enlace HTTPS de Google Maps.');
                }
            }],
        ]);
        $segment = $this->segments($route)[$input['segment']];
        $this->persist($tenant, $agency, $segment, [
            'distance_km' => round((float) $input['distance_km'], 1),
            'duration_minutes' => filled($input['duration_minutes'] ?? null) ? (int) $input['duration_minutes'] : null,
            'maps_url' => $input['maps_url'] ?? null,
            'source' => 'manual',
        ]);

        return redirect()->to(route('operations.routes').'#agency-'.$agency)->with('status', 'Datos del recorrido guardados.');
    }

    public function calculate(Request $request, int $agency, OperationRouteEstimator $estimator): RedirectResponse
    {
        OperationAccess::requireSupervisor($request);
        $tenant = OperationAccess::tenant($request);
        $route = $this->agency($tenant, $agency);
        $input = $request->validate(['segment' => ['required', 'string', Rule::in(array_keys($this->segments($route)))]]);
        $segment = $this->segments($route)[$input['segment']];
        $saved = DB::table('Ope_RouteEstimates')->where([
            'tenant_id' => $tenant, 'agency_id' => $agency, 'segment' => $segment['key'],
        ])->first();
        $mapsUrl = $saved && $saved->route_hash === $segment['hash'] ? $saved->maps_url : null;
        try {
            $distance = 0.0;
            $minutes = 0;
            $air = false;
            foreach ($segment['parts'] as $part) {
                $estimate = $estimator->estimate($part['origin'], $part['destination'], $part['air'],
                    count($segment['parts']) === 1 ? $mapsUrl : null);
                $distance += $estimate['distance_km'];
                $minutes += $estimate['duration_minutes'];
                $air = $air || $part['air'];
            }
            $this->persist($tenant, $agency, $segment, [
                'distance_km' => round($distance, 1), 'duration_minutes' => $minutes,
                'maps_url' => $mapsUrl,
                'source' => $air ? 'estimacion_aerea' : 'openrouteservice',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['mapas' => $exception instanceof RuntimeException
                ? $exception->getMessage() : 'No se pudo consultar el servicio de mapas. Intenta de nuevo o ingresa los valores manualmente.']);
        }

        return redirect()->to(route('operations.routes').'#agency-'.$agency)->with('status', 'Distancia y tiempo calculados. Revisa el resultado antes de usarlo.');
    }

    public function calculateBetweenStops(Request $request, int $trunk, OperationRouteEstimator $estimator): RedirectResponse
    {
        OperationAccess::requireSupervisor($request);
        $tenant = OperationAccess::tenant($request);
        $trunkRecord = DB::table('Ope_Troncales')->where(['tenant_id' => $tenant, 'id' => $trunk])->firstOrFail();
        if (in_array((int) $trunkRecord->trunk_code, [1, 2, 3], true)) {
            abort(404);
        }
        $routes = DB::table('Ope_Agencias')->where(['tenant_id' => $tenant, 'trunk_id' => $trunk])->orderBy('agency_code')
            ->pluck('id')->map(function (int $id) use ($tenant): array {
                $agency = $this->agency($tenant, $id);

                return ['agency' => $agency, 'segments' => $this->segments($agency)];
            });
        $pairs = $this->adjacentStops($this->visualLegs($routes, (int) $trunkRecord->trunk_code));
        $calculated = 0;
        $pending = 0;
        $firstFailure = null;
        foreach ($pairs as $pair) {
            try {
                $estimate = $estimator->estimate($pair['origin'], $pair['destination']);
                DB::table('Ope_RouteEstimates')->updateOrInsert(
                    ['tenant_id' => $tenant, 'agency_id' => $pair['agency_id'], 'segment' => $pair['key']],
                    ['route_hash' => $pair['hash'], 'distance_km' => $estimate['distance_km'],
                        'duration_minutes' => $estimate['duration_minutes'], 'source' => $estimate['source'],
                        'maps_url' => null, 'created_at' => now(), 'updated_at' => now()],
                );
                $calculated++;
            } catch (Throwable $exception) {
                if (! $exception instanceof RuntimeException) {
                    report($exception);
                }
                $pending = count($pairs) - $calculated;
                $firstFailure = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'No se pudo consultar el servicio de mapas. Intenta de nuevo o ingresa los kilómetros manualmente.';

                break;
            }
        }
        if ($calculated === 0 && $pending > 0) {
            throw ValidationException::withMessages(['mapas' => 'No se pudo calcular ninguna distancia entre paradas. '.$firstFailure]);
        }

        return redirect()->to(route('operations.routes').'#visual-trunk-'.$trunk)
            ->with('status', $calculated.' tramos entre paradas calculados'.($pending ? '; cálculo detenido y '.$pending.' pendientes por revisar. '.$firstFailure : '.'));
    }

    public function saveBetweenStops(Request $request, int $trunk): RedirectResponse
    {
        OperationAccess::requireSupervisor($request);
        $tenant = OperationAccess::tenant($request);
        $trunkRecord = DB::table('Ope_Troncales')->where(['tenant_id' => $tenant, 'id' => $trunk])->firstOrFail();
        if (in_array((int) $trunkRecord->trunk_code, [1, 2, 3], true)) {
            abort(404);
        }
        if (is_array($request->input('distances'))) {
            $request->merge(['distances' => array_map(
                fn (mixed $value): mixed => is_string($value) ? str_replace(',', '.', trim($value)) : $value,
                $request->input('distances'),
            )]);
        }
        $input = $request->validate(['distances' => ['required', 'array'], 'distances.*' => ['nullable', 'numeric', 'min:0', 'max:9999999']]);
        $routes = DB::table('Ope_Agencias')->where(['tenant_id' => $tenant, 'trunk_id' => $trunk])->orderBy('agency_code')
            ->pluck('id')->map(function (int $id) use ($tenant): array {
                $agency = $this->agency($tenant, $id);

                return ['agency' => $agency, 'segments' => $this->segments($agency)];
            });
        $pairs = collect($this->adjacentStops($this->visualLegs($routes, (int) $trunkRecord->trunk_code)))->keyBy('key');
        $unknown = array_diff(array_keys($input['distances']), $pairs->keys()->all());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['distances' => 'Se encontró un tramo que ya no pertenece a esta troncal. Actualiza la página.']);
        }
        $count = 0;
        foreach ($input['distances'] as $key => $distance) {
            if ($distance === null || $distance === '') {
                continue;
            }
            $pair = $pairs->get($key);
            DB::table('Ope_RouteEstimates')->updateOrInsert(
                ['tenant_id' => $tenant, 'agency_id' => $pair['agency_id'], 'segment' => $pair['key']],
                ['route_hash' => $pair['hash'], 'distance_km' => round((float) $distance, 1),
                    'duration_minutes' => 0, 'source' => 'manual', 'maps_url' => null,
                    'created_at' => now(), 'updated_at' => now()],
            );
            $count++;
        }

        return redirect()->to(route('operations.routes').'#visual-trunk-'.$trunk)
            ->with('status', $count.' distancias entre paradas guardadas.');
    }

    /** @param array<string, mixed> $segment */
    private function updatePermanentDestination(Request $request, int $tenant, array $segment, string $address): void
    {
        $kind = $segment['destination_kind'];
        $table = match ($kind) {
            'agency' => 'Ope_Agencias',
            'post_origin' => 'Ope_Postas',
            default => 'Ope_Troncales',
        };
        $column = $kind === 'post_origin' ? 'origin_address' : ($kind === 'agency' ? 'address' : 'destination_address');
        $record = DB::table($table)->where(['tenant_id' => $tenant, 'id' => $segment['destination_id']])->lockForUpdate()->firstOrFail();
        if ($record->{$column} === $address) {
            return;
        }
        DB::table($table)->where('id', $record->id)->update([$column => $address, 'updated_at' => now()]);
        OperationAccess::audit($tenant, $request->user()->id, 'Actualizar destino de ruta', $kind, $record->id,
            [$column => $address], [$column => $record->{$column}]);
        if ($kind === 'agency' && $segment['key'] === 'troncal') {
            $trunk = DB::table('Ope_Troncales')->where(['tenant_id' => $tenant, 'id' => $segment['transport_id']])->lockForUpdate()->firstOrFail();
            if (((int) $trunk->trunk_code === 4 && (int) $record->agency_code === 13)
                || ((int) $trunk->trunk_code === 6 && (int) $record->agency_code === 30)) {
                DB::table('Ope_Troncales')->where('id', $trunk->id)->update(['destination_address' => $address, 'updated_at' => now()]);
                OperationAccess::audit($tenant, $request->user()->id, 'Sincronizar destino de troncal', 'troncal', $trunk->id,
                    ['destination_address' => $address], ['destination_address' => $trunk->destination_address]);
            }
        }
    }

    /** @param array<string, mixed> $segment
     * @param  array<string, mixed>  $input
     */
    private function updateDepartureData(Request $request, int $tenant, int $agency, array $segment, int $departureId, array $input, string $address): void
    {
        if ($departureId <= 0) {
            throw ValidationException::withMessages(['departure_id' => 'Selecciona la salida concreta que quieres modificar.']);
        }
        $matching = DB::table('Ope_ProgramacionSalidas as departure')
            ->join('Ope_Lotes as lot', 'lot.id', '=', 'departure.lot_id')
            ->join('Ope_SalidaAgencias as assigned', 'assigned.departure_id', '=', 'departure.id')
            ->join('Ope_GuiaConfiguraciones as configuration', 'configuration.id', '=', 'assigned.configuration_id')
            ->join('PPR_coverages as coverage', 'coverage.id', '=', 'configuration.coverage_id')
            ->join('Ope_Agencias as agency', function ($join): void {
                $join->on('agency.agency_code', '=', 'coverage.ID_ComunaMatrizAgencia')
                    ->on('agency.tenant_id', '=', 'coverage.tenant_id');
            })
            ->where('lot.tenant_id', $tenant)->where('departure.id', $departureId)
            ->where('departure.status', 'draft')->where('configuration.role', $segment['key']);
        if ($segment['group_code'] === null) {
            $matching->whereNull('configuration.group_code')->where('agency.id', $agency);
        } else {
            $matching->where('configuration.group_code', $segment['group_code']);
        }
        if (! $matching->exists()) {
            throw ValidationException::withMessages(['departure_id' => 'La salida seleccionada no está pendiente o no corresponde a este tramo.']);
        }
        foreach (['plate', 'driver_rut', 'driver_name'] as $field) {
            if (blank($input[$field] ?? null)) {
                throw ValidationException::withMessages([$field => 'Completa patente, nombre y RUT para esta salida.']);
            }
        }

        DB::transaction(function () use ($request, $tenant, $segment, $departureId, $input, $address): void {
            $departure = DB::table('Ope_ProgramacionSalidas as departure')
                ->join('Ope_Lotes as lot', 'lot.id', '=', 'departure.lot_id')
                ->where('lot.tenant_id', $tenant)->where('departure.id', $departureId)
                ->select('departure.*')->lockForUpdate()->firstOrFail();
            if ($departure->status !== 'draft') {
                throw ValidationException::withMessages(['departure_id' => 'Reabre la salida antes de modificarla.']);
            }
            if ($segment['inherited_transport'] && (
                mb_strtoupper(str_replace(['-', ' '], '', (string) $input['plate'])) !== (string) $departure->plate
                || mb_strtoupper(str_replace(['.', ' '], '', (string) $input['driver_rut'])) !== (string) $departure->driver_rut
                || trim((string) $input['driver_name']) !== (string) $departure->driver_name
            )) {
                throw ValidationException::withMessages(['plate' => 'La Posta 1 hereda patente y chofer de la troncal. Modifica esa salida troncal para cambiarlos.']);
            }
            $assignment = array_intersect_key($input, array_flip(['plate', 'driver_name', 'driver_rut']));
            $workflow = app(OperationWorkflow::class);
            $workflow->saveAssignment($tenant, $request->user()->id, $departureId, $assignment);

            $destination = DB::table('Ope_Ubicaciones')->where(['tenant_id' => $tenant, 'id' => $departure->destination_id])->firstOrFail();
            $locationId = null;
            if ($destination->address !== $address) {
                $location = (array) $destination;
                unset($location['id']);
                $location['address'] = $address;
                $location['created_at'] = now();
                $location['updated_at'] = now();
                $locationId = DB::table('Ope_Ubicaciones')->insertGetId($location);
                $location['id'] = $locationId;
                DB::table('Ope_ProgramacionSalidas')->where('id', $departureId)->update(['destination_id' => $locationId, 'updated_at' => now()]);
                foreach (DB::table('Ope_SalidaAgencias')->where('departure_id', $departureId)->get() as $assigned) {
                    $snapshot = json_decode($assigned->snapshot, true);
                    $snapshot['destination'] = $location;
                    $snapshot['configuration']['destination_id'] = $locationId;
                    DB::table('Ope_SalidaAgencias')->where('id', $assigned->id)->update(['snapshot' => OperationAccess::json($snapshot)]);
                }
                OperationAccess::audit($tenant, $request->user()->id, 'Cambiar destino solo de salida', 'salida', $departureId,
                    ['address' => $address, 'location_id' => $locationId], ['address' => $destination->address, 'location_id' => $destination->id]);
            }

            if ($segment['key'] === 'troncal') {
                $trunk = DB::table('Ope_Troncales')->where(['tenant_id' => $tenant, 'id' => $segment['transport_id']])->firstOrFail();
                if (! in_array((int) $trunk->trunk_code, [1, 2, 3], true)) {
                    $postDepartureIds = DB::table('Ope_BultoTramos as source')
                        ->join('Ope_BultoTramos as next', 'next.package_id', '=', 'source.package_id')
                        ->join('Ope_GuiaConfiguraciones as configuration', 'configuration.id', '=', 'next.configuration_id')
                        ->join('Ope_ProgramacionSalidas as post_departure', 'post_departure.id', '=', 'next.departure_id')
                        ->where('source.departure_id', $departureId)->where('configuration.role', 'posta1')
                        ->where('post_departure.lot_id', $departure->lot_id)->distinct()->pluck('post_departure.id');
                    foreach ($postDepartureIds as $postDepartureId) {
                        $workflow->saveAssignment($tenant, $request->user()->id, (int) $postDepartureId, $assignment);
                        if ($locationId !== null) {
                            $postDeparture = DB::table('Ope_ProgramacionSalidas')->where('id', $postDepartureId)->firstOrFail();
                            $postOrigin = DB::table('Ope_Ubicaciones')->where('id', $postDeparture->origin_id)->firstOrFail();
                            if ($postOrigin->address === $destination->address && $postOrigin->commune === $destination->commune) {
                                DB::table('Ope_ProgramacionSalidas')->where('id', $postDepartureId)->update(['origin_id' => $locationId, 'updated_at' => now()]);
                                foreach (DB::table('Ope_SalidaAgencias')->where('departure_id', $postDepartureId)->get() as $postAssigned) {
                                    $snapshot = json_decode($postAssigned->snapshot, true);
                                    $snapshot['origin'] = $location;
                                    $snapshot['configuration']['origin_id'] = $locationId;
                                    DB::table('Ope_SalidaAgencias')->where('id', $postAssigned->id)->update(['snapshot' => OperationAccess::json($snapshot)]);
                                }
                                OperationAccess::audit($tenant, $request->user()->id, 'Heredar origen desde troncal', 'salida', (int) $postDepartureId,
                                    ['origin_id' => $locationId], ['origin_id' => $postDeparture->origin_id]);
                            }
                        }
                    }
                }
            }
        });
    }

    private function agency(int $tenant, int $agency): object
    {
        return DB::table('Ope_Agencias as agency')
            ->join('Ope_Troncales as trunk', 'trunk.id', '=', 'agency.trunk_id')
            ->join('Ope_Postas as post', 'post.id', '=', 'agency.post_id')
            ->leftJoin('Ope_Postas as second_post', 'second_post.id', '=', 'agency.second_post_id')
            ->where('agency.tenant_id', $tenant)->where('agency.id', $agency)
            ->select('agency.*', 'trunk.trunk_code', 'trunk.name as trunk_name', 'trunk.origin_address as trunk_origin_address',
                'trunk.origin_commune as trunk_origin_commune', 'trunk.destination_address as trunk_destination_address',
                'trunk.destination_commune as trunk_destination_commune', 'trunk.plate as trunk_plate',
                'post.name as post_name', 'post.plate as post_plate', 'post.origin_address as post_origin_address',
                'post.origin_commune as post_origin_commune', 'second_post.name as second_post_name',
                'second_post.plate as second_post_plate', 'second_post.origin_address as second_post_origin_address',
                'second_post.origin_commune as second_post_origin_commune')->firstOrFail();
    }

    /** @return array<string, array<string, mixed>> */
    private function segments(object $agency): array
    {
        $catalog = $this->routeCatalogs[$agency->tenant_id] ??= $this->routeCatalog((int) $agency->tenant_id);
        $trunk = $catalog['trunks']->get($agency->trunk_id);
        $legs = $trunk ? app(OperationGuideRoutePlanner::class)->legs($agency, $trunk, $catalog['postsById'], $catalog['postsByCode'], $catalog['agencies']) : [];
        if ($legs === []) {
            return [];
        }

        $origin = $this->pointAddress($legs[0]['origin']);
        $air = in_array((int) $agency->trunk_code, [1, 2, 3], true);
        $segments = [];
        foreach ($legs as $leg) {
            $transport = $leg['transportKind'] === 'trunk' ? $trunk : $catalog['postsById']->get($leg['transportId']);
            $driver = $transport?->driver_id ? $catalog['drivers']->get($transport->driver_id) : null;
            $role = ['troncal' => 'Troncal', 'posta1' => 'Posta 1', 'posta2' => 'Posta 2', 'posta3' => 'Posta 3'][$leg['role']];
            $segment = $this->segment($leg['role'], $role, $transport?->name ?? $role,
                $this->pointAddress($leg['origin']), $this->pointAddress($leg['destination']), $transport?->plate);
            $segment['driver_name'] = $driver?->name;
            $segment['driver_rut'] = $driver?->rut;
            $segment['group_code'] = $leg['groupCode'];
            $segment['stop_order'] = $leg['stopOrder'];
            $segment['origin_name'] = $leg['origin']['name'];
            $segment['destination_name'] = $leg['destination']['name'];
            $segment['destination_address'] = $leg['destination']['address'];
            $segment['destination_commune'] = $leg['destination']['commune'];
            $segment['transport_kind'] = $leg['transportKind'];
            $segment['transport_id'] = $leg['transportId'];
            $segment['trunk_id'] = $trunk->id;
            $segment['air_trunk'] = $leg['role'] === 'troncal' && $air;
            $segment['inherited_transport'] = $leg['role'] === 'posta1' && in_array((int) $trunk->trunk_code, [4, 5, 6], true);
            $destinationAgency = $catalog['agencies']->first(fn (object $entry): bool => $entry->name === $leg['destination']['name']
                && $entry->address === $leg['destination']['address']
                && $entry->commune === $leg['destination']['commune']);
            $segment['destination_kind'] = $destinationAgency ? 'agency' : 'trunk';
            $segment['destination_id'] = $destinationAgency?->id ?? $trunk->id;
            $segments[$leg['role']] = $segment;
            if ($air && $leg['role'] === 'troncal') {
                $airport = $this->pointAddress($legs[1]['origin']);
                $segments['vuelo'] = $this->segment('vuelo', 'Vuelo', $agency->trunk_name.' → '.$agency->post_name,
                    $segment['destination'], $airport, null, true);
                $segments['vuelo']['destination_address'] = $legs[1]['origin']['address'];
                $segments['vuelo']['destination_commune'] = $legs[1]['origin']['commune'];
                $segments['vuelo']['destination_kind'] = 'post_origin';
                $segments['vuelo']['destination_id'] = $agency->post_id;
                $segments['vuelo']['inherited_transport'] = false;
                $segments['vuelo']['air_trunk'] = false;
            }
            if ($leg['role'] === 'troncal') {
                continue;
            }
            $returnParts = $air
                ? [['origin' => $segment['destination'], 'destination' => $segment['origin'], 'air' => false],
                    ['origin' => $segment['origin'], 'destination' => $segments['troncal']['destination'], 'air' => true],
                    ['origin' => $segments['troncal']['destination'], 'destination' => $origin, 'air' => false]]
                : [['origin' => $segment['destination'], 'destination' => $origin, 'air' => false]];
            $segments['retorno_'.$leg['role']] = $this->segment('retorno_'.$leg['role'], 'Retorno de '.$role,
                $transport?->name ?? $role, $segment['destination'], $origin, $transport?->plate, $air, $returnParts);
        }

        return $segments;
    }

    /** @return array{trunks: Collection, postsById: Collection, postsByCode: Collection, agencies: Collection, drivers: Collection} */
    private function routeCatalog(int $tenant): array
    {
        $posts = DB::table('Ope_Postas')->where('tenant_id', $tenant)->get();

        return [
            'trunks' => DB::table('Ope_Troncales')->where('tenant_id', $tenant)->get()->keyBy('id'),
            'postsById' => $posts->keyBy('id'),
            'postsByCode' => $posts->keyBy('post_code'),
            'agencies' => DB::table('Ope_Agencias')->where('tenant_id', $tenant)->get()->keyBy('agency_code'),
            'drivers' => DB::table('Ope_Choferes')->where('tenant_id', $tenant)->get()->keyBy('id'),
        ];
    }

    /** @param  array{name: string, address: string, commune: string}  $point */
    private function pointAddress(array $point): string
    {
        return $this->address($point['address'], $point['commune']);
    }

    /** @return Collection<int, array{segment: array<string, mixed>, agencies: Collection, label: string}> */
    private function visualLegs(Collection $routes, int $trunkCode): Collection
    {
        return $routes->flatMap(fn (array $route): Collection => collect($route['segments'])
            ->filter(fn (array $segment): bool => in_array($segment['key'], ['troncal', 'posta1', 'posta2', 'posta3'], true))
            ->map(fn (array $segment): array => ['segment' => $segment, 'agency' => $route['agency']->name, 'agency_id' => $route['agency']->id])
            ->values())
            ->groupBy(fn (array $entry): string => OperationAccess::json([
                $entry['segment']['key'], $entry['segment']['group_code'], $entry['segment']['origin'],
                $entry['segment']['destination'], $entry['segment']['name'], $entry['segment']['plate'],
                $entry['segment']['driver_rut'],
            ]))
            ->map(function (Collection $entries): array {
                $segment = $entries->first()['segment'];
                $agencies = $entries->pluck('agency')->unique()->values();
                $destination = $segment['destination_name'];
                $label = str_contains((string) $segment['group_code'], 'transfer')
                    ? 'Consolidado a '.$destination
                    : ($agencies->count() === 1 && $agencies->first() !== $destination
                        ? $agencies->first().' → '.$destination : $destination);

                $agencyId = $entries->first()['agency_id'];

                return compact('segment', 'agencies', 'label', 'agencyId');
            })
            ->sort(function (array $left, array $right) use ($trunkCode): int {
                $order = fn (array $item): array => [
                    ['troncal' => 1, 'posta1' => 2, 'posta2' => 3, 'posta3' => 4][$item['segment']['key']],
                    $this->visualStopOrder($item['segment'], $trunkCode),
                    $item['label'],
                ];

                return $order($left) <=> $order($right);
            })
            ->values();
    }

    /** @return array<int, array{key: string, agency_id: int, origin: string, destination: string, hash: string, from_label: string, to_label: string, role: string}> */
    private function adjacentStops(Collection $visualLegs): array
    {
        $pairs = [];
        foreach ($visualLegs->groupBy(fn (array $stop): string => $stop['segment']['key']) as $role => $lane) {
            $stops = $lane->values();
            for ($index = 0; $index < $stops->count() - 1; $index++) {
                $from = $stops[$index];
                $to = $stops[$index + 1];
                $origin = $from['segment']['destination'];
                $destination = $to['segment']['destination'];
                $pairs[] = [
                    'key' => 'gap:'.$role.':'.$from['agencyId'].':'.$to['agencyId'],
                    'agency_id' => (int) $to['agencyId'],
                    'origin' => $origin,
                    'destination' => $destination,
                    'hash' => hash('sha256', OperationAccess::json([$origin, $destination])),
                    'from_label' => $from['label'],
                    'to_label' => $to['label'],
                    'role' => $role,
                ];
            }
        }

        return $pairs;
    }

    /** @param  array<string, mixed>  $segment */
    private function visualStopOrder(array $segment, int $trunkCode): int
    {
        if ($segment['key'] !== 'posta1') {
            return (int) $segment['stop_order'];
        }

        if ($trunkCode === 4) {
            return match ($segment['group_code']) {
                'south:concepcion:14' => 1,
                'south:concepcion:15' => 2,
                'south:temuco:16' => 3,
                'south:temuco:17' => 4,
                'south:temuco:transfer' => 5,
                default => (int) $segment['stop_order'],
            };
        }

        if ($trunkCode === 5) {
            return match (OperationAccess::key((string) $segment['destination_name'])) {
                'vina del mar' => 1,
                'litoral' => 2,
                'casa blanca', 'casablanca' => 3,
                'curacavi' => 4,
                'talagante' => 5,
                default => 99,
            };
        }

        return (int) $segment['stop_order'];
    }

    private function address(?string $address, ?string $commune): string
    {
        return trim((string) $address).', '.trim((string) $commune);
    }

    /** @param array<int, array{origin: string, destination: string, air: bool}>|null $parts
     * @return array<string, mixed>
     */
    private function segment(string $key, string $role, string $name, string $origin, string $destination, ?string $plate, bool $air = false, ?array $parts = null): array
    {
        $parts ??= [['origin' => $origin, 'destination' => $destination, 'air' => $air]];

        return [
            'key' => $key, 'role' => $role, 'name' => $name, 'origin' => $origin, 'destination' => $destination,
            'plate' => $plate, 'air' => $air, 'parts' => $parts,
            'hash' => hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE)),
        ];
    }

    /** @param array<string, mixed> $segment
     * @param  array{distance_km: float, duration_minutes: int|null, maps_url: string|null, source: string}  $estimate
     */
    private function persist(int $tenant, int $agency, array $segment, array $estimate): void
    {
        DB::table('Ope_RouteEstimates')->updateOrInsert(
            ['tenant_id' => $tenant, 'agency_id' => $agency, 'segment' => $segment['key']],
            [...$estimate, 'route_hash' => $segment['hash'], 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
