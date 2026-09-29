<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\ApoyoAlza;
use App\Models\Client;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\Provider;
use App\Models\RutaCv;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RutaCvClosingService
{
    public function close(int $tenantId, string $period): int
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);

        return DB::transaction(function () use ($tenantId, $period): int {
            $routes = RutaCv::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                ->orderBy('id')->lockForUpdate()->get();
            if ($routes->isEmpty()) {
                throw ValidationException::withMessages(['periodo' => 'Este período no tiene rutas para cerrar.']);
            }
            if ($routes->contains(fn (RutaCv $route): bool => $route->closed_at !== null)) {
                throw ValidationException::withMessages(['periodo' => 'Este período ya está cerrado.']);
            }

            $process = $period.'-Ruta CV';
            if (CourierPaymentMovement::query()->where('tenant_id', $tenantId)->where('periodo', $period)->where('nombre_proceso', 'Ruta CV')->exists()
                || CourierMovement::query()->where('tenant_id', $tenantId)->where('nombre_proceso', $process)->exists()) {
                throw ValidationException::withMessages(['periodo' => 'Ya existen movimientos de Ruta CV para este período. Revisa el proceso antes de cerrar.']);
            }

            $client = Client::query()->where('tenant_id', $tenantId)->where('tax_id', '89807200-2')->first();
            if ($client === null) {
                throw ValidationException::withMessages(['periodo' => 'Falta el cliente Cruz Verde en el mantenedor.']);
            }
            $providers = Provider::query()->where('tenant_id', $tenantId)
                ->whereIn('id', $routes->pluck('provider_id')->filter()->unique())->get()->keyBy('id');
            $date = CarbonImmutable::create((int) substr($period, 0, 4), (int) substr($period, 4, 2), 1);
            $prefix = 'RCV-'.$date->format('Ymd').'-';
            $nextSequence = CourierMovement::query()->where('tenant_id', $tenantId)
                ->where('tracking_number', 'like', $prefix.'%')->pluck('tracking_number')
                ->map(fn (string $tracking): int => ctype_digit(substr($tracking, strlen($prefix)))
                    ? (int) substr($tracking, strlen($prefix)) : 0)->max() + 1;

            foreach ($routes as $route) {
                $provider = $providers->get($route->provider_id);
                $workedDays = count($route->dias ?? []) - (int) $route->inasistencia;
                if ($provider === null || $workedDays < 1 || $route->total_mensual < 1) {
                    throw ValidationException::withMessages(['periodo' => "La ruta {$route->id} requiere proveedor, días trabajados y un monto mayor que cero antes del cierre."]);
                }
                if ($nextSequence > 9999) {
                    throw ValidationException::withMessages(['periodo' => 'Se agotó el correlativo RCV de cuatro dígitos para este período.']);
                }
                $tracking = $prefix.sprintf('%04d', $nextSequence++);
                $movement = CourierMovement::query()->create([
                    'tenant_id' => $tenantId,
                    'client_id' => $client->id,
                    'source_system' => 'Ruta CV',
                    'tracking_number' => $tracking,
                    'tracking_code' => $tracking,
                    'fecha' => $date,
                    'nombre_proceso' => $process,
                    'tipo_pago' => 'Ruta CV',
                    'weight_kg' => $workedDays,
                    'peso_real' => $workedDays,
                    'merchant_name' => $client->source_merchant_name ?: $client->commercial_name,
                    'service_name' => 'Ruta CV',
                    'status' => 'Entregado',
                    'recipient_address' => $route->detalle_ruta,
                    'destination_commune_name' => $route->comuna,
                    'courier_name' => $route->usuario,
                    'delivery_user_name' => $route->usuario,
                ]);
                CourierPaymentMovement::query()->create([
                    'tenant_id' => $tenantId,
                    'courier_movement_id' => $movement->id,
                    'ruta_cv_id' => $route->id,
                    'zona' => $route->zona,
                    'tipo_pago' => 'Ruta CV',
                    'nombre_proceso' => 'Ruta CV',
                    'periodo' => $period,
                    'seguimiento_paquete' => $tracking,
                    'fecha' => $date,
                    'direccion' => $route->detalle_ruta,
                    'comuna_destino' => $route->comuna,
                    'comerciante_pila' => $client->source_merchant_name ?: $client->commercial_name,
                    'client_id' => $client->id,
                    'rut_cliente' => $client->tax_id,
                    'razon_social_cliente' => $client->legal_name,
                    'peso_final' => $workedDays,
                    'valor' => $route->total_mensual,
                    'estado_envio' => 'Entregado',
                    'condicion_pago' => 'SI',
                    'provider_id' => $provider->id,
                    'razon_social_proveedor' => $provider->legal_name,
                    'rut_proveedor' => $provider->tax_id,
                    'nombre_operacional' => $provider->operational_name,
                    'tipo_documento' => $provider->tax_document_type,
                    'nombre_repartidor' => $route->usuario,
                    'usuario_entrega' => $route->usuario,
                    'empresa_mandante' => '4N',
                ]);
            }

            RutaCv::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                ->update(['closed_at' => now()]);

            return $routes->count();
        });
    }

    public function reopen(int $tenantId, string $period): int
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);

        return DB::transaction(function () use ($tenantId, $period): int {
            if (ApoyoAlza::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                ->where('proceso_base', 'Ruta CV')->whereNotNull('closed_at')->exists()) {
                throw ValidationException::withMessages(['periodo' => 'Primero reabre Apoyo Alza de este período antes de modificar Ruta CV.']);
            }
            $routes = RutaCv::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                ->orderBy('id')->lockForUpdate()->get();
            if ($routes->isEmpty() || $routes->contains(fn (RutaCv $route): bool => $route->closed_at === null)) {
                throw ValidationException::withMessages(['periodo' => 'Este período no está cerrado.']);
            }

            $process = $period.'-Ruta CV';
            $payments = CourierPaymentMovement::query()->where('tenant_id', $tenantId)
                ->where('periodo', $period)->where('nombre_proceso', 'Ruta CV')->lockForUpdate()->get();
            if ($payments->count() !== $routes->count()
                || $payments->pluck('ruta_cv_id')->sort()->values()->all() !== $routes->pluck('id')->sort()->values()->all()) {
                throw ValidationException::withMessages(['periodo' => 'Los pagos de Ruta CV no coinciden con las rutas cerradas. No se reabrió el período.']);
            }
            $movementIds = $payments->pluck('courier_movement_id');
            $sourceCount = CourierMovement::query()->where('tenant_id', $tenantId)
                ->whereIn('id', $movementIds)->where('nombre_proceso', $process)
                ->where('source_system', 'Ruta CV')->count();
            if ($sourceCount !== $routes->count()) {
                throw ValidationException::withMessages(['periodo' => 'Los movimientos de origen de Ruta CV están incompletos. No se reabrió el período.']);
            }

            CourierPaymentMovement::query()->whereIn('id', $payments->pluck('id'))->delete();
            CourierMovement::query()->whereIn('id', $movementIds)->delete();
            RutaCv::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                ->update(['closed_at' => null]);

            return $routes->count();
        });
    }
}
