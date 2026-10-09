<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\OperationAccess;
use App\Modules\Operations\Services\OperationDriverJourneyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class OperationDriverController extends Controller
{
    public function index(Request $request, OperationDriverJourneyService $service): View
    {
        $tenant = OperationAccess::tenant($request);
        $driver = $service->driver($tenant, $request->user()->id);
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? now('America/Santiago')->toDateString();
        $journeys = DB::table('Ope_Recorridos')->where(['tenant_id' => $tenant, 'driver_id' => $driver->id, 'departure_date' => $date])
            ->orderBy('id')->get();
        $incoming = DB::table('Ope_RecorridoTraspasos as transfer')
            ->join('Ope_Recorridos as source', 'source.id', '=', 'transfer.from_journey_id')
            ->leftJoin('Ope_RecorridoParadas as stop', 'stop.id', '=', 'transfer.stop_id')
            ->where(['transfer.tenant_id' => $tenant, 'transfer.to_driver_id' => $driver->id, 'transfer.status' => 'pending'])
            ->get(['transfer.id', 'transfer.package_count', 'transfer.observation', 'source.name as source_name', 'source.departure_date', 'stop.name as stop_name']);

        return view('operations::driver-index', [
            'driver' => $driver, 'date' => $date, 'assigned' => $service->assigned($tenant, $driver, $date),
            'journeys' => $journeys, 'incoming' => $incoming,
        ]);
    }

    public function claim(Request $request, OperationDriverJourneyService $service): RedirectResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'key' => ['required', 'string', 'size:64']]);
        $tenant = OperationAccess::tenant($request);
        $driver = $service->driver($tenant, $request->user()->id);
        $id = $service->claim($tenant, $driver, $data['date'], $data['key']);

        return redirect()->route('operations.driver.show', $id)->with('status', 'Ruta tomada. Verifica el vehículo antes de iniciar.');
    }

    public function show(Request $request, int $journey, OperationDriverJourneyService $service): View
    {
        $tenant = OperationAccess::tenant($request);
        $driver = $service->driver($tenant, $request->user()->id);
        $record = $service->journey($tenant, $driver->id, $journey);
        $stops = DB::table('Ope_RecorridoParadas')->where('journey_id', $journey)->orderBy('sequence')->get();
        $bsaleByGuide = DB::table('Ope_GuiasBsale')->where('tenant_id', $tenant)
            ->where('estado', 'generada')->whereIn('guide_id', $stops->pluck('guide_id'))
            ->orderBy('sheet_number')->get()->groupBy('guide_id');
        $evidence = DB::table('Ope_RecorridoEvidencias')->where('journey_id', $journey)->get()->groupBy('stop_id');
        $drivers = DB::table('Ope_Choferes')->where('tenant_id', $tenant)->where('is_active', true)
            ->whereNotNull('user_id')->where('id', '<>', $driver->id)->orderBy('name')->get(['id', 'name']);
        $transfers = DB::table('Ope_RecorridoTraspasos as transfer')
            ->join('Ope_Choferes as recipient', 'recipient.id', '=', 'transfer.to_driver_id')
            ->leftJoin('Ope_RecorridoParadas as stop', 'stop.id', '=', 'transfer.stop_id')
            ->where('transfer.from_journey_id', $journey)
            ->get(['transfer.id', 'transfer.stop_id', 'transfer.package_count', 'transfer.status', 'recipient.name as recipient_name', 'stop.name as stop_name']);
        $returnBalance = $service->returnBalance($journey);
        $warehouseReturns = DB::table('Ope_RecorridoDevolucionesBodega')->where('journey_id', $journey)->orderBy('recorded_at')->get();
        $manualLocationAllowed = $this->manualLocationAllowed($request);

        return view('operations::driver-show', compact('driver', 'record', 'stops', 'bsaleByGuide', 'evidence', 'drivers', 'transfers', 'returnBalance', 'warehouseReturns', 'manualLocationAllowed'));
    }

    public function guides(Request $request, int $journey, OperationDriverJourneyService $service): View
    {
        $tenant = OperationAccess::tenant($request);
        $driver = $service->driver($tenant, $request->user()->id);
        $record = $service->journey($tenant, $driver->id, $journey);
        $stops = DB::table('Ope_RecorridoParadas')->where('journey_id', $journey)->orderBy('sequence')->get();
        $emissions = DB::table('Ope_GuiasBsale')->where('tenant_id', $tenant)
            ->where('estado', 'generada')->whereIn('guide_id', $stops->pluck('guide_id'))
            ->orderBy('sheet_number')->get()->groupBy('guide_id');

        return view('operations::driver-guides', compact('record', 'stops', 'emissions'));
    }

    public function start(Request $request, int $journey, OperationDriverJourneyService $service): RedirectResponse
    {
        $data = $request->validate([
            'plate' => ['required', 'string', 'max:12'],
            'start_odometer' => ['required', 'integer', 'min:0', 'max:9999999'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'location_source' => ['nullable', 'in:gps,manual'],
            'vehicle_no_observations' => ['nullable', 'boolean'],
            'vehicle_observation' => ['nullable', 'string', 'max:2000'],
            'vehicle_photos' => ['nullable', 'array', 'max:5'],
            'vehicle_photos.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif', 'max:15360'],
        ], ['latitude.required' => 'Falta la latitud de la ubicación.', 'longitude.required' => 'Falta la longitud de la ubicación.']);
        $tenant = OperationAccess::tenant($request);
        $occurredAt = $this->occurredAt($request);
        $locationSource = $this->locationSource($request, $data);
        $driver = $service->driver($tenant, $request->user()->id);
        $record = $service->journey($tenant, $driver->id, $journey);
        if ($record->status !== 'assigned') {
            return back()->with('status', 'La jornada ya fue iniciada.');
        }
        if ($this->plateKey($data['plate']) !== $this->plateKey($record->plate)) {
            throw ValidationException::withMessages(['plate' => 'La patente no coincide con la asignada. Solicita la corrección al supervisor.']);
        }
        if (empty($data['vehicle_no_observations']) && blank($data['vehicle_observation'] ?? null)) {
            throw ValidationException::withMessages(['vehicle_observation' => 'Describe el estado del vehículo o marca «Sin observaciones».']);
        }
        $paths = [];
        try {
            foreach ($request->file('vehicle_photos', []) as $photo) {
                $paths[] = $photo->store("operations/{$tenant}/driver-journeys/{$journey}", 'local');
            }
            DB::transaction(function () use ($tenant, $request, $journey, $data, $paths, $occurredAt, $locationSource): void {
                $current = DB::table('Ope_Recorridos')->where('id', $journey)->lockForUpdate()->firstOrFail();
                if ($current->status !== 'assigned') {
                    throw ValidationException::withMessages(['route' => 'La jornada ya fue iniciada. Actualiza la pantalla.']);
                }
                DB::table('Ope_Recorridos')->where('id', $journey)->update([
                    'status' => 'in_progress', 'start_odometer' => $data['start_odometer'],
                    'start_latitude' => $data['latitude'], 'start_longitude' => $data['longitude'],
                    'start_location_source' => $locationSource,
                    'vehicle_no_observations' => ! empty($data['vehicle_no_observations']),
                    'vehicle_observation' => $data['vehicle_observation'] ?? null,
                    'started_at' => $occurredAt, 'updated_at' => now(),
                ]);
                $this->saveEvidence($journey, null, $request->user()->id, 'vehicle', $paths);
                OperationAccess::audit($tenant, $request->user()->id, 'Iniciar jornada', 'recorrido', $journey, ['plate' => $current->plate, 'start_odometer' => $data['start_odometer'], 'photos' => count($paths)]);
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($paths);
            throw $error;
        }

        return back()->with('status', 'Jornada iniciada. La hora y la ubicación quedaron registradas.');
    }

    public function depart(Request $request, int $journey, int $stop, OperationDriverJourneyService $service): RedirectResponse
    {
        $occurredAt = $this->occurredAt($request);
        [$record, $target] = $this->ownedStop($request, $journey, $stop, $service);
        abort_unless($record->status === 'in_progress', 409);
        if ($target->leg_started_at) {
            return back()->with('status', 'El trayecto ya está en curso.');
        }
        abort_unless($target->status === 'pending', 409);
        abort_if(DB::table('Ope_RecorridoParadas')->where('journey_id', $journey)->where('sequence', '<', $target->sequence)->where('status', '<>', 'completed')->exists(), 409, 'Completa la parada anterior primero.');
        DB::table('Ope_RecorridoParadas')->where('id', $stop)->update(['leg_started_at' => $occurredAt, 'updated_at' => now()]);

        return back()->with('status', 'Trayecto iniciado hacia '.$target->name.'.');
    }

    public function arrive(Request $request, int $journey, int $stop, OperationDriverJourneyService $service): RedirectResponse
    {
        $occurredAt = $this->occurredAt($request);
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'location_source' => ['nullable', 'in:gps,manual'],
        ], ['latitude.required' => 'Falta la latitud de la ubicación.', 'longitude.required' => 'Falta la longitud de la ubicación.']);
        $locationSource = $this->locationSource($request, $data);
        [$record, $target] = $this->ownedStop($request, $journey, $stop, $service);
        abort_unless($record->status === 'in_progress', 409);
        if ($target->status === 'arrived' || $target->status === 'completed') {
            return back()->with('status', 'La llegada ya está registrada.');
        }
        abort_unless($target->status === 'pending' && $target->leg_started_at, 409, 'Inicia el trayecto antes de registrar la llegada.');
        DB::table('Ope_RecorridoParadas')->where('id', $stop)->update([
            'status' => 'arrived', 'arrived_at' => $occurredAt, 'arrival_latitude' => $data['latitude'],
            'arrival_longitude' => $data['longitude'], 'arrival_location_source' => $locationSource, 'updated_at' => now(),
        ]);

        return back()->with('status', 'Llegada registrada. Comienza la descarga.');
    }

    public function complete(Request $request, int $journey, int $stop, OperationDriverJourneyService $service): RedirectResponse
    {
        $occurredAt = $this->occurredAt($request);
        $data = $request->validate([
            'observation' => ['nullable', 'string', 'max:2000'],
            'return_count' => ['required', 'integer', 'min:0', 'max:100000'],
            'return_observation' => ['nullable', 'string', 'max:2000'],
            'return_receiver' => ['nullable', 'string', 'max:30'],
            'delivery_photo' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif', 'max:15360'],
            'return_photo' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif', 'max:15360'],
            'signed_guide_photo' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif', 'max:15360'],
            'signature_data' => ['nullable', 'string', 'max:3000000'],
        ]);
        [$record, $target] = $this->ownedStop($request, $journey, $stop, $service);
        abort_unless($record->status === 'in_progress', 409);
        if ($target->status === 'completed') {
            return back()->with('status', 'Esta parada ya está completada.');
        }
        abort_unless($target->status === 'arrived', 409, 'Registra la llegada primero.');
        if (! $request->hasFile('signed_guide_photo') && blank($data['signature_data'] ?? null)) {
            throw ValidationException::withMessages(['signed_guide_photo' => 'Adjunta la guía firmada o recoge la firma en pantalla.']);
        }
        if ($data['return_count'] > 0 && ! $request->hasFile('return_photo')) {
            throw ValidationException::withMessages(['return_photo' => 'Fotografía las devoluciones recogidas.']);
        }
        $tenant = OperationAccess::tenant($request);
        $recipientId = null;
        if ($data['return_count'] > 0 && ($data['return_receiver'] ?? 'self') !== 'self') {
            $chosen = (string) $data['return_receiver'];
            if (! ctype_digit($chosen)) {
                throw ValidationException::withMessages(['return_receiver' => 'Selecciona un chofer válido para recibir las devoluciones.']);
            }
            $recipient = DB::table('Ope_Choferes')->where([
                'tenant_id' => $tenant, 'id' => (int) $chosen, 'is_active' => true,
            ])->whereNotNull('user_id')->where('id', '<>', $record->driver_id)->first();
            if (! $recipient) {
                throw ValidationException::withMessages(['return_receiver' => 'El chofer seleccionado no está disponible para recibir devoluciones.']);
            }
            $recipientId = $recipient->id;
        }
        $files = [];
        try {
            foreach (['delivery_photo' => 'delivery', 'return_photo' => 'return', 'signed_guide_photo' => 'signed_guide'] as $field => $type) {
                if ($request->hasFile($field)) {
                    $files[$type] = $request->file($field)->store('operations/'.OperationAccess::tenant($request)."/driver-journeys/{$journey}/stops/{$stop}", 'local');
                }
            }
            if (filled($data['signature_data'] ?? null)) {
                if (! preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/D', $data['signature_data'], $match)) {
                    throw ValidationException::withMessages(['signature_data' => 'La firma dibujada no tiene un formato válido.']);
                }
                $signature = base64_decode($match[1], true);
                if ($signature === false || strlen($signature) > 1500000 || ! str_starts_with($signature, "\x89PNG\r\n\x1a\n")) {
                    throw ValidationException::withMessages(['signature_data' => 'La firma dibujada no es una imagen PNG válida.']);
                }
                $files['signature'] = 'operations/'.OperationAccess::tenant($request)."/driver-journeys/{$journey}/stops/{$stop}/".Str::uuid().'.png';
                Storage::disk('local')->put($files['signature'], $signature);
            }
            DB::transaction(function () use ($request, $journey, $stop, $data, $files, $occurredAt, $tenant, $recipientId): void {
                $current = DB::table('Ope_RecorridoParadas')->where('id', $stop)->lockForUpdate()->firstOrFail();
                abort_unless($current->status === 'arrived', 409);
                DB::table('Ope_RecorridoParadas')->where('id', $stop)->update([
                    'status' => 'completed', 'departed_at' => $occurredAt, 'observation' => $data['observation'] ?? null,
                    'return_count' => $data['return_count'], 'return_observation' => $data['return_observation'] ?? null,
                    'signature_type' => isset($files['signature']) ? 'drawn' : 'photo', 'updated_at' => now(),
                ]);
                foreach ($files as $type => $path) {
                    $this->saveEvidence($journey, $stop, $request->user()->id, $type, [$path]);
                }
                if ($recipientId) {
                    DB::table('Ope_RecorridoTraspasos')->insert([
                        'tenant_id' => $tenant, 'from_journey_id' => $journey, 'stop_id' => $stop,
                        'to_driver_id' => $recipientId, 'package_count' => $data['return_count'],
                        'observation' => $data['return_observation'] ?? null,
                        'status' => 'pending', 'created_at' => $occurredAt, 'updated_at' => now(),
                    ]);
                }
                OperationAccess::audit($tenant, $request->user()->id, 'Completar parada', 'recorrido_parada', $stop, ['return_count' => $data['return_count'], 'return_receiver_id' => $recipientId, 'evidence' => array_keys($files)]);
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete(array_values($files));
            throw $error;
        }

        return back()->with('status', 'Entrega y devolución registradas. Ya puedes continuar a la siguiente parada.');
    }

    public function finish(Request $request, int $journey, OperationDriverJourneyService $service): RedirectResponse
    {
        $occurredAt = $this->occurredAt($request);
        $data = $request->validate(['end_odometer' => ['required', 'integer', 'min:0', 'max:9999999']]);
        $tenant = OperationAccess::tenant($request);
        $driver = $service->driver($tenant, $request->user()->id);
        $record = $service->journey($tenant, $driver->id, $journey);
        if ($record->status === 'completed') {
            return back()->with('status', 'Este recorrido ya está finalizado.');
        }
        abort_unless($record->status === 'in_progress', 409);
        abort_if(DB::table('Ope_RecorridoParadas')->where('journey_id', $journey)->where('status', '<>', 'completed')->exists(), 409, 'Completa todas las paradas primero.');
        if ($data['end_odometer'] < $record->start_odometer) {
            throw ValidationException::withMessages(['end_odometer' => 'El kilometraje final no puede ser menor que el inicial.']);
        }
        DB::table('Ope_Recorridos')->where('id', $journey)->update(['status' => 'completed', 'end_odometer' => $data['end_odometer'], 'finished_at' => $occurredAt, 'updated_at' => now()]);

        return back()->with('status', 'Recorrido finalizado. Las devoluciones pendientes siguen visibles hasta su entrega o traspaso.');
    }

    public function transfer(Request $request, int $journey, OperationDriverJourneyService $service): RedirectResponse
    {
        $occurredAt = $this->occurredAt($request);
        $data = $request->validate(['to_driver_id' => ['required', 'integer'], 'package_count' => ['required', 'integer', 'min:1'], 'observation' => ['nullable', 'string', 'max:2000'], 'request_key' => ['nullable', 'uuid']]);
        $tenant = OperationAccess::tenant($request);
        $driver = $service->driver($tenant, $request->user()->id);
        $service->journey($tenant, $driver->id, $journey);
        DB::table('Ope_Choferes')->where(['tenant_id' => $tenant, 'id' => $data['to_driver_id'], 'is_active' => true])->whereNotNull('user_id')->where('id', '<>', $driver->id)->firstOrFail();
        if (isset($data['request_key']) && DB::table('Ope_RecorridoTraspasos')->where(['tenant_id' => $tenant, 'from_journey_id' => $journey, 'request_key' => $data['request_key']])->exists()) {
            return back()->with('status', 'Este traspaso ya quedó registrado.');
        }
        DB::transaction(function () use ($tenant, $journey, $data, $occurredAt, $service): void {
            DB::table('Ope_Recorridos')->where('id', $journey)->lockForUpdate()->firstOrFail();
            if ($data['package_count'] > $service->returnBalance($journey)['available']) {
                throw ValidationException::withMessages(['package_count' => 'La cantidad supera las devoluciones disponibles para traspaso.']);
            }
            DB::table('Ope_RecorridoTraspasos')->insert([
                'tenant_id' => $tenant, 'from_journey_id' => $journey, 'to_driver_id' => $data['to_driver_id'],
                'package_count' => $data['package_count'], 'observation' => $data['observation'] ?? null,
                'request_key' => $data['request_key'] ?? null, 'status' => 'pending', 'created_at' => $occurredAt, 'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'Traspaso pendiente de confirmación por el chofer receptor.');
    }

    public function receive(Request $request, int $transfer, OperationDriverJourneyService $service): RedirectResponse
    {
        $occurredAt = $this->occurredAt($request);
        $data = $request->validate(['journey_id' => ['required', 'integer']]);
        $tenant = OperationAccess::tenant($request);
        $driver = $service->driver($tenant, $request->user()->id);
        $target = $service->journey($tenant, $driver->id, $data['journey_id']);
        abort_unless($target->status === 'in_progress', 409, 'Inicia tu ruta antes de recibir devoluciones.');
        DB::transaction(function () use ($tenant, $driver, $transfer, $target, $occurredAt): void {
            $record = DB::table('Ope_RecorridoTraspasos')->where(['tenant_id' => $tenant, 'id' => $transfer, 'to_driver_id' => $driver->id])->lockForUpdate()->firstOrFail();
            if ($record->status === 'received' && $record->to_journey_id === $target->id) {
                return;
            }
            abort_unless($record->status === 'pending', 409, 'Este traspaso ya fue confirmado.');
            DB::table('Ope_RecorridoTraspasos')->where('id', $transfer)->update([
                'status' => 'received', 'to_journey_id' => $target->id, 'received_at' => $occurredAt, 'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'Devoluciones recibidas y vinculadas a tu ruta.');
    }

    public function warehouse(Request $request, int $journey, OperationDriverJourneyService $service): RedirectResponse
    {
        $occurredAt = $this->occurredAt($request);
        $data = $request->validate([
            'package_count' => ['required', 'integer', 'min:1'],
            'photo' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif', 'max:15360'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'location_source' => ['nullable', 'in:gps,manual'],
            'observation' => ['nullable', 'string', 'max:2000'],
            'request_key' => ['nullable', 'uuid'],
        ], ['latitude.required' => 'Falta la latitud de la ubicación.', 'longitude.required' => 'Falta la longitud de la ubicación.']);
        $locationSource = $this->locationSource($request, $data);
        $tenant = OperationAccess::tenant($request);
        $driver = $service->driver($tenant, $request->user()->id);
        $record = $service->journey($tenant, $driver->id, $journey);
        abort_unless($record->status === 'completed', 409, 'Finaliza el recorrido antes de registrar la entrega en bodega.');
        if (isset($data['request_key']) && DB::table('Ope_RecorridoDevolucionesBodega')->where(['tenant_id' => $tenant, 'journey_id' => $journey, 'request_key' => $data['request_key']])->exists()) {
            return back()->with('status', 'Esta entrega a bodega ya quedó registrada.');
        }
        $path = $request->file('photo')->store("operations/{$tenant}/driver-journeys/{$journey}/warehouse", 'local');
        try {
            DB::transaction(function () use ($request, $service, $tenant, $journey, $data, $path, $occurredAt, $locationSource): void {
                DB::table('Ope_Recorridos')->where('id', $journey)->lockForUpdate()->firstOrFail();
                if ($data['package_count'] > $service->returnBalance($journey)['available']) {
                    throw ValidationException::withMessages(['package_count' => 'La cantidad supera las devoluciones disponibles para entregar en bodega.']);
                }
                DB::table('Ope_RecorridoDevolucionesBodega')->insert([
                    'tenant_id' => $tenant, 'journey_id' => $journey, 'user_id' => $request->user()->id,
                    'package_count' => $data['package_count'], 'photo_path' => $path,
                    'latitude' => $data['latitude'], 'longitude' => $data['longitude'],
                    'location_source' => $locationSource,
                    'observation' => $data['observation'] ?? null, 'recorded_at' => $occurredAt,
                    'request_key' => $data['request_key'] ?? null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        return back()->with('status', 'Devoluciones entregadas en bodega.');
    }

    public function warehousePhoto(Request $request, int $journey, int $receipt, OperationDriverJourneyService $service): BinaryFileResponse
    {
        $tenant = OperationAccess::tenant($request);
        $driver = DB::table('Ope_Choferes')->where(['tenant_id' => $tenant, 'user_id' => $request->user()->id])->first();
        $record = DB::table('Ope_Recorridos')->where(['tenant_id' => $tenant, 'id' => $journey])->firstOrFail();
        abort_unless(($driver && $record->driver_id === $driver->id) || OperationAccess::supervisor($request), 403);
        $file = DB::table('Ope_RecorridoDevolucionesBodega')->where(['tenant_id' => $tenant, 'journey_id' => $journey, 'id' => $receipt])->firstOrFail();
        abort_unless(Storage::disk('local')->exists($file->photo_path), 404);

        return response()->file(Storage::disk('local')->path($file->photo_path), [
            'Content-Type' => Storage::disk('local')->mimeType($file->photo_path),
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline',
        ]);
    }

    public function evidence(Request $request, int $journey, int $evidence, OperationDriverJourneyService $service): BinaryFileResponse
    {
        $tenant = OperationAccess::tenant($request);
        $driver = DB::table('Ope_Choferes')->where(['tenant_id' => $tenant, 'user_id' => $request->user()->id])->first();
        $record = DB::table('Ope_Recorridos')->where(['tenant_id' => $tenant, 'id' => $journey])->firstOrFail();
        abort_unless(($driver && $record->driver_id === $driver->id) || OperationAccess::supervisor($request), 403);
        $file = DB::table('Ope_RecorridoEvidencias')->where(['journey_id' => $journey, 'id' => $evidence])->firstOrFail();
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return response()->file(Storage::disk('local')->path($file->path), [
            'Content-Type' => Storage::disk('local')->mimeType($file->path),
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline',
        ]);
    }

    /** @return array{object, object} */
    private function ownedStop(Request $request, int $journey, int $stop, OperationDriverJourneyService $service): array
    {
        $tenant = OperationAccess::tenant($request);
        $driver = $service->driver($tenant, $request->user()->id);
        $record = $service->journey($tenant, $driver->id, $journey);

        return [$record, $service->stop($journey, $stop)];
    }

    /** @param array<int, string> $paths */
    private function saveEvidence(int $journey, ?int $stop, int $user, string $type, array $paths): void
    {
        foreach ($paths as $path) {
            DB::table('Ope_RecorridoEvidencias')->insert([
                'journey_id' => $journey, 'stop_id' => $stop, 'user_id' => $user,
                'type' => $type, 'path' => $path, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function plateKey(string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $plate));
    }

    private function locationSource(Request $request, array $data): string
    {
        $source = $data['location_source'] ?? 'gps';
        if ($source === 'manual' && ! $this->manualLocationAllowed($request)) {
            throw ValidationException::withMessages(['location_source' => 'Las coordenadas manuales solo están disponibles en la prueba local.']);
        }

        return $source;
    }

    private function manualLocationAllowed(Request $request): bool
    {
        if (! app()->environment('local', 'testing') || $request->isSecure()) {
            return false;
        }
        $host = $request->getHost();
        if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        [$first, $second] = array_map('intval', explode('.', $host));

        return $first === 10 || $first === 127 || ($first === 172 && $second >= 16 && $second <= 31) || ($first === 192 && $second === 168);
    }

    private function occurredAt(Request $request): Carbon
    {
        if (! $request->filled('occurred_at')) {
            return now();
        }
        try {
            $time = Carbon::parse($request->input('occurred_at'))->utc();
        } catch (Throwable) {
            throw ValidationException::withMessages(['occurred_at' => 'La fecha del registro no es válida.']);
        }

        return $time;
    }
}
