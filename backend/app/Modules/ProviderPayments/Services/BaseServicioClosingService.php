<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\BaseServicio;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\ServiceType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BaseServicioClosingService
{
    public function close(int $tenantId, string $period): int
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);

        return DB::transaction(function () use ($tenantId, $period): int {
            $rows = BaseServicio::query()->with(['client', 'provider'])
                ->where('tenant_id', $tenantId)->where('periodo', $period)
                ->orderBy('id')->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['periodo' => 'Este período no tiene servicios para cerrar.']);
            }
            if ($rows->contains(fn (BaseServicio $row): bool => $row->closed_at !== null)) {
                throw ValidationException::withMessages(['periodo' => 'Este período ya está cerrado.']);
            }

            $process = $period.'-Servicios';
            if (CourierPaymentMovement::query()->where('tenant_id', $tenantId)->where('periodo', $period)->where('nombre_proceso', 'Servicios')->exists()
                || CourierMovement::query()->where('tenant_id', $tenantId)->where('nombre_proceso', $process)->exists()) {
                throw ValidationException::withMessages(['periodo' => 'Ya existen movimientos de Servicios para este período. Revisa el proceso antes de cerrar.']);
            }

            $serviceTypes = ServiceType::query()->get()->keyBy(fn (ServiceType $service): string => $this->serviceKey($service->name));
            $service4N = ServiceType::query()->where('service_code', 0)->first();
            $nextSequences = [];
            foreach ($rows as $row) {
                $client = $row->client;
                $provider = $row->provider;
                if ($client === null || $provider === null || $client->tenant_id !== $tenantId || $provider->tenant_id !== $tenantId) {
                    throw ValidationException::withMessages(['periodo' => "El servicio de la fila {$row->fila_origen} requiere un cliente y proveedor válidos antes del cierre."]);
                }
                $weight = (float) $row->peso;
                if ($weight < 1 || floor($weight) !== $weight) {
                    throw ValidationException::withMessages(['periodo' => "El peso de la fila {$row->fila_origen} debe ser un número entero mayor que cero para grabarlo en Peso Final."]);
                }
                if (mb_strlen($row->zona) > 20 || mb_strlen($row->comuna_destino) > 150
                    || mb_strlen($row->usuario) > 160 || mb_strlen($row->empresa) > 20) {
                    throw ValidationException::withMessages(['periodo' => "La fila {$row->fila_origen} supera el largo permitido de zona, comuna, usuario o empresa en Pagos Movimientos Courier."]);
                }
                $service = $serviceTypes->get($this->serviceKey($row->servicio));
                if ($service === null && in_array($this->serviceKey($row->servicio), ['servicios 4n', 'servicio 4n'], true)) {
                    $service = $service4N;
                }
                if ($service === null) {
                    throw ValidationException::withMessages(['periodo' => "El servicio de la fila {$row->fila_origen} no existe en el mantenedor de Servicios."]);
                }

                $date = $row->fecha_carga;
                $prefix = 'SVC-'.$date->format('Ymd').'-';
                if (! isset($nextSequences[$prefix])) {
                    $nextSequences[$prefix] = CourierMovement::query()->where('tenant_id', $tenantId)
                        ->where('tracking_number', 'like', $prefix.'%')->pluck('tracking_number')
                        ->map(fn (string $tracking): int => ctype_digit(substr($tracking, strlen($prefix)))
                            ? (int) substr($tracking, strlen($prefix)) : 0)->max() + 1;
                }
                if ($nextSequences[$prefix] > 9999) {
                    throw ValidationException::withMessages(['periodo' => "Se agotó el correlativo SVC de cuatro dígitos para {$date->format('d-m-Y')}."]);
                }
                $tracking = $prefix.sprintf('%04d', $nextSequences[$prefix]++);
                $merchantName = $client->source_merchant_name ?: $client->commercial_name;
                $movement = CourierMovement::query()->create([
                    'tenant_id' => $tenantId,
                    'client_id' => $client->id,
                    'source_system' => 'Servicios',
                    'tracking_number' => $tracking,
                    'tracking_code' => $tracking,
                    'fecha' => $date,
                    'nombre_proceso' => $process,
                    'tipo_pago' => 'Servicios',
                    'weight_kg' => $weight,
                    'peso_real' => (int) $weight,
                    'merchant_name' => $merchantName,
                    'service_name' => $service->name,
                    'status' => 'Entregado',
                    'recipient_address' => $row->direccion,
                    'destination_commune_name' => $row->comuna_destino,
                    'courier_name' => $row->usuario,
                    'delivery_user_name' => $row->usuario,
                ]);
                CourierPaymentMovement::query()->create([
                    'tenant_id' => $tenantId,
                    'courier_movement_id' => $movement->id,
                    'base_servicio_id' => $row->id,
                    'zona' => $row->zona,
                    'tipo_pago' => 'Servicios',
                    'nombre_proceso' => 'Servicios',
                    'periodo' => $period,
                    'seguimiento_paquete' => $tracking,
                    'fecha' => $date,
                    'direccion' => $row->direccion,
                    'comuna_destino' => $row->comuna_destino,
                    'comerciante_pila' => $merchantName,
                    'client_id' => $client->id,
                    'rut_cliente' => $client->tax_id,
                    'razon_social_cliente' => $client->legal_name,
                    'service_type_id' => $service->id,
                    'service_code' => $service->service_code,
                    'service_name' => $service->name,
                    'peso_final' => (int) $weight,
                    'valor' => $row->valor_final,
                    'estado_envio' => 'Entregado',
                    'condicion_pago' => 'SI',
                    'provider_id' => $provider->id,
                    'razon_social_proveedor' => $provider->legal_name,
                    'rut_proveedor' => $provider->tax_id,
                    'nombre_operacional' => $provider->operational_name,
                    'tipo_documento' => $provider->tax_document_type,
                    'nombre_repartidor' => $row->usuario,
                    'usuario_entrega' => $row->usuario,
                    'empresa_mandante' => $row->empresa,
                ]);
                $row->update([
                    ...BaseServicioImporter::clientFields($client),
                    ...BaseServicioImporter::providerFields($provider),
                    'closed_at' => now(),
                ]);
            }

            return $rows->count();
        });
    }

    public function reopen(int $tenantId, string $period): int
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);

        return DB::transaction(function () use ($tenantId, $period): int {
            $rows = BaseServicio::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                ->orderBy('id')->lockForUpdate()->get();
            if ($rows->isEmpty() || $rows->contains(fn (BaseServicio $row): bool => $row->closed_at === null)) {
                throw ValidationException::withMessages(['periodo' => 'Este período no está cerrado.']);
            }

            $process = $period.'-Servicios';
            $payments = CourierPaymentMovement::query()->where('tenant_id', $tenantId)
                ->where('periodo', $period)->where('nombre_proceso', 'Servicios')->lockForUpdate()->get();
            if ($payments->count() !== $rows->count()
                || $payments->pluck('base_servicio_id')->sort()->values()->all() !== $rows->pluck('id')->sort()->values()->all()) {
                throw ValidationException::withMessages(['periodo' => 'Los pagos de Servicios no coinciden con el período cerrado. No se reabrió.']);
            }
            $movementIds = $payments->pluck('courier_movement_id');
            if (CourierMovement::query()->where('tenant_id', $tenantId)->whereIn('id', $movementIds)
                ->where('nombre_proceso', $process)->where('source_system', 'Servicios')->count() !== $rows->count()) {
                throw ValidationException::withMessages(['periodo' => 'Los movimientos de origen de Servicios están incompletos. No se reabrió.']);
            }

            CourierPaymentMovement::query()->whereIn('id', $payments->pluck('id'))->delete();
            CourierMovement::query()->whereIn('id', $movementIds)->delete();
            BaseServicio::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                ->update(['closed_at' => null]);

            return $rows->count();
        });
    }

    private function serviceKey(string $name): string
    {
        return mb_strtolower(Str::of($name)->squish()->ascii()->toString());
    }
}
