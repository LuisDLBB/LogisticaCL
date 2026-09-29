<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\CourierPaymentMovement;
use App\Models\CourierSpecialPayment;
use App\Models\MaestroPago;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourierSpecialPaymentProviderCorrection
{
    public function correct(int $tenantId, int $specialId, int $providerId): void
    {
        DB::transaction(function () use ($tenantId, $specialId, $providerId): void {
            $special = CourierSpecialPayment::query()->where('tenant_id', $tenantId)
                ->whereKey($specialId)->lockForUpdate()->firstOrFail();
            MonthlyPaymentClosingService::assertOpen($tenantId, substr($special->periodo, 0, 6));
            if ($special->finalized_at === null || ! $special->finalized_tracking_number) {
                throw ValidationException::withMessages(['payment' => 'El pago especial aún no está finalizado. Corrige el proveedor desde su fila.']);
            }

            $tracking = MaestroPago::trackingKey($special->finalized_tracking_number);
            if (MaestroPago::query()->whereKey($tracking)->exists()) {
                throw ValidationException::withMessages(['payment' => "El seguimiento {$tracking} ya está cerrado en Maestro_Pagos."]);
            }

            $provider = Provider::query()->where('tenant_id', $tenantId)->findOrFail($providerId);
            $payment = CourierPaymentMovement::query()->where('tenant_id', $tenantId)
                ->where('periodo', substr($special->periodo, 0, 6))
                ->where('nombre_proceso', 'Especiales')
                ->whereRaw('UPPER(TRIM(seguimiento_paquete)) = ?', [$tracking])
                ->lockForUpdate()->first();
            if (! $payment || $payment->updated_at?->toDateTimeString() !== $special->finalized_at->toDateTimeString()) {
                throw ValidationException::withMessages(['payment' => 'El pago especial cambió después de finalizarse o ya no existe. No se corrigió ningún dato.']);
            }

            $payment->update([
                'provider_id' => $provider->id,
                'razon_social_proveedor' => $provider->legal_name,
                'rut_proveedor' => $provider->tax_id,
                'nombre_operacional' => $provider->operational_name,
                'tipo_documento' => $provider->tax_document_type,
            ]);
            $special->update([
                'provider_id' => $provider->id,
                'finalized_at' => $payment->updated_at,
            ]);
        });
    }
}
