<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\Coverage;
use App\Models\VisitaDiaria;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VisitaDiariaClosingService
{
    public function close(int $tenantId, string $period): int
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);

        return DB::transaction(function () use ($tenantId, $period): int {
            $visits = VisitaDiaria::query()->with(['provider', 'client'])->where('tenant_id', $tenantId)
                ->where('periodo', $period)->orderBy('id')->lockForUpdate()->get();
            if ($visits->isEmpty() || $visits->contains(fn (VisitaDiaria $visit): bool => $visit->closed_at !== null)) {
                throw ValidationException::withMessages(['periodo' => 'No hay visitas abiertas para cerrar en este período.']);
            }
            $process = $period.'-Visitas';
            if (CourierPaymentMovement::query()->where('tenant_id', $tenantId)->where('periodo', $period)->where('nombre_proceso', 'Visitas')->exists()
                || CourierMovement::query()->where('tenant_id', $tenantId)->where('nombre_proceso', $process)->exists()) {
                throw ValidationException::withMessages(['periodo' => 'Ya existen movimientos de Visitas para este período.']);
            }
            $zones = Coverage::query()->where('tenant_id', $tenantId)->where('is_active', true)
                ->whereIn('provider_id', $visits->pluck('provider_id')->filter()->unique())
                ->whereNotNull('zone')->get(['provider_id', 'zone'])->groupBy('provider_id')
                ->map(fn ($rows) => $rows->pluck('zone')->map(fn ($zone) => trim((string) $zone))->filter()->unique()->values());
            $date = CarbonImmutable::create((int) substr($period, 0, 4), (int) substr($period, 4, 2), 1);
            $prefix = 'VDC-'.$date->format('Ymd').'-';
            $sequence = CourierMovement::query()->where('tenant_id', $tenantId)
                ->where('tracking_number', 'like', $prefix.'%')->pluck('tracking_number')
                ->map(fn (string $tracking): int => ctype_digit(substr($tracking, strlen($prefix)))
                    ? (int) substr($tracking, strlen($prefix)) : 0)->max() + 1;

            foreach ($visits as $visit) {
                $provider = $visit->provider;
                $client = $visit->client;
                $days = array_map('intval', $visit->dias ?? []);
                if ($provider === null || $client === null || $provider->tenant_id !== $tenantId || $client->tenant_id !== $tenantId
                    || trim((string) $provider->tax_id) === '' || trim((string) $client->tax_id) === '') {
                    throw ValidationException::withMessages(['periodo' => "La visita {$visit->id} requiere cliente y proveedor válidos."]);
                }
                if (trim($visit->direccion) === '' || trim($visit->local) === '' || trim($visit->nombre_local) === ''
                    || trim($visit->comuna) === '') {
                    throw ValidationException::withMessages(['periodo' => "Completa local, dirección y comuna de la visita {$visit->id}."]);
                }
                if ($days === [] || count($days) !== count(array_unique($days))
                    || collect($days)->contains(fn (int $day): bool => $day < 1 || $day > $date->daysInMonth)
                    || $visit->total_mensual !== count($days) * $visit->valor_dia || $visit->total_mensual < 1) {
                    throw ValidationException::withMessages(['periodo' => "Revisa días y monto de la visita {$visit->id} antes de cerrar."]);
                }
                $providerZones = $zones->get($provider->id, collect());
                $zone = ProviderZone::resolve($provider->tax_id, $provider->id, $visit->zona
                    ?: ($providerZones->count() === 1 ? $providerZones->first() : $provider->operator_type));
                if (! in_array($zone, ['RM', 'Regiones'], true)) {
                    throw ValidationException::withMessages(['periodo' => "La visita {$visit->id} no tiene una zona RM o Regiones."]);
                }
                if ($sequence > 9999) {
                    throw ValidationException::withMessages(['periodo' => 'Se agotó el correlativo VDC de cuatro dígitos.']);
                }

                $tracking = $prefix.sprintf('%04d', $sequence++);
                $merchant = $client->source_merchant_name ?: $client->commercial_name ?: $client->legal_name;
                $movement = CourierMovement::query()->create([
                    'tenant_id' => $tenantId, 'client_id' => $client->id, 'source_system' => 'Visitas Diarias',
                    'tracking_number' => $tracking, 'tracking_code' => $tracking, 'fecha' => $date,
                    'nombre_proceso' => $process, 'tipo_pago' => 'Visitas Diarias',
                    'weight_kg' => count($days), 'peso_real' => count($days), 'merchant_name' => $merchant,
                    'service_name' => 'Visitas Diarias', 'status' => 'Entregado',
                    'recipient_address' => $visit->direccion, 'destination_commune_name' => $visit->comuna,
                    'courier_name' => $visit->agente_original, 'delivery_user_name' => $visit->agente_original,
                ]);
                CourierPaymentMovement::query()->create([
                    'tenant_id' => $tenantId, 'courier_movement_id' => $movement->id,
                    'visita_diaria_id' => $visit->id, 'zona' => $zone, 'tipo_pago' => 'Visitas Diarias',
                    'nombre_proceso' => 'Visitas', 'periodo' => $period, 'seguimiento_paquete' => $tracking,
                    'fecha' => $date, 'direccion' => $visit->direccion, 'comuna_destino' => $visit->comuna,
                    'comerciante_pila' => $merchant, 'client_id' => $client->id, 'rut_cliente' => $client->tax_id,
                    'razon_social_cliente' => $client->legal_name, 'service_name' => 'Visitas Diarias',
                    'peso_final' => count($days), 'valor' => $visit->total_mensual,
                    'estado_envio' => 'Entregado', 'condicion_pago' => 'SI',
                    'provider_id' => $provider->id, 'razon_social_proveedor' => $provider->legal_name,
                    'rut_proveedor' => $provider->tax_id, 'nombre_operacional' => $provider->operational_name,
                    'tipo_documento' => $provider->tax_document_type,
                    'nombre_repartidor' => $visit->agente_original, 'usuario_entrega' => $visit->agente_original,
                    'empresa_mandante' => '4N',
                ]);
                $visit->update(['closed_at' => now(), 'zona' => $zone]);
            }

            return $visits->count();
        });
    }

    public function reopen(int $tenantId, string $period): int
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);

        return DB::transaction(function () use ($tenantId, $period): int {
            $visits = VisitaDiaria::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                ->orderBy('id')->lockForUpdate()->get();
            if ($visits->isEmpty() || $visits->contains(fn (VisitaDiaria $visit): bool => $visit->closed_at === null)) {
                throw ValidationException::withMessages(['periodo' => 'Este período de Visitas no está cerrado.']);
            }
            $process = $period.'-Visitas';
            $payments = CourierPaymentMovement::query()->where('tenant_id', $tenantId)
                ->where('periodo', $period)->where('nombre_proceso', 'Visitas')->lockForUpdate()->get();
            if ($payments->count() !== $visits->count()
                || $payments->pluck('visita_diaria_id')->sort()->values()->all() !== $visits->pluck('id')->sort()->values()->all()) {
                throw ValidationException::withMessages(['periodo' => 'Los pagos no coinciden con las visitas cerradas. No se reabrió.']);
            }
            $movementIds = $payments->pluck('courier_movement_id');
            if (CourierMovement::query()->where('tenant_id', $tenantId)->whereIn('id', $movementIds)
                ->where('nombre_proceso', $process)->where('source_system', 'Visitas Diarias')->count() !== $visits->count()) {
                throw ValidationException::withMessages(['periodo' => 'Faltan movimientos de origen de Visitas. No se reabrió.']);
            }
            CourierPaymentMovement::query()->whereIn('id', $payments->pluck('id'))->delete();
            CourierMovement::query()->whereIn('id', $movementIds)->delete();
            VisitaDiaria::query()->whereIn('id', $visits->pluck('id'))->update(['closed_at' => null]);

            return $visits->count();
        });
    }
}
