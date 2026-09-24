<?php

namespace App\Modules\ProviderPayments\Services;

use App\Models\CourierPaymentMovement;
use Illuminate\Support\Collection;

class CourierPaymentSummary
{
    public function forPeriod(?int $tenantId, string $period): Collection
    {
        if ($tenantId === null || $period === '') {
            return collect();
        }

        return CourierPaymentMovement::query()
            ->where('tenant_id', $tenantId)->where('periodo', $period)
            ->selectRaw("CASE WHEN UPPER(TRIM(zona)) = 'RM' THEN 'RM' WHEN zona IS NULL OR TRIM(zona) = '' THEN 'Sin zona' ELSE 'Regiones' END AS grupo_zona, condicion_pago, COUNT(*) AS total, SUM(CASE WHEN condicion_pago = 'SI' THEN COALESCE(valor, 0) ELSE 0 END) AS neto_considerado")
            ->groupBy('grupo_zona', 'condicion_pago')->get()
            ->groupBy('grupo_zona');
    }
}
