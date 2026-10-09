<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\OperationAccess;
use App\Modules\Operations\Services\OperationGuideRoutePlanner;
use App\Modules\Operations\Services\OperationWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OperationSetupController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = OperationAccess::tenant($request);

        $agencyRoutes = DB::table('Ope_Agencias as agency')
            ->join('Ope_Troncales as trunk', 'trunk.id', '=', 'agency.trunk_id')
            ->join('Ope_Postas as post', 'post.id', '=', 'agency.post_id')
            ->leftJoin('Ope_Postas as second_post', 'second_post.id', '=', 'agency.second_post_id')
            ->where('agency.tenant_id', $tenant)
            ->orderBy('agency.agency_code')
            ->select(
                'agency.id', 'agency.agency_code', 'agency.name', 'agency.address', 'agency.commune',
                'trunk.trunk_code', 'trunk.name as trunk_name', 'trunk.origin_address', 'trunk.origin_commune',
                'trunk.destination_address', 'trunk.destination_commune',
                'post.post_code', 'post.name as post_name', 'post.is_active as post_is_active',
                'second_post.post_code as second_post_code', 'second_post.name as second_post_name',
            )
            ->get();

        $coverageCounts = DB::table('PPR_coverages')
            ->where('tenant_id', $tenant)
            ->where('is_active', true)
            ->whereNotNull('ID_ComunaMatrizAgencia')
            ->select('ID_ComunaMatrizAgencia')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('ID_ComunaMatrizAgencia')
            ->pluck('total', 'ID_ComunaMatrizAgencia');

        $trunks = DB::table('Ope_Troncales as trunk')
            ->leftJoin('Ope_Choferes as driver', 'driver.id', '=', 'trunk.driver_id')
            ->where('trunk.tenant_id', $tenant)
            ->orderBy('trunk.trunk_code')
            ->select('trunk.*', 'driver.rut as driver_rut', 'driver.name as driver_name')
            ->get();

        $posts = DB::table('Ope_Postas as post')
            ->leftJoin('Ope_Choferes as driver', 'driver.id', '=', 'post.driver_id')
            ->where('post.tenant_id', $tenant)
            ->orderBy('post.post_code')
            ->select('post.*', 'driver.rut as driver_rut', 'driver.name as driver_name')
            ->get();

        return view('operations::setup', [
            'locations' => DB::table('Ope_Ubicaciones')->where('tenant_id', $tenant)->orderBy('name')->get(),
            'configurations' => DB::table('Ope_GuiaConfiguraciones as c')
                ->join('PPR_coverages as coverage', 'coverage.id', '=', 'c.coverage_id')
                ->leftJoin('Ope_Agencias as agency', function ($join): void {
                    $join->on('agency.agency_code', '=', 'coverage.ID_ComunaMatrizAgencia')
                        ->on('agency.tenant_id', '=', 'coverage.tenant_id');
                })
                ->leftJoin('Ope_Troncales as trunk', 'trunk.id', '=', 'agency.trunk_id')
                ->leftJoin('Ope_Postas as first_post', 'first_post.id', '=', 'agency.post_id')
                ->leftJoin('Ope_Postas as second_post', 'second_post.id', '=', 'agency.second_post_id')
                ->join('Ope_Ubicaciones as o', 'o.id', '=', 'c.origin_id')
                ->join('Ope_Ubicaciones as d', 'd.id', '=', 'c.destination_id')
                ->where('c.tenant_id', $tenant)
                ->orderBy('coverage.commune_name')
                ->orderBy('c.sequence')
                ->get(['c.*', 'coverage.commune_name', 'agency.name as agency_name', 'trunk.name as trunk_name',
                    'first_post.name as first_post_name', 'second_post.name as second_post_name',
                    'o.name as origin_name', 'd.name as destination_name']),
            'agencyRoutes' => $agencyRoutes,
            'coverageCounts' => $coverageCounts,
            'trunks' => $trunks,
            'posts' => $posts,
        ]);
    }

    public function transport(Request $request): View
    {
        $tenant = OperationAccess::tenant($request);
        $trunks = DB::table('Ope_Troncales as trunk')
            ->leftJoin('Ope_Choferes as driver', 'driver.id', '=', 'trunk.driver_id')
            ->where('trunk.tenant_id', $tenant)
            ->orderBy('trunk.trunk_code')
            ->select('trunk.*', 'driver.rut as driver_rut', 'driver.name as driver_name')
            ->get();
        $posts = DB::table('Ope_Postas as post')
            ->leftJoin('Ope_Choferes as driver', 'driver.id', '=', 'post.driver_id')
            ->where('post.tenant_id', $tenant)
            ->orderBy('post.post_code')
            ->select('post.*', 'driver.rut as driver_rut', 'driver.name as driver_name')
            ->get();
        $agencies = DB::table('Ope_Agencias')->where('tenant_id', $tenant)->get(['trunk_id', 'post_id', 'second_post_id']);
        $linkedFirstPosts = DB::table('Ope_Agencias as agency')
            ->join('Ope_Troncales as trunk', 'trunk.id', '=', 'agency.trunk_id')
            ->where('agency.tenant_id', $tenant)
            ->whereIn('trunk.trunk_code', [4, 5, 6])
            ->select('agency.post_id', 'trunk.id as trunk_id', 'trunk.name as trunk_name')
            ->distinct()
            ->get()
            ->keyBy('post_id');
        $coverageAssignments = DB::table('PPR_coverages as coverage')
            ->join('Ope_Agencias as agency', function ($join): void {
                $join->on('agency.agency_code', '=', 'coverage.ID_ComunaMatrizAgencia')
                    ->on('agency.tenant_id', '=', 'coverage.tenant_id');
            })
            ->where('coverage.tenant_id', $tenant)
            ->select('agency.trunk_id', 'agency.post_id', 'agency.second_post_id')
            ->get();
        $plates = DB::table('MBA_vehicles')->where(['tenant_id' => $tenant, 'is_active' => true])->pluck('plate')
            ->concat($trunks->pluck('plate'))
            ->concat($posts->pluck('plate'))
            ->filter()
            ->map(fn (string $plate): string => mb_strtoupper(str_replace(['-', ' '], '', $plate)))
            ->unique()
            ->sort()
            ->values();
        $drivers = DB::table('Ope_Choferes')->where(['tenant_id' => $tenant, 'is_active' => true])->get(['rut', 'name'])
            ->concat(DB::table('MBA_users as u')
                ->join('MBA_tenant_users as member', 'member.user_id', '=', 'u.id')
                ->where(['member.tenant_id' => $tenant, 'member.is_active' => true])
                ->whereNotNull('u.tax_id')
                ->get(['u.tax_id as rut', 'u.name']))
            ->filter(fn (object $driver): bool => filled($driver->rut) && filled($driver->name))
            ->unique(fn (object $driver): string => mb_strtoupper(str_replace(['.', ' '], '', $driver->rut)))
            ->sortBy(fn (object $driver): string => mb_strtolower($driver->name))
            ->values();

        $selected = null;
        $type = (string) $request->query('type', '');
        if ($type !== '' || $request->filled('record')) {
            abort_unless(in_array($type, ['troncal', 'posta'], true) && $request->integer('record') > 0, 404);
            $selected = ($type === 'troncal' ? $trunks : $posts)->firstWhere('id', $request->integer('record'));
            abort_if($selected === null, 404);
        }

        return view('operations::transport', [
            'trunks' => $trunks,
            'posts' => $posts,
            'plates' => $plates,
            'drivers' => $drivers,
            'selected' => $selected,
            'selectedType' => $type,
            'trunkUsage' => $agencies->countBy('trunk_id'),
            'firstPostUsage' => $agencies->countBy('post_id'),
            'secondPostUsage' => $agencies->countBy('second_post_id'),
            'linkedFirstPosts' => $linkedFirstPosts,
            'trunkCoverageCounts' => $coverageAssignments->countBy('trunk_id'),
            'postCoverageCounts' => $coverageAssignments->pluck('post_id')->concat($coverageAssignments->pluck('second_post_id'))->filter()->countBy(),
            'canEdit' => OperationAccess::supervisor($request),
        ]);
    }

    public function transportAgencies(Request $request, string $type, int $record): JsonResponse
    {
        abort_unless(in_array($type, ['troncal', 'posta'], true), 404);
        $tenant = OperationAccess::tenant($request);
        $table = $type === 'troncal' ? 'Ope_Troncales' : 'Ope_Postas';
        abort_unless(DB::table($table)->where(['tenant_id' => $tenant, 'id' => $record])->exists(), 404);

        $query = DB::table('Ope_Agencias as agency')
            ->join('Ope_Postas as first_post', 'first_post.id', '=', 'agency.post_id')
            ->leftJoin('Ope_Postas as second_post', 'second_post.id', '=', 'agency.second_post_id')
            ->where('agency.tenant_id', $tenant);
        if ($type === 'troncal') {
            $query->where('agency.trunk_id', $record);
        } else {
            $role = (string) $request->query('role', '');
            abort_unless(in_array($role, ['1', '2'], true), 404);
            $query->where($role === '1' ? 'agency.post_id' : 'agency.second_post_id', $record);
        }

        $agencies = $query
            ->orderBy('agency.agency_code')
            ->get(['agency.agency_code as code', 'agency.name', 'agency.address', 'agency.commune as matrix_origin_commune',
                'first_post.name as first_post', 'second_post.name as second_post']);

        return response()->json(['agencies' => $agencies]);
    }

    public function transportCoverages(Request $request, string $type, int $record): JsonResponse
    {
        abort_unless(in_array($type, ['troncal', 'posta'], true), 404);
        $tenant = OperationAccess::tenant($request);
        $table = $type === 'troncal' ? 'Ope_Troncales' : 'Ope_Postas';
        abort_unless(DB::table($table)->where(['tenant_id' => $tenant, 'id' => $record])->exists(), 404);

        $query = DB::table('PPR_coverages as coverage')
            ->join('Ope_Agencias as agency', function ($join): void {
                $join->on('agency.agency_code', '=', 'coverage.ID_ComunaMatrizAgencia')
                    ->on('agency.tenant_id', '=', 'coverage.tenant_id');
            })
            ->where('coverage.tenant_id', $tenant);
        if ($type === 'troncal') {
            $query->where('agency.trunk_id', $record);
        } else {
            $query->where(function ($query) use ($record): void {
                $query->where('agency.post_id', $record)->orWhere('agency.second_post_id', $record);
            });
        }

        $assignments = $query
            ->orderBy('agency.agency_code')
            ->orderBy('coverage.commune_name')
            ->get(['coverage.id', 'coverage.commune_name', 'coverage.provider_name_source', 'coverage.delivery_frequency', 'coverage.is_active',
                'agency.name as agency_name', 'agency.post_id', 'agency.second_post_id']);

        $coverages = collect();
        foreach ($assignments as $assignment) {
            if ($type === 'troncal') {
                $coverages->push(['id' => $assignment->id, 'agency' => $assignment->agency_name, 'role' => 'Troncal', 'commune' => $assignment->commune_name,
                    'provider' => $assignment->provider_name_source, 'frequency' => $assignment->delivery_frequency, 'active' => (bool) $assignment->is_active]);
            } else {
                foreach (['Posta 1' => $assignment->post_id, 'Posta 2' => $assignment->second_post_id] as $role => $postId) {
                    if ($postId !== null && (int) $postId === $record) {
                        $coverages->push(['id' => $assignment->id, 'agency' => $assignment->agency_name, 'role' => $role, 'commune' => $assignment->commune_name,
                            'provider' => $assignment->provider_name_source, 'frequency' => $assignment->delivery_frequency, 'active' => (bool) $assignment->is_active]);
                    }
                }
            }
        }

        return response()->json(['coverages' => $coverages]);
    }

    public function updateTransport(Request $request, string $type, int $record): RedirectResponse
    {
        OperationAccess::requireSupervisor($request);
        abort_unless(in_array($type, ['troncal', 'posta'], true), 404);
        $tenant = OperationAccess::tenant($request);
        $trunkCode = $type === 'troncal' ? DB::table('Ope_Troncales')->where(['tenant_id' => $tenant, 'id' => $record])->value('trunk_code') : null;
        $isAirRoute = $type === 'troncal' && in_array((int) $trunkCode, [1, 2, 3], true);
        $input = $request->validate([
            'plate' => ['nullable', 'string', 'max:12'],
            'driver_rut' => ['nullable', 'string', 'max:15'],
            'driver_name' => ['nullable', 'string', 'max:160'],
            'air_route_scope' => [$isAirRoute ? 'required' : 'nullable', Rule::in(['only', 'all'])],
        ]);
        $extendToOtherAirRoutes = $isAirRoute && ($input['air_route_scope'] ?? null) === 'all';

        $plate = mb_strtoupper(str_replace(['-', ' '], '', trim((string) ($input['plate'] ?? ''))));
        $rut = mb_strtoupper(str_replace(['.', ' '], '', trim((string) ($input['driver_rut'] ?? ''))));
        $driverName = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['driver_name'] ?? '')));
        $plate = $plate === '' ? null : $plate;
        $rut = $rut === '' ? null : $rut;
        $driverName = $driverName === '' ? null : $driverName;

        if ($plate !== null && preg_match('/^(?:[A-Z]{4}\d{2}|[A-Z]{2}\d{4})$/D', $plate) !== 1) {
            throw ValidationException::withMessages(['plate' => 'Ingresa una patente chilena válida: ABCD12 o AB1234, o deja el campo vacío.']);
        }
        if (($rut === null) !== ($driverName === null)) {
            throw ValidationException::withMessages(['driver_rut' => 'Ingresa juntos el RUT y el nombre del chofer, o deja ambos vacíos.']);
        }
        if ($rut !== null && ! OperationAccess::validRut($rut)) {
            throw ValidationException::withMessages(['driver_rut' => 'El RUT del chofer no tiene un dígito verificador válido.']);
        }
        if ($driverName !== null && (in_array(OperationAccess::key($driverName), ['pendiente', 'no aplica', 'n/a', 'por definir'], true) || preg_match('/\p{L}/u', $driverName) !== 1)) {
            throw ValidationException::withMessages(['driver_name' => 'Ingresa el nombre real del chofer o deja RUT y nombre vacíos.']);
        }

        $table = $type === 'troncal' ? 'Ope_Troncales' : 'Ope_Postas';
        $name = DB::transaction(function () use ($request, $tenant, $table, $type, $record, $plate, $rut, $driverName, $extendToOtherAirRoutes): string {
            $before = DB::table($table)->where(['tenant_id' => $tenant, 'id' => $record])->lockForUpdate()->firstOrFail();
            $previousDriver = $before->driver_id ? DB::table('Ope_Choferes')->where(['tenant_id' => $tenant, 'id' => $before->driver_id])->first() : null;
            $driverId = null;

            if ($rut !== null) {
                $driver = DB::table('Ope_Choferes')->where(['tenant_id' => $tenant, 'rut' => $rut])->lockForUpdate()->first();
                if ($driver) {
                    $driverId = $driver->id;
                    if ($driver->name !== $driverName) {
                        DB::table('Ope_Choferes')->where('id', $driverId)->update(['name' => $driverName, 'updated_at' => now()]);
                        OperationAccess::audit($tenant, $request->user()->id, 'Corregir nombre de chofer', 'chofer', $driverId, ['rut' => $rut, 'name' => $driverName], ['rut' => $rut, 'name' => $driver->name]);
                    }
                } else {
                    $userId = DB::table('MBA_users as u')
                        ->join('MBA_tenant_users as member', 'member.user_id', '=', 'u.id')
                        ->where('member.tenant_id', $tenant)
                        ->where('u.tax_id', $rut)
                        ->value('u.id');
                    $driverId = DB::table('Ope_Choferes')->insertGetId(['tenant_id' => $tenant, 'user_id' => $userId, 'rut' => $rut, 'name' => $driverName, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
            }

            $vehicleId = $plate === null ? null : DB::table('MBA_vehicles')->where(['tenant_id' => $tenant, 'plate' => $plate])->value('id');
            DB::table($table)->where('id', $record)->update(['plate' => $plate, 'vehicle_id' => $vehicleId, 'driver_id' => $driverId, 'updated_at' => now()]);
            OperationAccess::audit($tenant, $request->user()->id, 'Actualizar transporte', $type, $record,
                ['plate' => $plate, 'driver_rut' => $rut, 'driver_name' => $driverName],
                ['plate' => $before->plate, 'driver_rut' => $previousDriver?->rut, 'driver_name' => $previousDriver?->name]);

            if ($extendToOtherAirRoutes) {
                $otherAirRoutes = DB::table('Ope_Troncales')
                    ->where('tenant_id', $tenant)
                    ->whereIn('trunk_code', [1, 2, 3])
                    ->where('id', '!=', $record)
                    ->lockForUpdate()
                    ->get();
                foreach ($otherAirRoutes as $airRoute) {
                    DB::table('Ope_Troncales')->where('id', $airRoute->id)->update(['plate' => $plate, 'vehicle_id' => $vehicleId, 'driver_id' => $driverId, 'updated_at' => now()]);
                    OperationAccess::audit($tenant, $request->user()->id, 'Extender transporte aéreo', 'troncal', $airRoute->id,
                        ['plate' => $plate, 'driver_id' => $driverId],
                        ['plate' => $airRoute->plate, 'driver_id' => $airRoute->driver_id]);
                }
            }

            if ($type === 'troncal' && in_array($before->trunk_code, [4, 5, 6], true)) {
                $agencies = DB::table('Ope_Agencias')->where(['tenant_id' => $tenant, 'trunk_id' => $record])->get();
                $allAgencies = DB::table('Ope_Agencias')->where('tenant_id', $tenant)->get()->keyBy('agency_code');
                $allPosts = DB::table('Ope_Postas')->where('tenant_id', $tenant)->get();
                $postsById = $allPosts->keyBy('id');
                $postsByCode = $allPosts->keyBy('post_code');
                $planner = app(OperationGuideRoutePlanner::class);
                $firstPostIds = $agencies->pluck('post_id');
                foreach ($agencies as $agency) {
                    foreach ($planner->legs($agency, $before, $postsById, $postsByCode, $allAgencies) as $leg) {
                        if ($leg['role'] === 'posta1' && $leg['transportKind'] === 'post') {
                            $firstPostIds->push($leg['transportId']);
                        }
                    }
                }
                $firstPosts = DB::table('Ope_Postas')
                    ->where('tenant_id', $tenant)
                    ->whereIn('id', $firstPostIds->unique())
                    ->lockForUpdate()
                    ->get();
                foreach ($firstPosts as $post) {
                    DB::table('Ope_Postas')->where('id', $post->id)->update(['plate' => $plate, 'vehicle_id' => $vehicleId, 'driver_id' => $driverId, 'updated_at' => now()]);
                    OperationAccess::audit($tenant, $request->user()->id, 'Sincronizar Posta 1 con troncal', 'posta', $post->id,
                        ['plate' => $plate, 'driver_id' => $driverId],
                        ['plate' => $post->plate, 'driver_id' => $post->driver_id]);
                }
            }

            return $before->name;
        });

        return redirect()->to(route('operations.transport').'#'.($type === 'troncal' ? 'troncales' : 'postas'))
            ->with('status', $extendToOtherAirRoutes ? "Transporte de {$name} actualizado y extendido a los otros aéreos." : "Transporte de {$name} actualizado.");
    }

    public function location(Request $request): RedirectResponse
    {
        OperationAccess::requireSupervisor($request);
        $tenant = OperationAccess::tenant($request);
        $input = $request->validate(['id' => ['nullable', 'integer'], 'provider_id' => ['nullable', 'integer', Rule::exists('MBA_providers', 'id')->where('tenant_id', $tenant)], 'name' => ['required', 'string', 'max:160'], 'address' => ['required', 'string', 'max:255'], 'commune' => ['required', 'string', 'max:150']]);
        DB::transaction(function () use ($input, $tenant, $request): void {
            $before = ! empty($input['id']) ? DB::table('Ope_Ubicaciones')->where(['id' => $input['id'], 'tenant_id' => $tenant])->lockForUpdate()->firstOrFail() : null;
            $data = array_intersect_key($input, array_flip(['provider_id', 'name', 'address', 'commune']));
            $data['tenant_id'] = $tenant;
            $data['updated_at'] = now();
            if ($before) {
                $id = $before->id;
                DB::table('Ope_Ubicaciones')->where('id', $id)->update($data);
            } else {
                $data['created_at'] = now();
                $id = DB::table('Ope_Ubicaciones')->insertGetId($data);
            }
            OperationAccess::audit($tenant, $request->user()->id, 'Guardar ubicación', 'ubicacion', $id, $data, $before);
        });

        return back()->with('status', 'Ubicación guardada. Las guías y salidas existentes conservan sus direcciones originales.');
    }

    public function updateAgency(Request $request, int $agency, OperationWorkflow $workflow): RedirectResponse
    {
        OperationAccess::requireSupervisor($request);
        $tenant = OperationAccess::tenant($request);
        $input = $request->validate([
            'address' => ['required', 'string', 'max:255'],
            'commune' => ['required', 'string', 'max:150'],
        ]);
        if (OperationAccess::key($input['address']) === 'pendiente') {
            throw ValidationException::withMessages(['address' => 'Ingresa la dirección real de entrega de la agencia.']);
        }
        DB::transaction(function () use ($request, $tenant, $agency, $input, $workflow): void {
            $before = DB::table('Ope_Agencias')->where(['tenant_id' => $tenant, 'id' => $agency])->lockForUpdate()->firstOrFail();
            DB::table('Ope_Agencias')->where('id', $agency)->update([...$input, 'updated_at' => now()]);
            OperationAccess::audit($tenant, $request->user()->id, 'Corregir dirección de agencia', 'agencia', $agency, $input, $before);
            $lotIds = DB::table('Ope_Lotes as lot')
                ->join('Ope_Bultos as package', 'package.lot_id', '=', 'lot.id')
                ->where('lot.tenant_id', $tenant)
                ->distinct()->pluck('lot.id');
            foreach ($lotIds as $lotId) {
                $workflow->prepareGuideRoutes($tenant, $lotId);
            }
        });

        return redirect()->route('operations.setup')->with('status', 'Dirección de entrega actualizada para las próximas guías. Las salidas ya programadas conservan su dirección anterior.');
    }

    public function postOrigins(Request $request): View
    {
        $tenant = OperationAccess::tenant($request);

        return view('operations::post-origins', [
            'posts' => DB::table('Ope_Postas')->where('tenant_id', $tenant)->orderBy('post_code')->get(),
            'supervisor' => OperationAccess::supervisor($request),
        ]);
    }

    public function updatePostOrigin(Request $request, int $post, OperationWorkflow $workflow): RedirectResponse
    {
        OperationAccess::requireSupervisor($request);
        $tenant = OperationAccess::tenant($request);
        $input = $request->validate([
            'origin_address' => ['required', 'string', 'max:255'],
            'origin_commune' => ['required', 'string', 'max:150'],
        ]);
        if (OperationAccess::key($input['origin_address']) === 'pendiente') {
            throw ValidationException::withMessages(['origin_address' => 'Ingresa el lugar o dirección real de origen de la posta.']);
        }

        DB::transaction(function () use ($request, $tenant, $post, $input, $workflow): void {
            $before = DB::table('Ope_Postas')->where(['tenant_id' => $tenant, 'id' => $post])->lockForUpdate()->firstOrFail();
            DB::table('Ope_Postas')->where('id', $post)->update([...$input, 'updated_at' => now()]);
            OperationAccess::audit($tenant, $request->user()->id, 'Actualizar origen de posta', 'posta', $post, $input, $before);

            $lotIds = DB::table('Ope_Bultos as package')
                ->join('Ope_Lotes as lot', 'lot.id', '=', 'package.lot_id')
                ->join('PPR_coverages as coverage', 'coverage.id', '=', 'package.coverage_id')
                ->join('Ope_Agencias as agency', function ($join): void {
                    $join->on('agency.agency_code', '=', 'coverage.ID_ComunaMatrizAgencia')
                        ->on('agency.tenant_id', '=', 'coverage.tenant_id');
                })
                ->where('lot.tenant_id', $tenant)
                ->where(function ($query) use ($post): void {
                    $query->where('agency.post_id', $post)->orWhere('agency.second_post_id', $post);
                })
                ->distinct()->pluck('lot.id');
            foreach ($lotIds as $lotId) {
                $workflow->prepareGuideRoutes($tenant, $lotId);
            }
        });

        return redirect()->to(route('operations.post-origins').'#posta-'.$post)
            ->with('status', 'Origen de posta guardado. Se actualizaron las salidas pendientes; las guías aprobadas conservan su dirección original.');
    }

    public function configuration(Request $request): RedirectResponse
    {
        OperationAccess::requireSupervisor($request);
        $tenant = OperationAccess::tenant($request);
        $input = $request->validate([
            'agency_id' => ['required', 'integer', Rule::exists('Ope_Agencias', 'id')->where('tenant_id', $tenant)->where('is_active', true)],
            'role' => ['required', Rule::in(['troncal', 'posta1', 'posta2'])],
            'transfer_location_id' => ['nullable', 'integer', Rule::exists('Ope_Ubicaciones', 'id')->where('tenant_id', $tenant)->where('is_active', true)],
            'template' => ['required', 'string', 'max:500'],
            'requires_customer_guide' => ['nullable', 'boolean'],
        ]);
        $sequence = ['troncal' => 1, 'posta1' => 2, 'posta2' => 3][$input['role']];
        DB::transaction(function () use ($input, $sequence, $tenant, $request): void {
            $agency = DB::table('Ope_Agencias')->where(['tenant_id' => $tenant, 'id' => $input['agency_id'], 'is_active' => true])->lockForUpdate()->firstOrFail();
            $coverages = DB::table('PPR_coverages')
                ->where(['tenant_id' => $tenant, 'ID_ComunaMatrizAgencia' => $agency->agency_code, 'is_active' => true])
                ->lockForUpdate()
                ->get(['id']);
            if ($coverages->isEmpty()) {
                throw ValidationException::withMessages(['agency_id' => 'Esta agencia aún no tiene coberturas activas asociadas.']);
            }
            $planned = DB::table('Ope_GuiaConfiguraciones')
                ->where('tenant_id', $tenant)
                ->where('sequence', $sequence)
                ->whereNotNull('group_code')
                ->whereIn('coverage_id', $coverages->pluck('id'))
                ->get();
            if ($planned->count() === $coverages->count()) {
                foreach ($planned as $configuration) {
                    $after = [
                        'template' => $input['template'],
                        'requires_customer_guide' => $request->boolean('requires_customer_guide'),
                        'version' => $configuration->version + 1,
                        'updated_at' => now(),
                    ];
                    DB::table('Ope_GuiaConfiguraciones')->where('id', $configuration->id)->update($after);
                    OperationAccess::audit($tenant, $request->user()->id, 'Actualizar formato de guía', 'configuracion', $configuration->id, $after, $configuration);
                }

                return;
            }
            if ($sequence === 3 && $agency->second_post_id === null) {
                throw ValidationException::withMessages(['role' => 'Esta agencia no tiene Posta 2 asignada.']);
            }
            if (($sequence === 3 || ($sequence === 2 && $agency->second_post_id === null)) && OperationAccess::key($agency->address) === 'pendiente') {
                throw ValidationException::withMessages(['agency_id' => 'Corrige la dirección de entrega de esta agencia antes de configurar su tramo final.']);
            }
            $trunk = DB::table('Ope_Troncales')->where(['tenant_id' => $tenant, 'id' => $agency->trunk_id, 'is_active' => true])->firstOrFail();
            $firstPost = DB::table('Ope_Postas')->where(['tenant_id' => $tenant, 'id' => $agency->post_id])->firstOrFail();
            if ($sequence === 3) {
                DB::table('Ope_Postas')->where(['tenant_id' => $tenant, 'id' => $agency->second_post_id])->firstOrFail();
            }
            $originId = $sequence === 1
                ? $this->catalogLocation($tenant, $trunk->name.' · origen', $trunk->origin_address, $trunk->origin_commune)
                : null;
            if ($sequence === 1) {
                $destinationId = $this->catalogLocation($tenant, $trunk->name.' · destino', $trunk->destination_address, $trunk->destination_commune);
            } elseif ($sequence === 2 && $agency->second_post_id !== null) {
                if (empty($input['transfer_location_id'])) {
                    throw ValidationException::withMessages(['transfer_location_id' => 'Selecciona el punto donde '.$firstPost->name.' entrega a la Posta 2. Si falta, regístralo en Ubicaciones.']);
                }
                $destinationId = (int) $input['transfer_location_id'];
            } else {
                $destinationId = $this->catalogLocation($tenant, $agency->name, $agency->address, $agency->commune);
            }
            foreach ($coverages as $coverage) {
                $before = DB::table('Ope_GuiaConfiguraciones')->where(['coverage_id' => $coverage->id, 'sequence' => $sequence])->first();
                if ($before?->group_code !== null) {
                    $after = [
                        'template' => $input['template'],
                        'requires_customer_guide' => $request->boolean('requires_customer_guide'),
                        'version' => $before->version + 1,
                        'updated_at' => now(),
                    ];
                    DB::table('Ope_GuiaConfiguraciones')->where('id', $before->id)->update($after);
                    OperationAccess::audit($tenant, $request->user()->id, 'Actualizar formato de guía', 'configuracion', $before->id, $after, $before);

                    continue;
                }
                $previous = $sequence > 1 ? DB::table('Ope_GuiaConfiguraciones')->where(['coverage_id' => $coverage->id, 'sequence' => $sequence - 1])->first() : null;
                if ($sequence > 1 && ! $previous) {
                    throw ValidationException::withMessages(['role' => 'Configura primero el tramo anterior de todas las coberturas de esta agencia.']);
                }
                $currentOriginId = $sequence === 1 ? $originId : $previous->destination_id;
                $next = DB::table('Ope_GuiaConfiguraciones')->where(['coverage_id' => $coverage->id, 'sequence' => $sequence + 1])->first();
                if ($next && $next->origin_id !== $destinationId) {
                    throw ValidationException::withMessages(['role' => 'El destino cambiaría el origen de la posta siguiente. Revisa primero su configuración.']);
                }
                $data = [
                    'tenant_id' => $tenant,
                    'coverage_id' => $coverage->id,
                    'sequence' => $sequence,
                    'role' => $input['role'],
                    'name' => $agency->name,
                    'origin_id' => $currentOriginId,
                    'destination_id' => $destinationId,
                    'template' => $input['template'],
                    'requires_customer_guide' => $request->boolean('requires_customer_guide'),
                    'version' => ($before?->version ?? 0) + 1,
                    'updated_at' => now(),
                ];
                if ($before) {
                    $id = $before->id;
                    DB::table('Ope_GuiaConfiguraciones')->where('id', $id)->update($data);
                } else {
                    $data['created_at'] = now();
                    $id = DB::table('Ope_GuiaConfiguraciones')->insertGetId($data);
                }
                OperationAccess::audit($tenant, $request->user()->id, 'Configurar tramo', 'configuracion', $id, $data, $before);
            }
        });

        return back()->with('status', 'Recorrido de la agencia guardado para todas sus coberturas activas.');
    }

    private function catalogLocation(int $tenant, string $name, string $address, string $commune): int
    {
        $existing = DB::table('Ope_Ubicaciones')
            ->where(['tenant_id' => $tenant, 'address' => $address, 'commune' => $commune, 'is_active' => true])
            ->value('id');

        return $existing ?: DB::table('Ope_Ubicaciones')->insertGetId([
            'tenant_id' => $tenant,
            'name' => $name,
            'address' => $address,
            'commune' => $commune,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
