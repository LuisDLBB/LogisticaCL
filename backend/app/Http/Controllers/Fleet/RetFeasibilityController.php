<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\CoordinationService;
use App\Fleet\FleetAccess;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientBranch;
use App\Models\RetFeasibility;
use App\Models\ServiceType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RetFeasibilityController extends Controller
{
    public const REQUEST_FIELDS = [
        'pickup_name' => 'Nombre del retiro', 'material' => 'Descripción de la carga', 'packages' => 'Bultos',
        'weight_kg' => 'Peso (kg)', 'dimensions' => 'Dimensiones (indicar unidad)', 'origin' => 'Origen', 'destination' => 'Destino',
        'service_date' => 'Fecha solicitada', 'deadline' => 'Plazo', 'window_start' => 'Ventana desde', 'window_end' => 'Ventana hasta',
        'restrictions' => 'Restricciones y requisitos especiales', 'notes' => 'Observaciones',
    ];

    public const OPERATION_FIELDS = [
        'km' => 'Kilómetros estimados', 'hours' => 'Horas estimadas', 'vehicle' => 'Vehículo requerido',
        'own_drivers' => 'Conductores propios', 'external_drivers' => 'Conductores externos',
        'own_helpers' => 'Peonetas propios', 'external_helpers' => 'Peonetas externos',
        'allowances_detail' => 'Detalle de viáticos', 'tolls' => 'TAG y peajes', 'loading' => 'Carga y descarga',
        'contingencies' => 'Contingencias', 'availability' => 'Disponibilidad y restricciones', 'conditions' => 'Condiciones operativas',
        'estimated_cost' => 'Costo estimado (CLP, sin revisión financiera)',
    ];

    public const COSTS = ['driver' => 'Conductor', 'helpers' => 'Peonetas', 'fuel' => 'Combustible', 'tolls' => 'TAG/peajes', 'vehicle' => 'Vehículo', 'insurance' => 'Seguros', 'depreciation' => 'Depreciación', 'maintenance' => 'Mantención', 'external' => 'Costos externos'];

    public const REASONS = ['Sin cobertura', 'Capacidad vehículo', 'Peso/volumen', 'Ventana', 'Restricción acceso', 'Disponibilidad flota', 'Plazo', 'Condiciones de carga', 'Riesgo', 'Costo no viable', 'Otro'];

    public const STATES = ['operations' => 'Evaluación de Operaciones', 'finance' => 'Revisión de Finanzas', 'cost_validation' => 'Validación de costos', 'commercial' => 'Propuesta comercial', 'approval' => 'Aprobación de Gerencia Comercial', 'approved' => 'Aprobación final', 'rejected' => 'Rechazado', 'correction' => 'Corrección solicitada', 'converted' => 'En Coordinación'];

    public function index(Request $request, FleetAccess $access): View
    {
        $this->authorizeArea($request, $access);
        $tenant = $access->tenant($request);
        $rows = RetFeasibility::with('client')->where('tenant_id', $tenant->id)->latest('id')->paginate(30);
        $clients = Client::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('commercial_name')->get();
        $types = ServiceType::where('is_active', true)->get();
        $canCreate = $this->canCreate($request, $access);

        return view('fleet::feasibility.index', compact('rows', 'clients', 'types', 'canCreate'));
    }

    public function store(Request $request, FleetAccess $access): RedirectResponse
    {
        abort_unless($this->canCreate($request, $access), 403);
        $data = $this->requestData($request);
        $client = Client::where('tenant_id', $access->tenant($request)->id)->where('is_active', true)->findOrFail($request->integer('client_id'));
        ServiceType::where('is_active', true)->findOrFail($request->integer('service_type_id'));
        $row = DB::transaction(function () use ($request, $client, $data): RetFeasibility {
            $row = RetFeasibility::create(['tenant_id' => $client->tenant_id, 'client_id' => $client->id, 'service_type_id' => $request->integer('service_type_id'), 'created_by_user_id' => $request->user()->id, 'requires_finance' => $request->boolean('requires_finance'), 'request_data' => $data, 'original_request_data' => $data, 'status' => 'operations', 'is_test' => $this->testFlag($request)]);
            $this->event($row, $request, 'created', $data);

            return $row;
        });

        return redirect()->route('fleet.feasibility.show', $row);
    }

    public function show(Request $request, RetFeasibility $feasibility, FleetAccess $access): View
    {
        $this->authorizeArea($request, $access, $feasibility);
        $events = DB::table('ret_feasibility_events')->where('ret_feasibility_id', $feasibility->id)->orderBy('id')->get();
        $points = ClientBranch::where('client_id', $feasibility->client_id)->where('is_active', true)->get();
        $permissions = [];
        foreach (['operations.feasibility', 'finance.feasibility', 'finance.feasibility.validate', 'commercial.feasibility', 'commercial.feasibility.approve', 'coordination.requests'] as $scope) {
            $permissions[$scope] = $access->allows($request->user(), $access->tenant($request), $scope, str_ends_with($scope, '.approve') || str_ends_with($scope, '.validate') ? 3 : 2);
        }
        $canCreate = $this->canCreate($request, $access);

        return view('fleet::feasibility.show', compact('feasibility', 'events', 'points', 'permissions', 'canCreate'));
    }

    public function act(Request $request, RetFeasibility $feasibility, FleetAccess $access, CoordinationService $coordination): RedirectResponse
    {
        $this->authorizeArea($request, $access, $feasibility);
        $action = $request->validate(['action' => ['required', 'in:operations,finance,validate,commercial,approve,reject,correction,resubmit,convert']])['action'];
        DB::transaction(function () use ($request, $feasibility, $access, $coordination, $action): void {
            $row = RetFeasibility::lockForUpdate()->findOrFail($feasibility->id);
            $before = $row->toArray();
            $stageScopes = ['operations' => 'operations.feasibility', 'finance' => 'finance.feasibility', 'cost_validation' => 'finance.feasibility.validate', 'commercial' => 'commercial.feasibility', 'approval' => 'commercial.feasibility.approve', 'approved' => 'coordination.requests'];
            if ($action === 'resubmit') {
                abort_unless($row->status === 'correction' && $this->canCreate($request, $access), 403);
                $data = $this->requestData($request, false);
                $request->validate(['reason' => ['required', 'string', 'max:2000']]);
                $row->update(['request_data' => $data, 'requires_finance' => $request->boolean('requires_finance'), 'status' => 'operations', 'operational_result' => null, 'operations_data' => null, 'finance_data' => null, 'proposed_price' => null]);
            } else {
                $scope = $stageScopes[$row->status] ?? null;
                abort_unless($scope && $access->allows($request->user(), $access->tenant($request), $scope, in_array($row->status, ['cost_validation', 'approval']) ? 3 : 2), 403);
                if (in_array($action, ['reject', 'correction'])) {
                    abort_if(in_array($row->status, ['approved', 'converted']), 409);
                    $request->validate(['reason' => ['required', 'string', 'max:2000']]);
                    $row->update(['status' => $action === 'reject' ? 'rejected' : 'correction']);
                } elseif ($action === 'operations' && $row->status === 'operations') {
                    $rules = ['result' => ['required', 'in:Factible,Factible con condiciones,No factible'], 'allowances' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:2000'], 'reason_code' => ['nullable', Rule::in(self::REASONS)]];
                    foreach (self::OPERATION_FIELDS as $key => $label) {
                        $rules[$key] = ['nullable', 'string', 'max:2000'];
                    }
                    foreach (['km', 'hours', 'own_drivers', 'external_drivers', 'own_helpers', 'external_helpers', 'estimated_cost'] as $key) {
                        $rules[$key] = ['nullable', 'numeric', 'min:0', 'max:999999999'];
                    }
                    $rules['conditions'][] = 'required_if:result,Factible con condiciones';
                    $rules['allowances_detail'][] = 'required_if:allowances,1';
                    $rules['reason'][] = 'required_if:result,No factible';
                    if (! $row->requires_finance && $request->input('result') !== 'No factible') {
                        $rules['estimated_cost'][] = 'required';
                    }
                    $data = $request->validate($rules);
                    $row->update(['operations_data' => $data, 'original_operations_data' => $row->original_operations_data ?? $data, 'operational_result' => $data['result'], 'status' => $data['result'] === 'No factible' ? 'rejected' : ($row->requires_finance ? 'finance' : 'commercial')]);
                } elseif ($action === 'finance' && $row->status === 'finance') {
                    $rules = ['reason' => ['required', 'string', 'max:2000']];
                    foreach (self::COSTS as $key => $label) {
                        $rules[$key] = ['required', 'numeric', 'min:0', 'max:999999999'];
                    }
                    $data = $request->validate($rules);
                    $row->update(['finance_data' => $data, 'original_finance_data' => $row->original_finance_data ?? $data, 'status' => 'cost_validation']);
                } elseif ($action === 'validate' && $row->status === 'cost_validation') {
                    $request->validate(['reason' => ['required', 'string', 'max:2000']]);
                    $row->update(['status' => 'commercial']);
                } elseif ($action === 'commercial' && $row->status === 'commercial') {
                    $data = $request->validate(['proposed_price' => ['required', 'numeric', 'min:0.01', 'max:999999999'], 'reason' => ['required', 'string', 'max:2000']]);
                    $row->update(['proposed_price' => $data['proposed_price'], 'status' => 'approval']);
                } elseif ($action === 'approve' && $row->status === 'approval') {
                    $data = $request->validate(['proposed_price' => ['required', 'numeric', 'min:0.01', 'max:999999999'], 'reason' => ['required', 'string', 'max:2000']]);
                    $row->update(['proposed_price' => $data['proposed_price'], 'status' => 'approved']);
                } elseif ($action === 'convert' && $row->status === 'approved') {
                    abort_if($row->service_request_id !== null, 409);
                    $data = $request->validate(['client_branch_id' => ['required', 'integer'], 'service_date' => ['required', 'date_format:Y-m-d'], 'window_start' => ['required', 'date_format:H:i'], 'window_end' => ['required', 'date_format:H:i', 'after:window_start'], 'shift' => ['required', 'in:AM,PM'], 'packages' => ['required', 'integer', 'min:1'], 'material' => ['required', 'string', 'max:160'], 'reason' => ['required', 'string', 'max:2000']]);
                    $branch = ClientBranch::where('client_id', $row->client_id)->where('is_active', true)->findOrFail($data['client_branch_id']);
                    $ret = $coordination->createRequest($request->user(), $row->client, $branch, $row->service_type_id, $data + ['requested_weight_kg' => $row->request_data['weight_kg'] ?? null, 'is_test' => $row->is_test, 'pickup_name' => $row->request_data['pickup_name'] ?? null, 'notes' => 'Factibilidad #'.$row->id.'. Condiciones: '.($row->operations_data['conditions'] ?? '').'. Destino: '.($row->request_data['destination'] ?? '')]);
                    $row->update(['service_request_id' => $ret->id, 'status' => 'converted']);
                    $coordination->event($ret, $request->user(), 'feasibility_linked', ['feasibility_id' => $row->id]);
                } else {
                    abort(409, 'La acción no corresponde a la etapa vigente.');
                }
            }
            $this->event($row, $request, $action, ['before' => $before, 'after' => $row->fresh()->toArray(), 'reason' => $request->input('reason')]);
        }, 3);

        return back()->with('status', 'Expediente actualizado.');
    }

    public function recognize(Request $request, FleetAccess $access): JsonResponse
    {
        abort_unless($this->canCreate($request, $access), 403);
        $text = $request->validate(['text' => ['required', 'string', 'max:15000']])['text'];
        $values = [];
        $ambiguous = [];
        $labels = ['pickup_name' => 'nombre(?: del retiro)?', 'material' => 'carga|material', 'packages' => 'bultos', 'weight_kg' => 'peso(?: en kg)?', 'dimensions' => 'dimensiones|medidas', 'origin' => 'origen', 'destination' => 'destino', 'service_date' => 'fecha', 'window_start' => 'desde', 'window_end' => 'hasta', 'deadline' => 'plazo', 'restrictions' => 'restricciones', 'notes' => 'observaciones'];
        foreach ($labels as $key => $label) {
            preg_match_all('/^(?:'.$label.')\s*:\s*(.+)$/imu', $text, $matches);
            $found = array_unique(array_map('trim', $matches[1]));
            if (count($found) !== 1) {
                if (count($found) > 1) {
                    $ambiguous[] = $key;
                }

                continue;
            }
            $value = array_values($found)[0];
            if ($key === 'packages' && ! preg_match('/^\d+$/', $value)) {
                $ambiguous[] = $key;

                continue;
            }
            if ($key === 'weight_kg') {
                if (! preg_match('/^(\d+(?:[.,]\d+)?)\s*kg$/i', $value, $weight)) {
                    $ambiguous[] = $key;

                    continue;
                }
                $value = str_replace(',', '.', $weight[1]);
            }
            if ($key === 'service_date' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $ambiguous[] = $key;

                continue;
            }
            if (in_array($key, ['window_start', 'window_end']) && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
                $ambiguous[] = $key;

                continue;
            }
            $values[$key] = $value;
        }

        return response()->json(['values' => $values, 'ambiguous' => $ambiguous, 'missing' => array_values(array_diff(array_keys(self::REQUEST_FIELDS), array_keys($values)))]);
    }

    private function requestData(Request $request, bool $ids = true): array
    {
        $rules = ['requires_finance' => ['required', 'boolean'], 'is_test' => ['nullable', 'boolean']];
        if ($ids) {
            $rules += ['client_id' => ['required', 'integer'], 'service_type_id' => ['required', 'integer']];
        }
        foreach (self::REQUEST_FIELDS as $key => $label) {
            $rules[$key] = ['nullable', 'string', 'max:2000'];
        }
        $rules['material'] = ['required', 'string', 'max:160'];
        $rules['packages'] = ['nullable', 'integer', 'min:1'];
        $rules['weight_kg'] = ['nullable', 'numeric', 'min:0'];
        $rules['service_date'] = ['nullable', 'date_format:Y-m-d'];
        $rules['window_start'] = ['nullable', 'date_format:H:i'];
        $rules['window_end'] = ['nullable', 'date_format:H:i', 'after:window_start'];
        $data = $request->validate($rules);

        return array_intersect_key($data, self::REQUEST_FIELDS);
    }

    private function authorizeArea(Request $request, FleetAccess $access, ?RetFeasibility $row = null): void
    {
        $tenant = $access->tenant($request);
        abort_if($row && $row->tenant_id !== $tenant->id, 404);
        foreach (['coordination.feasibility', 'commercial.feasibility', 'finance.feasibility', 'operations.feasibility', 'finance.feasibility.validate', 'commercial.feasibility.approve'] as $scope) {
            if ($access->allows($request->user(), $tenant, $scope)) {
                return;
            }
        }
        abort(403);
    }

    private function canCreate(Request $request, FleetAccess $access): bool
    {
        return $access->allows($request->user(), $access->tenant($request), 'coordination.feasibility', 2) || $access->allows($request->user(), $access->tenant($request), 'commercial.feasibility', 2);
    }

    private function testFlag(Request $request): bool
    {
        return app()->environment(['local', 'testing']) && config('fleet.test_tools_enabled') && (config('fleet.auto_test_mode') || $request->boolean('is_test'));
    }

    private function event(RetFeasibility $row, Request $request, string $action, array $details): void
    {
        DB::table('ret_feasibility_events')->insert(['ret_feasibility_id' => $row->id, 'actor_user_id' => $request->user()->id, 'action' => $action, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
