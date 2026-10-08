<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\OperationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class OperationSystemReceptionController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = OperationAccess::tenant($request);

        return view('operations::system-receptions', [
            'clients' => DB::table('MBA_clients')->where(['tenant_id' => $tenant, 'is_active' => true])->orderBy('commercial_name')->get(['id', 'commercial_name', 'legal_name', 'tax_id']),
            'receptions' => DB::table('Ope_RecepcionesSistema as reception')
                ->join('MBA_users as user', 'user.id', '=', 'reception.user_id')
                ->join('MBA_clients as client', 'client.id', '=', 'reception.client_id')
                ->where('reception.tenant_id', $tenant)
                ->orderByDesc('reception.id')
                ->select('reception.*', 'user.name as user_name', 'client.commercial_name as client_name')
                ->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = OperationAccess::tenant($request);
        $input = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('MBA_clients', 'id')->where('tenant_id', $tenant)->where('is_active', true)],
            'document_type' => ['required', Rule::in(['guia', 'factura'])],
            'document_number' => ['required', 'string', 'max:100'],
            'observations' => ['nullable', 'string', 'max:2000'],
        ]);
        $input['document_number'] = trim($input['document_number']);
        if ($input['document_number'] === '') {
            throw ValidationException::withMessages(['document_number' => 'Ingresa el número de guía o factura.']);
        }

        $id = DB::transaction(function () use ($tenant, $request, $input): int {
            $id = DB::table('Ope_RecepcionesSistema')->insertGetId([
                'tenant_id' => $tenant, 'user_id' => $request->user()->id,
                'client_id' => $input['client_id'], 'document_type' => $input['document_type'],
                'document_number' => $input['document_number'],
                'observations' => trim((string) ($input['observations'] ?? '')) ?: null,
                'status' => 'awaiting_photo', 'created_at' => now(), 'updated_at' => now(),
            ]);
            OperationAccess::audit($tenant, $request->user()->id, 'Crear recepción sistema', 'recepcion_sistema', $id, $input);

            return $id;
        });

        return redirect()->route('operations.system-receptions.show', $id)
            ->with('status', 'Recepción creada. Adjunta la fotografía de la guía o factura para comenzar a escanear.');
    }

    public function show(Request $request, int $reception): View
    {
        $record = $this->reception($request, $reception);

        return view('operations::system-reception-show', [
            'reception' => $record,
            'creator' => DB::table('MBA_users')->where('id', $record->user_id)->value('name'),
            'client' => DB::table('MBA_clients')->where('id', $record->client_id)->first(['commercial_name', 'legal_name', 'tax_id']),
            'scans' => DB::table('Ope_RecepcionSistemaBultos')->where('reception_id', $reception)->orderByDesc('sequence')->limit(100)->get(),
            'scanCount' => DB::table('Ope_RecepcionSistemaBultos')->where('reception_id', $reception)->count(),
        ]);
    }

    public function updateQrFormat(Request $request, int $reception): RedirectResponse
    {
        $tenant = OperationAccess::tenant($request);
        $input = $request->validate([
            'qr_start_position' => ['required', 'integer', 'min:1', 'max:500'],
            'qr_length' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        DB::transaction(function () use ($request, $tenant, $reception, $input): void {
            $record = DB::table('Ope_RecepcionesSistema')->where(['tenant_id' => $tenant, 'id' => $reception])->lockForUpdate()->firstOrFail();
            abort_if($record->status === 'completed', 409, 'La recepción está cerrada.');
            if (DB::table('Ope_RecepcionSistemaBultos')->where('reception_id', $reception)->exists()) {
                throw ValidationException::withMessages(['qr_start_position' => 'Define el tramo del QR antes del primer escaneo para mantener todos los códigos comparables.']);
            }
            DB::table('Ope_RecepcionesSistema')->where('id', $reception)->update([...$input, 'updated_at' => now()]);
            OperationAccess::audit($tenant, $request->user()->id, 'Configurar lectura QR', 'recepcion_sistema', $reception, $input);
        });

        return back()->with('status', 'Tramo útil del QR guardado. La detección de duplicados usará ese código.');
    }

    public function uploadPhoto(Request $request, int $reception): RedirectResponse
    {
        $record = $this->reception($request, $reception);
        abort_if($record->status === 'completed', 409, 'La recepción está cerrada.');
        $request->validate(['photo' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif', 'max:15360']]);

        $tenant = OperationAccess::tenant($request);
        $path = $request->file('photo')->store("operations/{$tenant}/system-receptions/{$reception}", 'local');
        try {
            DB::transaction(function () use ($request, $reception, $tenant, $path, $record): void {
                DB::table('Ope_RecepcionesSistema')->where('id', $reception)->update(['photo_path' => $path, 'status' => 'scanning', 'updated_at' => now()]);
                OperationAccess::audit($tenant, $request->user()->id, 'Adjuntar respaldo', 'recepcion_sistema', $reception, ['photo_path' => $path], ['photo_path' => $record->photo_path]);
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        if ($record->photo_path) {
            Storage::disk('local')->delete($record->photo_path);
        }

        return redirect()->to(route('operations.system-receptions.show', $reception).'?scan=1')
            ->with('status', 'Respaldo guardado. Ya puedes escanear los bultos.');
    }

    public function photo(Request $request, int $reception): BinaryFileResponse
    {
        $record = $this->reception($request, $reception);
        abort_unless($record->photo_path && Storage::disk('local')->exists($record->photo_path), 404);

        return response()->file(Storage::disk('local')->path($record->photo_path), [
            'Content-Type' => Storage::disk('local')->mimeType($record->photo_path),
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline',
        ]);
    }

    public function checkTracking(Request $request, int $reception): JsonResponse
    {
        $record = $this->reception($request, $reception);
        $this->assertScannable($record);
        [$rawCode, $tracking, $scanSource] = $this->tracking($request, $record);
        $previous = DB::table('Ope_RecepcionSistemaBultos')->where(['reception_id' => $reception, 'tracking' => $tracking])->value('sequence');
        if ($previous !== null) {
            return response()->json(['message' => "Código repetido: ya fue registrado como bulto #{$previous}.", 'duplicate_sequence' => $previous], 409);
        }

        return response()->json([
            'raw_code' => $rawCode, 'tracking' => $tracking,
            'next_number' => (int) DB::table('Ope_RecepcionSistemaBultos')->where('reception_id', $reception)->max('sequence') + 1,
        ]);
    }

    public function scan(Request $request, int $reception): JsonResponse|RedirectResponse
    {
        $tenant = OperationAccess::tenant($request);
        $record = $this->reception($request, $reception);
        $this->assertScannable($record);
        [$rawCode, $tracking, $scanSource] = $this->tracking($request, $record);
        $input = $request->validate([
            'weight' => ['required', 'regex:/^\d{1,9}(?:[.,]\d{1,3})?$/D'],
            'height_cm' => ['required', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'],
            'length_cm' => ['required', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'],
            'width_cm' => ['required', 'regex:/^\d{1,6}(?:[.,]\d{1,2})?$/D'],
        ]);
        foreach ($input as $field => $value) {
            $input[$field] = str_replace(',', '.', $value);
            if ((float) $input[$field] <= 0) {
                throw ValidationException::withMessages([$field => 'El peso y las medidas deben ser mayores que cero.']);
            }
        }

        $result = DB::transaction(function () use ($request, $reception, $tenant, $rawCode, $tracking, $scanSource, $input): array {
            $record = DB::table('Ope_RecepcionesSistema')->where(['tenant_id' => $tenant, 'id' => $reception])->lockForUpdate()->firstOrFail();
            $this->assertScannable($record);
            $previous = DB::table('Ope_RecepcionSistemaBultos')->where(['reception_id' => $reception, 'tracking' => $tracking])->value('sequence');
            if ($previous !== null) {
                return ['duplicate_sequence' => $previous, 'message' => "Código repetido: ya fue registrado como bulto #{$previous}."];
            }
            $sequence = (int) DB::table('Ope_RecepcionSistemaBultos')->where('reception_id', $reception)->max('sequence') + 1;
            DB::table('Ope_RecepcionSistemaBultos')->insert([
                'reception_id' => $reception, 'user_id' => $request->user()->id,
                'sequence' => $sequence, 'tracking' => $tracking, 'raw_code' => $rawCode, 'scan_source' => $scanSource,
                'scanned_on' => now('America/Santiago')->toDateString(), ...$input,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            OperationAccess::audit($tenant, $request->user()->id, 'Escanear bulto', 'recepcion_sistema', $reception, ['sequence' => $sequence, 'tracking' => $tracking, 'raw_code' => $rawCode, 'scan_source' => $scanSource, ...$input]);

            return ['sequence' => $sequence, 'count' => $sequence, 'tracking' => $tracking, ...$input];
        });
        if (isset($result['duplicate_sequence'])) {
            return $request->expectsJson()
                ? response()->json($result, 409)
                : back()->withErrors(['raw_code' => $result['message']]);
        }

        return $request->expectsJson()
            ? response()->json($result, 201)
            : redirect()->to(route('operations.system-receptions.show', $reception).'?scan=1')->with('status', "Bulto #{$result['sequence']} registrado.");
    }

    public function complete(Request $request, int $reception): RedirectResponse
    {
        $tenant = OperationAccess::tenant($request);
        DB::transaction(function () use ($request, $tenant, $reception): void {
            $record = DB::table('Ope_RecepcionesSistema')->where(['tenant_id' => $tenant, 'id' => $reception])->lockForUpdate()->firstOrFail();
            if ($record->status === 'completed') {
                return;
            }
            $this->assertScannable($record);
            $scans = DB::table('Ope_RecepcionSistemaBultos')->where('reception_id', $reception)->orderBy('sequence')->get();
            if ($scans->isEmpty()) {
                throw ValidationException::withMessages(['reception' => 'Escanea al menos un bulto antes de cerrar la recepción.']);
            }
            $client = DB::table('MBA_clients')->where(['tenant_id' => $tenant, 'id' => $record->client_id])->firstOrFail();
            $users = DB::table('MBA_users')->whereIn('id', $scans->pluck('user_id')->unique())->pluck('name', 'id');
            $loadId = DB::table('Ope_Cargas')->insertGetId([
                'tenant_id' => $tenant, 'user_id' => $record->user_id,
                'source_type' => 'reception', 'filename' => "Recepción Sistema #{$reception}",
                'path' => "system-reception/{$reception}",
                'sha256' => hash('sha256', "system-reception:{$tenant}:{$reception}"),
                'sheet' => 'Sistema', 'mapping' => OperationAccess::json(['profile' => 'system', 'reception_id' => $reception]),
                'status' => 'completed', 'row_count' => $scans->count(), 'invalid_count' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($scans->chunk(300) as $chunk) {
                DB::table('Ope_FilasFuente')->insert($chunk->map(function ($scan) use ($record, $client, $users, $loadId): array {
                    $data = [
                        'tracking' => $scan->tracking, 'raw_code' => $scan->raw_code, 'scan_source' => $scan->scan_source,
                        'weight' => $scan->weight, 'date' => $scan->scanned_on,
                        'operator' => $users->get($scan->user_id),
                        'customer_guide' => $record->document_type === 'guia' ? $record->document_number : null,
                        'reference' => ucfirst($record->document_type).' '.$record->document_number,
                        'client_id' => $record->client_id, 'client_name' => $client->commercial_name ?: $client->legal_name,
                        'height_cm' => $scan->height_cm, 'length_cm' => $scan->length_cm, 'width_cm' => $scan->width_cm,
                        'observations' => $record->observations,
                    ];

                    return [
                        'load_id' => $loadId, 'line' => $scan->sequence, 'tracking' => $scan->tracking,
                        'raw' => OperationAccess::json($data), 'data' => OperationAccess::json($data), 'errors' => '[]',
                    ];
                })->all());
            }
            DB::table('Ope_RecepcionesSistema')->where('id', $reception)->update(['status' => 'completed', 'load_id' => $loadId, 'updated_at' => now()]);
            OperationAccess::audit($tenant, $request->user()->id, 'Cerrar recepción sistema', 'recepcion_sistema', $reception, ['load_id' => $loadId, 'row_count' => $scans->count()]);
        });

        return redirect()->route('operations.system-receptions.show', $reception)
            ->with('status', 'Recepción cerrada. Sus bultos ya están disponibles para preparar el proceso de Operaciones, sin subir un Excel.');
    }

    private function reception(Request $request, int $id): object
    {
        return DB::table('Ope_RecepcionesSistema')->where(['tenant_id' => OperationAccess::tenant($request), 'id' => $id])->firstOrFail();
    }

    private function assertScannable(object $record): void
    {
        if ($record->status !== 'scanning' || ! $record->photo_path) {
            throw ValidationException::withMessages(['reception' => 'Adjunta el respaldo fotográfico antes de escanear o revisa si la recepción ya está cerrada.']);
        }
    }

    private function tracking(Request $request, object $record): array
    {
        $input = $request->validate([
            'raw_code' => ['required', 'string', 'max:500'],
            'scan_source' => ['nullable', Rule::in(['reader', 'camera'])],
        ]);
        $rawCode = trim($input['raw_code']);
        $scanSource = $input['scan_source'] ?? 'reader';
        $decoded = json_decode($rawCode, true);
        if (is_array($decoded) && isset($decoded['o'], $decoded['p']) && is_scalar($decoded['o']) && is_scalar($decoded['p'])) {
            $tracking = trim((string) $decoded['o']).'-'.trim((string) $decoded['p']);
        } else {
            $tracking = $scanSource === 'camera' && $record->qr_start_position && $record->qr_length
                ? mb_substr($rawCode, $record->qr_start_position - 1, $record->qr_length)
                : $rawCode;
        }
        $tracking = mb_strtoupper(trim($tracking));
        if ($tracking === '' || mb_strlen($tracking) > 100 || preg_match('/^[A-Z0-9][A-Z0-9._-]*$/D', $tracking) !== 1) {
            throw ValidationException::withMessages(['raw_code' => 'No se obtuvo un código de bulto válido. Revisa la posición y longitud configuradas para el QR.']);
        }

        return [$rawCode, $tracking, $scanSource];
    }
}
