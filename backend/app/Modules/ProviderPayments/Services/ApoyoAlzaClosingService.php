<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\ApoyoAlza;
use App\Models\Client;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\Coverage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApoyoAlzaClosingService
{
    public function __construct(private readonly ApoyoAlzaCalculator $calculator) {}

    public function close(int $tenantId, string $period): int
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);

        return DB::transaction(function () use ($tenantId, $period): int {
            $rows = ApoyoAlza::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                ->orderBy('id')->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['periodo' => 'Este período no tiene apoyos para cerrar.']);
            }
            if ($rows->contains(fn (ApoyoAlza $row): bool => $row->closed_at !== null)) {
                throw ValidationException::withMessages(['periodo' => 'El período de Apoyo Alza ya está cerrado.']);
            }

            $process = $period.'-Apoyo';
            if (CourierPaymentMovement::query()->where('tenant_id', $tenantId)->where('periodo', $period)->where('nombre_proceso', 'Apoyo')->exists()
                || CourierMovement::query()->where('tenant_id', $tenantId)->where('nombre_proceso', $process)->exists()) {
                throw ValidationException::withMessages(['periodo' => 'Ya existen movimientos de Apoyo Alza para este período. Revisa el proceso antes de cerrar.']);
            }

            $result = $this->calculator->recalculate($tenantId, $period);
            if ($result['pendientes'] > 0) {
                throw ValidationException::withMessages(['periodo' => "Hay {$result['pendientes']} apoyos sin cálculo válido. Corrígelos antes del cierre."]);
            }
            $rows = ApoyoAlza::query()->with('provider')->where('tenant_id', $tenantId)
                ->where('periodo', $period)->orderBy('id')->lockForUpdate()->get();
            $payableRows = $rows->where('estado_calculo', 'calculado');
            $client = Client::query()->where('tenant_id', $tenantId)->where('tax_id', '77346078-7')->first();
            if ($client === null || trim((string) $client->legal_name) === '') {
                throw ValidationException::withMessages(['periodo' => 'Falta el cliente 4 Nortes Logística SpA (RUT 77346078-7) en el mantenedor de Clientes.']);
            }

            $coverageZones = Coverage::query()->where('tenant_id', $tenantId)->where('is_active', true)
                ->whereIn('provider_id', $rows->pluck('provider_id')->filter()->unique())
                ->whereNotNull('zone')->get(['provider_id', 'zone'])->groupBy('provider_id')
                ->map(fn ($coverages) => $coverages->pluck('zone')->map(fn ($zone) => trim((string) $zone))
                    ->filter()->unique()->values());
            $date = CarbonImmutable::create((int) substr($period, 0, 4), (int) substr($period, 4, 2), 1);
            $prefix = 'ALZ-'.$date->format('Ymd').'-';
            $nextSequence = CourierMovement::query()->where('tenant_id', $tenantId)
                ->where('tracking_number', 'like', $prefix.'%')->pluck('tracking_number')
                ->map(fn (string $tracking): int => ctype_digit(substr($tracking, strlen($prefix)))
                    ? (int) substr($tracking, strlen($prefix)) : 0)->max() + 1;
            $merchantName = $client->source_merchant_name ?: $client->commercial_name ?: $client->legal_name;

            foreach ($payableRows as $row) {
                $provider = $row->provider;
                if ($provider === null || $provider->tenant_id !== $tenantId || trim((string) $provider->tax_id) === '') {
                    throw ValidationException::withMessages(['periodo' => "El apoyo de la fila {$row->fila_origen} requiere un proveedor válido."]);
                }
                $zones = $coverageZones->get($provider->id, collect());
                $zone = in_array($provider->operator_type, ['RM', 'Regiones'], true)
                    ? $provider->operator_type : ($zones->count() === 1 ? $zones->first() : null);
                if (! in_array($zone, ['RM', 'Regiones'], true)) {
                    throw ValidationException::withMessages(['periodo' => "El proveedor de la fila {$row->fila_origen} no tiene una zona RM o Regiones única. Revisa su ficha y Coberturas."]);
                }
                if ($row->monto_apoyo === null || $row->monto_apoyo <= 0) {
                    throw ValidationException::withMessages(['periodo' => "El apoyo de la fila {$row->fila_origen} no tiene un monto calculado."]);
                }
                if (trim((string) $row->agencia) === '' || trim((string) $row->empresa_mandante) === ''
                    || mb_strlen($row->empresa_mandante) > 20) {
                    throw ValidationException::withMessages(['periodo' => "La fila {$row->fila_origen} requiere agencia y empresa mandante de hasta 20 caracteres."]);
                }
                if ($nextSequence > 9999) {
                    throw ValidationException::withMessages(['periodo' => 'Se agotó el correlativo ALZ de cuatro dígitos para este período.']);
                }

                $tracking = $prefix.sprintf('%04d', $nextSequence++);
                $movement = CourierMovement::query()->create([
                    'tenant_id' => $tenantId,
                    'client_id' => $client->id,
                    'source_system' => 'Apoyo Alza',
                    'tracking_number' => $tracking,
                    'tracking_code' => $tracking,
                    'fecha' => $date,
                    'nombre_proceso' => $process,
                    'tipo_pago' => 'Apoyo Alza',
                    'weight_kg' => 1,
                    'peso_real' => 1,
                    'merchant_name' => $merchantName,
                    'service_name' => 'Apoyo Alza',
                    'status' => 'Entregado',
                    'recipient_address' => 'Apoyo Alza',
                    'destination_commune_name' => $row->agencia,
                    'courier_name' => null,
                    'delivery_user_name' => null,
                ]);
                CourierPaymentMovement::query()->create([
                    'tenant_id' => $tenantId,
                    'courier_movement_id' => $movement->id,
                    'apoyo_alza_id' => $row->id,
                    'zona' => $zone,
                    'tipo_pago' => 'Apoyo Alza',
                    'nombre_proceso' => 'Apoyo',
                    'periodo' => $period,
                    'seguimiento_paquete' => $tracking,
                    'fecha' => $date,
                    'direccion' => 'Apoyo Alza',
                    'comuna_destino' => $row->agencia,
                    'comerciante_pila' => $merchantName,
                    'client_id' => $client->id,
                    'rut_cliente' => $client->tax_id,
                    'razon_social_cliente' => $client->legal_name,
                    'service_name' => 'Apoyo Alza',
                    'peso_final' => 1,
                    'valor' => $row->monto_apoyo,
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
            }

            ApoyoAlza::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                ->update(['closed_at' => now()]);

            return $payableRows->count();
        });
    }

    public function reopen(int $tenantId, string $period): int
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, $period);

        return DB::transaction(function () use ($tenantId, $period): int {
            $rows = ApoyoAlza::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                ->orderBy('id')->lockForUpdate()->get();
            if ($rows->isEmpty() || $rows->contains(fn (ApoyoAlza $row): bool => $row->closed_at === null)) {
                throw ValidationException::withMessages(['periodo' => 'Este período de Apoyo Alza no está cerrado.']);
            }

            $process = $period.'-Apoyo';
            $payableRows = $rows->where('estado_calculo', 'calculado');
            $payments = CourierPaymentMovement::query()->where('tenant_id', $tenantId)
                ->where('periodo', $period)->where('nombre_proceso', 'Apoyo')->lockForUpdate()->get();
            if ($payments->count() !== $payableRows->count()
                || $payments->pluck('apoyo_alza_id')->sort()->values()->all() !== $payableRows->pluck('id')->sort()->values()->all()) {
                throw ValidationException::withMessages(['periodo' => 'Los pagos de Apoyo Alza no coinciden con el período cerrado. No se reabrió.']);
            }
            $movementIds = $payments->pluck('courier_movement_id');
            if (CourierMovement::query()->where('tenant_id', $tenantId)->whereIn('id', $movementIds)
                ->where('nombre_proceso', $process)->where('source_system', 'Apoyo Alza')->count() !== $payableRows->count()) {
                throw ValidationException::withMessages(['periodo' => 'Los movimientos de origen de Apoyo Alza están incompletos. No se reabrió.']);
            }

            CourierPaymentMovement::query()->whereIn('id', $payments->pluck('id'))->delete();
            CourierMovement::query()->whereIn('id', $movementIds)->delete();
            ApoyoAlza::query()->where('tenant_id', $tenantId)->where('periodo', $period)
                ->update(['closed_at' => null]);

            return $payments->count();
        });
    }
}
