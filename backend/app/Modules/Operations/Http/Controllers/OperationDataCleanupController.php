<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\OperationAccess;
use App\Modules\Operations\Services\OperationDataCleaner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OperationDataCleanupController extends Controller
{
    public function index(Request $request, OperationDataCleaner $cleaner): View
    {
        OperationAccess::requireSupervisor($request);
        $tenant = OperationAccess::tenant($request);
        $plan = null;
        if ($request->filled('source')) {
            $input = $this->selection($request);
            $plan = $cleaner->plan($tenant, $input['source'], $input['selector'], $input['value']);
        }

        return view('operations::data-cleanup', [
            'plan' => $plan,
            'processes' => DB::table('Ope_Lotes')->where('tenant_id', $tenant)->orderByDesc('id')->get(['id', 'name', 'operation_date']),
            'excelLoads' => DB::table('Ope_Cargas')->where(['tenant_id' => $tenant, 'source_type' => 'reception'])
                ->where('path', 'like', 'operations/'.$tenant.'/%')->orderByDesc('id')->get(['id', 'filename', 'row_count', 'created_at']),
            'masterLoads' => DB::table('Ope_Cargas')->where(['tenant_id' => $tenant, 'source_type' => 'master'])
                ->orderByDesc('id')->get(['id', 'filename', 'row_count', 'created_at']),
            'systemCount' => DB::table('Ope_RecepcionesSistema')->where('tenant_id', $tenant)->count(),
        ]);
    }

    public function destroy(Request $request, OperationDataCleaner $cleaner): RedirectResponse
    {
        OperationAccess::requireSupervisor($request);
        $input = $this->selection($request);
        $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
            'confirm' => ['accepted'],
        ]);
        $plan = $cleaner->clean(
            OperationAccess::tenant($request), $request->user()->id, $input['source'], $input['selector'], $input['value'],
            $request->string('fingerprint')->toString(),
        );
        $message = 'Datos limpiados: '.$plan['counts']['loads'].' cargas, '.$plan['counts']['system']
            .' recepciones del sistema y '.$plan['counts']['processes'].' procesos relacionados.';
        if (! $plan['files_removed']) {
            $message .= ' Algunos archivos no pudieron retirarse del almacenamiento; revísalos.';
        }

        return redirect()->route('operations.cleanup.index')->with('status', $message);
    }

    private function selection(Request $request): array
    {
        $input = $request->validate([
            'source' => ['required', Rule::in(['system', 'excel', 'master'])],
            'selector' => ['required', Rule::in(['process', 'date', 'load', 'all'])],
            'value' => ['required', 'string', 'max:30'],
        ]);
        $valid = match ($input['source']) {
            'system' => in_array($input['selector'], ['process', 'date'], true),
            'excel', 'master' => in_array($input['selector'], ['load', 'all'], true),
        };
        if (! $valid || ($input['selector'] === 'all' && $input['value'] !== 'all')
            || (in_array($input['selector'], ['load', 'process'], true) && ! ctype_digit($input['value']))
            || ($input['selector'] === 'date' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $input['value']))) {
            throw ValidationException::withMessages(['selection' => 'Selecciona una fuente y un criterio válidos.']);
        }

        return $input;
    }
}
