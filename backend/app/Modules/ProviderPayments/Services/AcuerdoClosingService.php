<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\Acuerdo;
use App\Models\ApoyoAlza;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\Coverage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcuerdoClosingService
{
    public function close(int $tenantId, string $period): int
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);

        return DB::transaction(function () use ($tenantId, $period): int {
            $rows = Acuerdo::query()->with(['client', 'provider'])
                ->where('tenant_id', $tenantId)->where('periodo', $period)
                ->orderBy('id')->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['periodo' => 'Este período no tiene acuerdos para cerrar.']);
            }
            if ($rows->contains(fn (Acuerdo $row): bool => $row->closed_at !== null)) {
                throw ValidationException::withMessages(['periodo' => 'Este período ya está cerrado.']);
            }

            $process = $period.'-Acuerdos';
            if (CourierPaymentMovement::query()->where('tenant_id', $tenantId)->where('periodo', $period)->where('nombre_proceso', 'Acuerdos')->exists()
                || CourierMovement::query()->where('tenant_id', $tenantId)->where('nombre_proceso', $process)->exists()) {
                throw ValidationException::withMessages(['periodo' => 'Ya existen movimientos de Acuerdos para este período. Revisa el proceso antes de cerrar.']);
            }

            $coverageZones = Coverage::query()->where('tenant_id', $tenantId)->where('is_active', true)
                ->whereIn('provider_id', $rows->pluck('provider_id')->filter()->unique())
                ->whereNotNull('zone')->get(['provider_id', 'zone'])->groupBy('provider_id')
                ->map(fn ($coverages) => $coverages->pluck('zone')->map(fn ($zone) => trim((string) $zone))
                    ->filter()->unique()->values());
            $date = CarbonImmutable::create((int) substr($period, 0, 4), (int) substr($period, 4, 2), 1);
            $prefix = 'ACO-'.$date->format('Ymd').'-';
            $nextSequence = CourierMovement::query()->where('tenant_id', $tenantId)
                ->where('tracking_number', 'like', $prefix.'%')->pluck('tracking_number')
                ->map(fn (string $tracking): int => ctype_digit(substr($tracking, strlen($prefix)))
                    ? (int) substr($tracking, strlen($prefix)) : 0)->max() + 1;

            foreach ($rows as $row) {
                $client = $row->client;
                $provider = $row->provider;
                if ($client === null || $provider === null || $client->tenant_id !== $tenantId || $provider->tenant_id !== $tenantId
                    || trim((string) $client->tax_id) === '' || trim((string) $provider->tax_id) === '') {
                    throw ValidationException::withMessages(['periodo' => "El acuerdo {$row->id} requiere un cliente y proveedor válidos en sus mantenedores antes del cierre."]);
                }
                $zones = $coverageZones->get($provider->id, collect());
                $zone = $row->zona ?: ($zones->count() === 1 ? $zones->first()
                    : ($zones->isEmpty() ? $provider->operator_type : null));
                if (! in_array($zone, ['RM', 'Regiones'], true)) {
                    throw ValidationException::withMessages(['periodo' => "El acuerdo {$row->id} no tiene una zona única para el proveedor. Selecciona RM o Regiones en la fila."]);
                }
                if (trim((string) $row->empresa_mandante) === '' || mb_strlen($row->empresa_mandante) > 100) {
                    throw ValidationException::withMessages(['periodo' => "El acuerdo {$row->id} requiere empresa mandante antes del cierre."]);
                }
                if (trim((string) $row->agencia) === '' || (int) $row->cantidad < 1) {
                    throw ValidationException::withMessages(['periodo' => "El acuerdo {$row->id} requiere agencia y cantidad mayor que cero antes del cierre."]);
                }
                if ($nextSequence > 9999) {
                    throw ValidationException::withMessages(['periodo' => 'Se agotó el correlativo ACO de cuatro dígitos para este período.']);
                }

                $tracking = $prefix.sprintf('%04d', $nextSequence++);
                $merchantName = $client->source_merchant_name ?: $client->commercial_name ?: $client->legal_name;
                $address = trim((string) $row->marca).' / '.trim($row->agencia);
                $movement = CourierMovement::query()->create([
                    'tenant_id' => $tenantId,
                    'client_id' => $client->id,
                    'source_system' => 'Acuerdos',
                    'tracking_number' => $tracking,
                    'tracking_code' => $tracking,
                    'fecha' => $date,
                    'nombre_proceso' => $process,
                    'tipo_pago' => 'Acuerdos',
                    'weight_kg' => $row->cantidad,
                    'peso_real' => $row->cantidad,
                    'merchant_name' => $merchantName,
                    'service_name' => $row->servicio,
                    'status' => 'Entregado',
                    'recipient_address' => $address,
                    'destination_commune_name' => $row->agencia,
                    'courier_name' => null,
                    'delivery_user_name' => null,
                ]);
                CourierPaymentMovement::query()->create([
                    'tenant_id' => $tenantId,
                    'courier_movement_id' => $movement->id,
                    'acuerdo_id' => $row->id,
                    'zona' => $zone,
                    'tipo_pago' => 'Acuerdos',
                    'nombre_proceso' => 'Acuerdos',
                    'periodo' => $period,
                    'seguimiento_paquete' => $tracking,
                    'fecha' => $date,
                    'direccion' => $address,
                    'comuna_destino' => $row->agencia,
                    'comerciante_pila' => $merchantName,
                    'client_id' => $client->id,
                    'rut_cliente' => $client->tax_id,
                    'razon_social_cliente' => $client->legal_name,
                    'service_name' => $row->servicio,
                    'peso_final' => $row->cantidad,
                    'valor' => $row->total,
                    'estado_envio' => 'Entregado',
                    'condicion_pago' => 'SI',
                    'provider_id' => $provider->id,
                    'razon_social_proveedor' => $provider->legal_name,
                    'rut_proveedor' => $provider->tax_id,
                    'nombre_operacional' => $provider->operational_name,
                    'tipo_documento' => $provider->tax_document_type,
                    'nombre_repartidor' => null,
                    'usuario_entrega' => null,
                    'empresa_mandante' => $row->empresa_mandante,
                ]);
                $row->update(['closed_at' => now()]);
            }

            return $rows->count();
        });
    }

    public function reopen(int $tenantId, string $period): int
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);

        return DB::transaction(function () use ($tenantId, $period): int {
            if (ApoyoAlza::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                ->where('proceso_base', 'Acuerdos')->whereNotNull('closed_at')->exists()) {
                throw ValidationException::withMessages(['periodo' => 'Primero reabre Apoyo Alza de este período antes de modificar Acuerdos.']);
            }
            $rows = Acuerdo::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                ->orderBy('id')->lockForUpdate()->get();
            if ($rows->isEmpty() || $rows->contains(fn (Acuerdo $row): bool => $row->closed_at === null)) {
                throw ValidationException::withMessages(['periodo' => 'Este período no está cerrado.']);
            }

            $process = $period.'-Acuerdos';
            $payments = CourierPaymentMovement::query()->where('tenant_id', $tenantId)
                ->where('periodo', $period)->where('nombre_proceso', 'Acuerdos')->lockForUpdate()->get();
            if ($payments->count() !== $rows->count()
                || $payments->pluck('acuerdo_id')->sort()->values()->all() !== $rows->pluck('id')->sort()->values()->all()) {
                throw ValidationException::withMessages(['periodo' => 'Los pagos de Acuerdos no coinciden con el período cerrado. No se reabrió.']);
            }
            $movementIds = $payments->pluck('courier_movement_id');
            if (CourierMovement::query()->where('tenant_id', $tenantId)->whereIn('id', $movementIds)
                ->where('nombre_proceso', $process)->where('source_system', 'Acuerdos')->count() !== $rows->count()) {
                throw ValidationException::withMessages(['periodo' => 'Los movimientos de origen de Acuerdos están incompletos. No se reabrió.']);
            }

            CourierPaymentMovement::query()->whereIn('id', $payments->pluck('id'))->delete();
            CourierMovement::query()->whereIn('id', $movementIds)->delete();
            Acuerdo::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                ->update(['closed_at' => null]);

            return $rows->count();
        });
    }
}
