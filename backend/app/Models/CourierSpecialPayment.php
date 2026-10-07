<?php

namespace App\Models;

use Database\Factories\CourierSpecialPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierSpecialPayment extends Model
{
    protected $table = 'PPR_courier_special_payments';

    /** @use HasFactory<CourierSpecialPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'provider_id', 'client_id', 'service_type_id', 'periodo', 'fecha', 'usuario_ingresa', 'autoriza', 'agente', 'zona_tipo',
        'codigo_seguimiento', 'localidad', 'cliente', 'descripcion', 'monto',
        'archivo_origen', 'hash_archivo', 'fila_origen', 'finalized_tracking_number', 'finalized_at', 'payment_before_finalization',
    ];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'monto' => 'integer', 'finalized_at' => 'datetime', 'payment_before_finalization' => 'array'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }
}
