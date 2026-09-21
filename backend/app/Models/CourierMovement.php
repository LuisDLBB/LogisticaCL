<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CourierMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierMovement extends Model
{
    /** @use HasFactory<CourierMovementFactory> */
    use HasFactory;

    protected $table = 'movimientos_courier';

    protected $fillable = [
        'tenant_id', 'client_id', 'source_system', 'fecha', 'tracking_number', 'tracking_code', 'external_code',
        'cost_center', 'purchase_order', 'dispatch_guide', 'weight_kg', 'peso_real', 'peso_transformado', 'peso_final', 'tipo_pago', 'nombre_proceso', 'length_cm', 'width_cm', 'height_cm',
        'status', 'delivery_attempts', 'merchant_name', 'service_name', 'campaign_name', 'recipient_name',
        'recipient_company_name', 'recipient_address', 'destination_commune_name', 'recipient_phone',
        'recipient_email', 'declared_value', 'received_at', 'estimated_delivery_date', 'delivered_at',
        'merchant_pickup', 'pickup_warehouse_name', 'delivery_route_code', 'courier_name', 'courier_phone',
        'delivery_user_name',
    ];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'decimal:3',
            'peso_real' => 'integer',
            'peso_transformado' => 'integer',
            'peso_final' => 'integer',
            'length_cm' => 'decimal:2',
            'width_cm' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'fecha' => 'date',
            'recipient_name' => 'encrypted',
            'recipient_company_name' => 'encrypted',
            'recipient_address' => 'encrypted',
            'recipient_phone' => 'encrypted',
            'recipient_email' => 'encrypted',
            'declared_value' => 'decimal:2',
            'received_at' => 'datetime',
            'estimated_delivery_date' => 'date',
            'delivered_at' => 'datetime',
            'merchant_pickup' => 'boolean',
            'courier_phone' => 'encrypted',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected static function booted(): void
    {
        static::saving(function (CourierMovement $movement): void {
            if ($movement->weight_kg === null) {
                $movement->weight_kg = 1;
            }

            $movement->peso_final = self::pesoFinal($movement->peso_real, $movement->peso_transformado);
        });
    }

    public static function fechaFromTrackingNumber(string $trackingNumber): ?CarbonImmutable
    {
        $year = substr($trackingNumber, 2, 4);
        $month = substr($trackingNumber, 6, 2);
        $day = substr($trackingNumber, 8, 2);

        if (! ctype_digit($year) || ! ctype_digit($month) || ! ctype_digit($day)
            || ! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return CarbonImmutable::create((int) $year, (int) $month, (int) $day);
    }

    public static function pesoFinal(?int $pesoReal, ?int $pesoTransformado): ?int
    {
        if ($pesoReal === null || $pesoTransformado === null) {
            return null;
        }

        return min($pesoReal, $pesoTransformado);
    }
}
