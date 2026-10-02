<?php

namespace App\Models;

use App\Modules\ProviderPayments\Services\ProviderZone;
use Database\Factories\VisitaDiariaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitaDiaria extends Model
{
    /** @use HasFactory<VisitaDiariaFactory> */
    use HasFactory;

    protected $table = 'PPR_Visitas_Diarias';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $visit): void {
            $visit->zona = ProviderZone::resolve($visit->rut_proveedor_origen, $visit->provider_id, $visit->zona);
        });
    }

    protected function casts(): array
    {
        return ['dias' => 'array', 'valor_dia' => 'integer', 'total_mensual' => 'integer', 'closed_at' => 'datetime'];
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
