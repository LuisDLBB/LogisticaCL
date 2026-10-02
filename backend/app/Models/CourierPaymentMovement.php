<?php

namespace App\Models;

use App\Modules\ProviderPayments\Services\CalamaProviderTransition;
use App\Modules\ProviderPayments\Services\ProviderZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourierPaymentMovement extends Model
{
    protected $table = 'PPR_Pago_Movimientos_Courier';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $payment): void {
            $tracking = MaestroPago::trackingKey((string) $payment->seguimiento_paquete);
            if ($tracking !== '' && MaestroPago::query()->whereKey($tracking)->exists()) {
                throw ValidationException::withMessages(['seguimiento_paquete' => "El seguimiento {$tracking} ya está cerrado en Maestro_Pagos."]);
            }
            if (DB::table('PPR_Cierres_Pagos')->where('tenant_id', $payment->tenant_id)->where('periodo', $payment->periodo)->exists()) {
                throw ValidationException::withMessages(['periodo' => "El período {$payment->periodo} tiene un cierre definitivo."]);
            }
            $transition = app(CalamaProviderTransition::class);
            $providerRut = $transition->providerRut((string) $payment->periodo, (string) ($payment->tipo_pago ?: $payment->nombre_proceso), $payment->comuna_destino, $payment->nombre_repartidor);
            if ($providerRut !== null) {
                $provider = Provider::query()->where('tenant_id', $payment->tenant_id)->where('tax_id', $providerRut)->first();
                if ($provider === null) {
                    throw ValidationException::withMessages(['rut_proveedor' => "Falta el proveedor {$providerRut} para {$payment->comuna_destino}. Revisa Proveedores antes de grabar."]);
                }
                $payment->provider_id = $provider->id;
                $payment->rut_proveedor = $provider->tax_id;
                $payment->razon_social_proveedor = $provider->legal_name;
                $payment->nombre_operacional = $provider->operational_name;
                $payment->tipo_documento = $provider->tax_document_type;
            }
            $payment->zona = ProviderZone::resolve($payment->rut_proveedor, $payment->provider_id, $payment->zona);
            $payment->nombre_proceso = self::withoutPeriodPrefix($payment->nombre_proceso);
        });
    }

    public static function withoutPeriodPrefix(?string $processName): ?string
    {
        return $processName === null ? null : preg_replace('/^\d{6}-/', '', $processName);
    }

    public function courierMovement(): BelongsTo
    {
        return $this->belongsTo(CourierMovement::class);
    }

    public function scopeExcludingSpecials(Builder $query): Builder
    {
        return $query->whereNotIn('tipo_pago', ['Especiales', 'Ruta CV', 'Servicios', 'Acuerdos', 'Apoyo Alza', 'Visitas Diarias'])
            ->whereNotIn('nombre_proceso', ['Especiales', 'Ruta CV', 'Servicios', 'Acuerdos', 'Apoyo', 'Visitas'])
            ->where('nombre_proceso', 'not like', '%-Especiales')
            ->where('nombre_proceso', 'not like', '%-Ruta CV')
            ->where('nombre_proceso', 'not like', '%-Servicios')
            ->where('nombre_proceso', 'not like', '%-Acuerdos')
            ->where('nombre_proceso', 'not like', '%-Apoyo')
            ->where('nombre_proceso', 'not like', '%-Visitas');
    }

    protected function casts(): array
    {
        return ['fecha' => 'date', 'direccion' => 'encrypted', 'peso_final' => 'integer', 'valor' => 'integer'];
    }
}
