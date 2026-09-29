<?php

namespace App\Models;

use App\Modules\ProviderPayments\Services\ProviderZone;
use Database\Factories\AcuerdoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Acuerdo extends Model
{
    /** @use HasFactory<AcuerdoFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $agreement): void {
            $agreement->zona = ProviderZone::resolve($agreement->rut_proveedor_origen, $agreement->provider_id, $agreement->zona);
        });
    }

    protected function casts(): array
    {
        return ['costo' => 'integer', 'dias_calendario' => 'integer', 'inasistencias' => 'integer',
            'adicionales' => 'integer', 'cantidad' => 'integer', 'factor' => 'integer', 'total' => 'integer',
            'closed_at' => 'datetime'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
