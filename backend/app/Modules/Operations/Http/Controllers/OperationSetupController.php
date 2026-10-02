<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\OperationAccess;
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

        $selected = $request->filled('configuration') ? DB::table('Ope_GuiaConfiguraciones')->where(['tenant_id' => $tenant, 'id' => $request->integer('configuration')])->firstOrFail() : null;

        return view('operations::setup', ['selected' => $selected, 'locations' => DB::table('Ope_Ubicaciones')->where('tenant_id', $tenant)->orderBy('name')->get(), 'coverages' => DB::table('PPR_coverages')->where(['tenant_id' => $tenant, 'is_active' => true])->orderBy('commune_name')->get(), 'providers' => DB::table('MBA_providers')->where(['tenant_id' => $tenant, 'is_active' => true])->orderBy('legal_name')->get(['id', 'legal_name', 'operational_name']), 'configurations' => DB::table('Ope_GuiaConfiguraciones as c')->join('PPR_coverages as v', 'v.id', '=', 'c.coverage_id')->join('Ope_Ubicaciones as o', 'o.id', '=', 'c.origin_id')->join('Ope_Ubicaciones as d', 'd.id', '=', 'c.destination_id')->where('c.tenant_id', $tenant)->orderBy('v.commune_name')->orderBy('c.sequence')->select('c.*', 'v.commune_name', 'v.trunk_name', 'v.post_name', 'o.name as origin_name', 'd.name as destination_name')->get()]);
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

    public function configuration(Request $request): RedirectResponse
    {
        OperationAccess::requireSupervisor($request);
        $tenant = OperationAccess::tenant($request);
        $location = Rule::exists('Ope_Ubicaciones', 'id')->where('tenant_id', $tenant)->where('is_active', true);
        $input = $request->validate(['coverage_id' => ['required', 'integer', Rule::exists('PPR_coverages', 'id')->where('tenant_id', $tenant)->where('is_active', true)], 'role' => ['required', Rule::in(['troncal', 'posta1', 'posta2'])], 'name' => ['required', 'string', 'max:160'], 'origin_id' => ['required', 'integer', $location], 'destination_id' => ['required', 'integer', 'different:origin_id', clone $location], 'template' => ['required', 'string', 'max:500'], 'requires_customer_guide' => ['nullable', 'boolean']]);
        $sequence = ['troncal' => 1, 'posta1' => 2, 'posta2' => 3][$input['role']];
        DB::transaction(function () use ($input, $sequence, $tenant, $request): void {
            DB::table('PPR_coverages')->where('id', $input['coverage_id'])->lockForUpdate()->firstOrFail();
            $before = DB::table('Ope_GuiaConfiguraciones')->where(['coverage_id' => $input['coverage_id'], 'sequence' => $sequence])->first();
            $previous = $sequence > 1 ? DB::table('Ope_GuiaConfiguraciones')->where(['coverage_id' => $input['coverage_id'], 'sequence' => $sequence - 1])->first() : null;
            if ($sequence > 1 && (! $previous || $previous->destination_id !== (int) $input['origin_id'])) {
                throw ValidationException::withMessages(['origin_id' => 'El origen debe coincidir con el destino del tramo anterior. Configura primero ese tramo.']);
            }
            $next = DB::table('Ope_GuiaConfiguraciones')->where(['coverage_id' => $input['coverage_id'], 'sequence' => $sequence + 1])->first();
            if ($next && $next->origin_id !== (int) $input['destination_id']) {
                throw ValidationException::withMessages(['destination_id' => 'El destino debe coincidir con el origen de la posta siguiente.']);
            }
            $data = array_intersect_key($input, array_flip(['coverage_id', 'role', 'name', 'origin_id', 'destination_id', 'template']));
            $data += ['tenant_id' => $tenant, 'sequence' => $sequence, 'requires_customer_guide' => $request->boolean('requires_customer_guide'), 'version' => ($before?->version ?? 0) + 1, 'updated_at' => now()];
            if ($before) {
                $id = $before->id;
                DB::table('Ope_GuiaConfiguraciones')->where('id', $id)->update($data);
            } else {
                $data['created_at'] = now();
                $id = DB::table('Ope_GuiaConfiguraciones')->insertGetId($data);
            }
            OperationAccess::audit($tenant, $request->user()->id, 'Configurar tramo', 'configuracion', $id, $data, $before);
        });

        return back()->with('status', 'Configuración guardada para las próximas salidas.');
    }
}
