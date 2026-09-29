<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\Client;
use App\Models\CourierMovement;
use App\Models\CourierPaymentMovement;
use App\Models\CourierSpecialPayment;
use App\Models\Coverage;
use App\Models\Provider;
use App\Models\ServiceType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CourierSpecialPaymentFinalizer
{
    /** @return array{updated: int, created: int} */
    public function finalize(int $tenantId, string $selectedPeriod): array
    {
        MonthlyPaymentClosingService::assertOpen($tenantId, substr($selectedPeriod, 0, 6));
        $period = substr($selectedPeriod, 0, 6);

        return DB::transaction(function () use ($tenantId, $selectedPeriod, $period): array {
            $specials = CourierSpecialPayment::query()->where('tenant_id', $tenantId)
                ->where('periodo', $selectedPeriod)->orderBy('id')->lockForUpdate()->get();
            if ($specials->isEmpty()) {
                throw ValidationException::withMessages(['periodo' => 'No hay pagos especiales para finalizar en este período.']);
            }
            if ($specials->contains(fn (CourierSpecialPayment $special): bool => $special->finalized_at !== null)) {
                throw ValidationException::withMessages(['periodo' => 'Este período ya contiene pagos finalizados. Reábrelo con la clave maestra antes de volver a finalizar.']);
            }

            $incomplete = $specials->filter(fn (CourierSpecialPayment $special): bool => ! $special->provider_id || ! $special->client_id || ! $special->service_type_id
            );
            if ($incomplete->isNotEmpty()) {
                throw ValidationException::withMessages(['periodo' => $incomplete->count().' pagos especiales aún necesitan proveedor, cliente o servicio.']);
            }

            $providers = Provider::query()->where('tenant_id', $tenantId)
                ->whereIn('id', $specials->pluck('provider_id'))->get()->keyBy('id');
            $clients = Client::query()->where('tenant_id', $tenantId)
                ->whereIn('id', $specials->pluck('client_id'))->get()->keyBy('id');
            $services = ServiceType::query()->whereIn('id', $specials->pluck('service_type_id'))->get()->keyBy('id');
            $coverages = Coverage::query()->where('tenant_id', $tenantId)->where('is_active', true)->get();
            $nextSequence = $this->nextSequence($tenantId);
            $result = ['updated' => 0, 'created' => 0];

            foreach ($specials as $special) {
                $provider = $providers->get($special->provider_id);
                $client = $clients->get($special->client_id);
                $service = $services->get($special->service_type_id);
                if (! $provider || ! $client || ! $service) {
                    throw ValidationException::withMessages(['periodo' => "El registro {$special->id} tiene una asociación que ya no existe."]);
                }

                $sourceTracking = trim((string) $special->codigo_seguimiento);
                if ($sourceTracking === '' || strtoupper($sourceTracking) === 'N/A') {
                    $sourceTracking = null;
                }
                $tracking = $special->finalized_tracking_number ?: $sourceTracking;
                $matches = $tracking === null ? collect() : CourierPaymentMovement::query()
                    ->where('tenant_id', $tenantId)->where('seguimiento_paquete', $tracking)
                    ->lockForUpdate()->get();
                if ($tracking !== null && $matches->isEmpty()) {
                    $matches = CourierPaymentMovement::query()->where('tenant_id', $tenantId)
                        ->whereRaw('UPPER(TRIM(seguimiento_paquete)) = ?', [mb_strtoupper(trim($tracking))])
                        ->lockForUpdate()->get();
                }
                if ($matches->count() > 1) {
                    throw ValidationException::withMessages(['periodo' => "El seguimiento {$tracking} aparece más de una vez en movimientos de pago."]);
                }
                $payment = $matches->first();
                if ($payment?->ruta_cv_id !== null) {
                    throw ValidationException::withMessages(['periodo' => "El seguimiento {$tracking} pertenece a un período cerrado de Ruta CV y no se puede modificar desde Especiales."]);
                }
                if ($payment !== null && $special->payment_before_finalization === null) {
                    $syntheticMovement = CourierMovement::query()->where('tenant_id', $tenantId)
                        ->whereKey($payment->courier_movement_id)->where('nombre_proceso', $selectedPeriod)
                        ->where('source_system', 'Especiales')->exists();
                    if (! $syntheticMovement) {
                        if ($special->finalized_at !== null) {
                            throw ValidationException::withMessages(['periodo' => "El registro {$special->id} fue finalizado sin copia previa. Debe recuperarse antes de finalizar nuevamente."]);
                        }
                        $special->update(['payment_before_finalization' => $payment->getRawOriginal()]);
                    }
                }

                $matrixCommune = trim((string) $payment?->comuna_matriz);
                $zone = trim((string) $payment?->zona);
                $agentCommune = trim(preg_replace('/^operador\s+/iu', '', $special->agente) ?? '');
                $agentCoverages = $coverages->filter(fn (Coverage $coverage): bool => $this->communeKey($coverage->commune_name) === $this->communeKey($agentCommune));
                $providerCoverages = $agentCoverages->filter(fn (Coverage $coverage): bool => $coverage->provider_id === $provider->id || $coverage->provider_tax_id === $provider->tax_id);
                $candidatesByPriority = [$providerCoverages];
                if ($matrixCommune === '') {
                    $candidatesByPriority[] = $coverages->filter(fn (Coverage $coverage): bool => $this->communeKey($coverage->commune_name) === $this->communeKey($special->localidad));
                    $candidatesByPriority[] = $agentCoverages;
                }
                foreach ($candidatesByPriority as $candidates) {
                    $matrices = $candidates->pluck('matrix_commune_name')->filter()->unique()->values();
                    if ($matrices->count() === 1) {
                        $matrixCommune = $matrices->first();
                        $zones = $candidates->pluck('zone')->filter()->unique()->values();
                        $zone = $zones->count() === 1 ? $zones->first() : $zone;
                        break;
                    }
                }
                if ($matrixCommune === '') {
                    throw ValidationException::withMessages(['periodo' => "El registro {$special->id} no tiene una comuna matriz única en Coberturas ni en su movimiento de pago. Revisa el agente y la localidad."]);
                }
                $zone = $zone !== '' ? $zone : $this->zone($special->zona_tipo);

                if ($payment === null) {
                    $tracking = sprintf('ESP-%s-%04d', $special->fecha->format('Ymd'), $nextSequence++);
                    $address = implode(' / ', [$special->agente, $special->localidad, $special->descripcion ?? '', $special->autoriza]);
                    $movement = CourierMovement::query()->create([
                        'tenant_id' => $tenantId,
                        'client_id' => $client->id,
                        'source_system' => 'Especiales',
                        'tracking_number' => $tracking,
                        'tracking_code' => $tracking,
                        'fecha' => $special->fecha,
                        'nombre_proceso' => $selectedPeriod,
                        'tipo_pago' => 'Especiales',
                        'weight_kg' => 1,
                        'peso_final' => 1,
                        'merchant_name' => $client->source_merchant_name ?: $client->commercial_name,
                        'service_name' => $service->name,
                        'destination_commune_name' => $matrixCommune,
                        'recipient_address' => $address,
                        'courier_name' => $provider->operational_name ?: $provider->legal_name,
                        'delivery_user_name' => $special->usuario_ingresa,
                    ]);
                    $payment = CourierPaymentMovement::query()->create([
                        'tenant_id' => $tenantId,
                        'courier_movement_id' => $movement->id,
                        'tipo_pago' => 'Especiales',
                        'nombre_proceso' => 'Especiales',
                        'periodo' => $period,
                        'seguimiento_paquete' => $tracking,
                        'fecha' => $special->fecha,
                        'direccion' => $address,
                        'comuna_destino' => $matrixCommune,
                        'peso_final' => 1,
                        'estado_envio' => 'Especiales',
                        'nombre_repartidor' => $provider->operational_name ?: $provider->legal_name,
                        'usuario_entrega' => $special->usuario_ingresa,
                    ]);
                    $result['created']++;
                } else {
                    $tracking = $payment->seguimiento_paquete;
                    $result['updated']++;
                }

                $payment->update([
                    'zona' => $zone,
                    'comuna_matriz' => $matrixCommune,
                    'tipo_pago' => 'Especiales',
                    'nombre_proceso' => 'Especiales',
                    'periodo' => $period,
                    'comerciante_pila' => $client->source_merchant_name ?: $client->commercial_name,
                    'client_id' => $client->id,
                    'rut_cliente' => $client->tax_id,
                    'razon_social_cliente' => $client->legal_name,
                    'provider_id' => $provider->id,
                    'razon_social_proveedor' => $provider->legal_name,
                    'rut_proveedor' => $provider->tax_id,
                    'nombre_operacional' => $provider->operational_name,
                    'tipo_documento' => $provider->tax_document_type,
                    'service_type_id' => $service->id,
                    'service_code' => $service->service_code,
                    'service_name' => $service->name,
                    'condicion_pago' => 'SI',
                    'valor' => $special->monto,
                    'empresa_mandante' => '4N',
                ]);
                $special->update(['finalized_tracking_number' => $tracking, 'finalized_at' => $payment->updated_at]);
            }

            return $result;
        });
    }

    private function nextSequence(int $tenantId): int
    {
        $highest = 0;
        $codes = CourierMovement::query()->where('tenant_id', $tenantId)
            ->where('tracking_number', 'like', 'ESP-%')->pluck('tracking_number')
            ->merge(CourierPaymentMovement::query()->where('tenant_id', $tenantId)
                ->where('seguimiento_paquete', 'like', 'ESP-%')->pluck('seguimiento_paquete'));
        foreach ($codes as $tracking) {
            if (preg_match('/^ESP-\d{8}-(\d{4,})$/', $tracking, $matches)) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return $highest + 1;
    }

    private function communeKey(string $commune): string
    {
        return Str::of($commune)->squish()->lower()->ascii()->toString();
    }

    private function zone(string $source): ?string
    {
        return match (strtoupper(trim($source))) {
            'RM' => 'RM',
            'REG', 'REGIONES' => 'Regiones',
            default => null,
        };
    }
}
