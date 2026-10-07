<?php

namespace App\Models;

use App\Modules\ProviderPayments\Services\ProviderZone;
use Database\Factories\CoverageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Coverage extends Model
{
    protected $table = 'PPR_coverages';

    /** @use HasFactory<CoverageFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'provider_id', 'commune_name', 'matrix_commune_name',
        'provider_tax_id', 'provider_name_source', 'zone', 'return_payment_applies', 'return_value',
        'delivery_frequency', 'delivery_type', 'region_code', 'route_code', 'consideration_code',
        'aerial_commune_name', 'aerial_route_code', 'base_commune_name', 'trunk_name', 'post_name',
        'trunk_delivery_order', 'effective_from', 'effective_to', 'is_active', 'ID_ComunaMatrizAgencia',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $coverage): void {
            $coverage->zone = ProviderZone::resolve($coverage->provider_tax_id, $coverage->provider_id, $coverage->zone);
        });
    }

    protected function casts(): array
    {
        return [
            'return_payment_applies' => 'boolean',
            'return_value' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
            'ID_ComunaMatrizAgencia' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
