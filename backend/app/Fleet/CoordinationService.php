<?php

namespace App\Fleet;

use App\Models\Client;
use App\Models\ClientBranch;
use App\Models\FixedPickup;
use App\Models\ServiceRequest;
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
            $address = $data['effective_address'] ?? $branch->address;
            $override = $address !== $branch->address;
            if ($override && blank($data['address_override_reason'] ?? null)) {
                throw ValidationException::withMessages(['address_override_reason' => 'La dirección excepcional requiere un motivo.']);
            }
            $request = ServiceRequest::create([
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
                'material' => $data['material'],
                'client_name_snapshot' => $fixedPickup?->source_client_name ?? $client->commercial_name,
                'legal_name_snapshot' => $client->legal_name,
                'point_name_snapshot' => $fixedPickup?->source_point_name ?? $branch->name,
                'address_snapshot' => $branch->address,
                'effective_address' => $address,
                'operational_emails_snapshot' => $fixedPickup?->operational_emails ?? $branch->operational_emails,
                'address_override_reason' => $override ? $data['address_override_reason'] : null,
                'address_override_by_user_id' => $override ? $actor->id : null,
                'address_override_at' => $override ? now() : null,
                'notes' => $data['notes'] ?? null,
                'email_status' => 'not_prepared',
            ]);
            $request->update(['ret_code' => 'RET-'.str_pad((string) $request->id, 5, '0', STR_PAD_LEFT)]);
            $this->event($request, $actor, 'created', ['fixed_pickup_id' => $fixedPickup?->id]);

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
        $date = Carbon::parse($request->service_date)->locale('es')->translatedFormat('l d \d\e F \d\e Y');
        $subject = $request->status === 'rescheduled'
            ? 'Reprogramación de retiro '.$request->ret_code.' – 4N Logística'
            : 'Retiro agendado '.$request->ret_code.' · '.$request->service_date->format('Y-m-d');
        $body = "Hola 👋\n\n¡Tu retiro ya está programado con el código **{$request->ret_code}**!\n\n"
            ."- **Fecha:** {$date}\n- **Dirección:** {$request->effective_address}\n"
            ."- **Jornada/horario:** {$request->shift} · {$request->window_start}–{$request->window_end}\n"
            ."- **Conductor:** {$driver}\n- **Patente:** {$plate}\n\n"
            ."El retiro fue agendado considerando **{$request->packages} bulto(s) de {$request->material}**.\n\n"
            ."Si necesitas modificar la cantidad o informar alguna consideración adicional, responde este correo —con copia a tu ejecutivo de Postventa— **dentro de los próximos 10 minutos**.\n\n"
            ."**Importante:** la carga debe estar lista dentro de la ventana horaria indicada. Nuestro transportista podrá esperar un **máximo de 10 minutos**; si no se encuentra disponible, deberá continuar con su ruta.\n\n"
            ."Si la cantidad supera lo informado y el cambio no fue comunicado previamente, **no podemos garantizar el retiro de toda la carga**, ya que la capacidad del vehículo se asigna según los antecedentes recibidos.\n\n"
            ."**Recuerda:** cada bulto debe contar con un embalaje adecuado a sus características, peso y fragilidad, que lo proteja durante su manipulación y transporte. **4N Logística no podrá responder por daños ocasionados por un embalaje insuficiente o inadecuado.**\n\n"
            ."Saludos cordiales,\n\n**4N Logística**";

        return ['subject' => $subject, 'body' => $body];
    }
}
