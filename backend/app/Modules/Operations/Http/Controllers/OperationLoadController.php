<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\OperationAccess;
use App\Modules\Operations\Services\OperationImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class OperationLoadController extends Controller
{
    public function index(Request $request, string $type): View
    {
        abort_unless(in_array($type, ['master', 'reception'], true), 404);

        return view('operations::loads', ['type' => $type, 'loads' => DB::table('Ope_Cargas')->where(['tenant_id' => OperationAccess::tenant($request), 'source_type' => $type])->orderByDesc('id')->paginate(20)]);
    }

    public function store(Request $request, string $type, OperationImporter $importer): RedirectResponse
    {
        abort_unless(in_array($type, ['master', 'reception'], true), 404);
        $input = $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'extensions:xlsx', 'max:65536'], 'sheet' => ['required', 'string', 'max:80'], 'profile' => [$type === 'reception' ? 'required' : 'nullable', Rule::in(['legacy', 'custom'])], 'date' => ['nullable', 'regex:/^[A-Z]{1,2}$/D'], 'tracking' => ['nullable', 'regex:/^[A-Z]{1,2}$/D'], 'weight' => ['nullable', 'regex:/^[A-Z]{1,2}$/D'], 'operator' => ['nullable', 'regex:/^[A-Z]{1,2}$/D'], 'customer_guide' => ['nullable', 'regex:/^[A-Z]{1,2}$/D'], 'reference' => ['nullable', 'regex:/^[A-Z]{1,2}$/D']]);
        $mapping = $type === 'master' ? [] : array_intersect_key($input, array_flip(['profile', 'date', 'tracking', 'weight', 'operator', 'customer_guide', 'reference']));
        if ($type === 'reception' && ($input['profile'] ?? 'legacy') === 'legacy') {
            $mapping = ['profile' => 'legacy', 'date' => 'A', 'tracking' => 'E', 'weight' => 'F', 'operator' => 'H', 'customer_guide' => $input['customer_guide'] ?? null, 'reference' => 'I'];
        }
        $file = $request->file('file');
        $path = $file->store('operations/'.OperationAccess::tenant($request), 'local');
        try {
            $id = $importer->enqueue(Storage::disk('local')->path($path), $path, $file->getClientOriginalName(), OperationAccess::tenant($request), $request->user()->id, $type, $input['sheet'], $mapping);
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        if (DB::table('Ope_Cargas')->where('id', $id)->value('path') !== $path) {
            Storage::disk('local')->delete($path);
        }

        return redirect()->route('operations.loads.show', $id)->with('status', 'Archivo recibido. La importación se procesa en segundo plano; revisa su estado antes de preparar el proceso.');
    }

    public function show(Request $request, int $load): View
    {
        $record = DB::table('Ope_Cargas')->where(['tenant_id' => OperationAccess::tenant($request), 'id' => $load])->firstOrFail();
        $query = DB::table('Ope_FilasFuente')->where('load_id', $load);
        if ($request->boolean('errors')) {
            $query->where('errors', '<>', '[]');
        }

        return view('operations::load-show', ['load' => $record, 'rows' => $query->orderBy('line')->paginate(40)->withQueryString()]);
    }
}
