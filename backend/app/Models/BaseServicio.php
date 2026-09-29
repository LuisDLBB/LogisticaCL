<?php

namespace App\Models;

use App\Modules\ProviderPayments\Services\ProviderZone;
use Database\Factories\BaseServicioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BaseServicio extends Model
{
    /** @use HasFactory<BaseServicioFactory> */
    use HasFactory;

    protected $table = 'Base_Servicios';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $service): void {
            $service->zona = ProviderZone::resolve($service->rut_proveedor, $service->provider_id, $service->zona);
        });
    }

    protected function casts(): array
    {
        return ['fecha_carga' => 'date', 'peso' => 'decimal:3', 'valor_final' => 'integer', 'closed_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
