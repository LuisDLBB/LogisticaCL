<?php

namespace App\Models;

use Database\Factories\ExternalShipmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalShipment extends Model
{
    /** @use HasFactory<ExternalShipmentFactory> */
    use HasFactory;

    protected $table = 'envios_externos';

    protected $fillable = [
        'tenant_id', 'client_id', 'fecha', 'tracking_number', 'external_order_number',
        'external_courier_name', 'destination_locality_name', 'delivery_point', 'client_name_source',
        'exclude_provider_payment', 'observacion',
    ];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'exclude_provider_payment' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
