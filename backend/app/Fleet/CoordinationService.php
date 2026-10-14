<?php

namespace App\Fleet;

use App\Models\Client;
use App\Models\ClientBranch;
use App\Models\FixedPickup;
use App\Models\ServiceRequest;
use App\Models\ServiceType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CoordinationService
{
    public function createRequest(
        User $actor,
        Client $client,
        ClientBranch $branch,
        int $serviceTypeId,
        array $data,
        ?FixedPickup $fixedPickup = null,
    ): ServiceRequest {
        return DB::transaction(function () use ($actor, $client, $branch, $serviceTypeId, $data, $fixedPickup): ServiceRequest {
            if ($fixedPickup !== null) {
                $fixedPickup = FixedPickup::query()->lockForUpdate()->findOrFail($fixedPickup->id);
                if (ServiceRequest::where('fixed_pickup_id', $fixedPickup->id)->whereDate('requested_date_original', $data['service_date'])->exists()) {
                    throw ValidationException::withMessages(['fixed_pickup_id' => 'Esta ocurrencia ya está en Solicitudes.']);
                }
            }
            if ($branch->client_id !== $client->id || ! $branch->is_active || $branch->address_status !== 'ready') {
                throw ValidationException::withMessages(['client_branch_id' => 'El punto no tiene una dirección operativa validada para este cliente.']);
            }
            if ($fixedPickup !== null && ($fixedPickup->client_id !== $client->id || $fixedPickup->client_branch_id !== $branch->id || ! $fixedPickup->is_active || $fixedPickup->association_status !== 'linked')) {
                throw ValidationException::withMessages(['fixed_pickup_id' => 'El retiro fijo no está disponible.']);
            }
            $occurrenceKey = $fixedPickup === null ? null : $fixedPickup->id.'|'.$data['service_date'].'|'.$data['window_start'].'|'.$data['window_end'];
            if ($occurrenceKey !== null && ServiceRequest::query()->where('fixed_occurrence_key', $occurrenceKey)->exists()) {
                throw ValidationException::withMessages(['fixed_pickup_id' => 'Esta ocurrencia ya está en Solicitudes.']);
            }
            if (! $client->is_active || ! ServiceType::whereKey($serviceTypeId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['service_type_id' => 'El cliente y el servicio deben estar activos.']);
            }
            $contact = $branch->contacts()->where('is_active', true)->orderByDesc('is_primary')->first();
            $address = $data['effective_address'] ?? $branch->address;
            $override = $address !== $branch->address;
            if ($override && blank($data['address_override_reason'] ?? null)) {
                throw ValidationException::withMessages(['address_override_reason' => 'La dirección excepcional requiere un motivo.']);
            }
            $request = ServiceRequest::create([
                'requested_volume_m3' => $data['requested_volume_m3'] ?? null,
                'requested_weight_kg' => $data['requested_weight_kg'] ?? null,
                'pickup_name' => $data['pickup_name'] ?? $branch->name,
                'commune_snapshot' => $data['commune_snapshot'] ?? $branch->commune_name,
                'access_reference_snapshot' => $data['access_reference_snapshot'] ?? $branch->location_reference,
                'contact_name_snapshot' => $data['contact_name_snapshot'] ?? $contact?->name,
                'contact_phone_snapshot' => $data['contact_phone_snapshot'] ?? $contact?->phone,
                'is_test' => app()->environment(['local', 'testing']) && config('fleet.test_tools_enabled') && (config('fleet.auto_test_mode') || ($data['is_test'] ?? false)),
                'route_notes' => $data['route_notes'] ?? null,
                'tenant_id' => $client->tenant_id,
                'client_id' => $client->id,
                'client_branch_id' => $branch->id,
                'service_type_id' => $serviceTypeId,
                'fixed_pickup_id' => $fixedPickup?->id,
                'fixed_occurrence_key' => $occurrenceKey,
                'requested_by_user_id' => $actor->id,
                'status' => 'requested',
                'requested_date_original' => $data['service_date'],
                'service_date' => $data['service_date'],
                'shift' => $data['shift'],
                'window_start' => $data['window_start'],
                'window_end' => $data['window_end'],
                'packages' => $data['packages'],
                'load_unit' => $data['load_unit'] ?? null,
                'pallet_type' => ($data['load_unit'] ?? null) === 'pallets' ? ($data['pallet_type'] ?? null) : null,
                'pallet_height_cm' => ($data['load_unit'] ?? null) === 'pallets' ? ($data['pallet_height_cm'] ?? null) : null,
                'material' => $data['material'] ?? 'Bultos',
                'requesting_area' => $data['requesting_area'] ?? ($fixedPickup ? 'Operaciones' : ($actor->area ?: 'Coordinación')),
                'pickup_kind' => $fixedPickup ? 'Fijo' : ($data['pickup_kind'] ?? 'Spot'),
                'client_name_snapshot' => $fixedPickup?->source_client_name ?? $client->commercial_name,
                'legal_name_snapshot' => $client->legal_name,
                'point_name_snapshot' => $fixedPickup?->source_point_name ?? $branch->name,
                'address_snapshot' => $branch->address,
                'effective_address' => $address,
                'operational_emails_snapshot' => $data['operational_emails_snapshot'] ?? $fixedPickup?->operational_emails ?? $branch->operational_emails,
                'address_override_reason' => $override ? $data['address_override_reason'] : null,
                'address_override_by_user_id' => $override ? $actor->id : null,
                'address_override_at' => $override ? now() : null,
                'notes' => $data['notes'] ?? null,
                'email_status' => 'not_prepared',
            ]);
            $request->update(['ret_code' => RetCode::format($request->id)]);
            if (($data['pickup_kind'] ?? null) === 'Variable') {
                $request->update(['pickup_name' => $request->ret_code]);
            }
            $request->update(['original_data' => $request->only(['service_date', 'window_start', 'window_end', 'shift', 'effective_address', 'packages', 'load_unit', 'pallet_type', 'pallet_height_cm', 'client_name_snapshot', 'point_name_snapshot', 'requesting_area', 'pickup_kind', 'contact_name_snapshot', 'contact_phone_snapshot', 'access_reference_snapshot', 'operational_emails_snapshot', 'commune_snapshot', 'requested_weight_kg', 'requested_volume_m3', 'notes'])]);
            $this->event($request, $actor, 'created', ['fixed_pickup_id' => $fixedPickup?->id]);

            if (app(LatePickupAuthorization::class)->afterCutoff($request->service_date->toDateString(), $request->tenant_id)) {
                app(LatePickupAuthorization::class)->request($request, $actor, $request->service_date->toDateString(), $data['notes'] ?? 'Solicitud ingresada fuera del horario de corte.');
                $request->refresh();
            }

            app(PickupCustomerService::class)->receipt($request);

            return $request;
        }, 3);
    }

    public function event(ServiceRequest $request, ?User $actor, string $action, array $details = []): void
    {
        DB::table('service_request_events')->insert([
            'service_request_id' => $request->id,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    public function confirmationPreview(ServiceRequest $request, string $driver, string $plate): array
    {
        if (! in_array($request->status, ['scheduled', 'rescheduled'], true) || blank($request->operational_emails_snapshot) || blank($driver) || blank($plate)) {
            throw ValidationException::withMessages(['email' => 'Faltan datos de la programación o el correo operacional.']);
        }
        $date = Carbon::parse($request->service_date)->locale('es')->translatedFormat('l, d \d\e F \d\e Y');
        $subject = $request->status === 'rescheduled'
            ? $request->ret_code.' | Reprogramación de retiro – 4N Logística'
            : $request->ret_code.' | Retiro agendado · '.$request->service_date->format('Y-m-d');
        $address = collect([$request->point_name_snapshot, $request->effective_address, $request->commune_snapshot])->filter(fn ($value) => filled($value))->unique()->implode(', ');
        $start = substr($request->window_start, 0, 5);
        $end = substr($request->window_end, 0, 5);
        $unit = $request->load_unit === 'pallets' ? ($request->packages === 1 ? 'pallet' : 'pallets') : ($request->packages === 1 ? 'bulto' : 'bultos');
        $packages = $request->packages.' '.$unit;
        $material = trim((string) $request->material);
        if ($material !== '' && mb_strtolower($material) !== 'bultos') {
            $packages .= ' de '.$material;
        }
        $greeting = str_replace(["\r", "\n", '*', '_', '[', ']'], ' ', $request->client_name_snapshot);
        $body = "**Hola, {$greeting} 👋**\n\n¡Tu retiro ya está programado con el código **{$request->ret_code}**!\n\n"
            ."- **Fecha:** {$date}\n- **Dirección:** {$address}\n"
            ."- **Jornada/horario:** {$start} a {$end}\n"
            ."- **Conductor:** {$driver}\n- **Patente:** {$plate}\n\n"
            ."El retiro fue agendado considerando **{$packages}**.\n\n"
            ."Si necesitas modificar la carga, informa la nueva cantidad de bultos, el peso total de todos los bultos o el peso de cada uno, y tus indicaciones, respondiendo este correo —con copia a tu ejecutivo de Postventa— **dentro de los próximos 20 minutos**. Si no recibimos nuevos antecedentes, se considerará la cantidad de bultos informada en la solicitud.\n\n"
            ."**Importante:** la carga debe estar lista dentro de la ventana horaria indicada. Nuestro transportista podrá esperar un **máximo de 10 minutos**; si no se encuentra disponible, deberá continuar con su ruta.\n\n"
            ."Si la cantidad supera lo informado y el cambio no fue comunicado previamente, **no podemos garantizar el retiro de toda la carga**, ya que la capacidad del vehículo se asigna según los antecedentes recibidos.\n\n"
            ."**Recuerda:** cada bulto debe contar con un embalaje adecuado a sus características, peso y fragilidad, que lo proteja durante su manipulación y transporte. **4N Logística no podrá responder por daños ocasionados por un embalaje insuficiente o inadecuado.**\n\n"
            ."**Documentación y trazabilidad:** antes del retiro, asegúrate de que las guías de despacho de tus envíos estén correctamente emitidas y registradas en la plataforma de **4N Logística**, con información completa y consistente con la carga que entregarás.\n\n"
            ."Saludos cordiales,\n\n**4N Logística**";

        return ['subject' => $subject, 'body' => $body];
    }
}
