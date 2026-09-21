<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = [
        'tenant_id',
        'tax_id',
        'tax_id_number',
        'tax_id_check_digit',
        'source_merchant_name',
        'billing_company_code',
        'commercial_name',
        'legal_name',
        'billing_address',
        'billing_commune_name',
        'business_activity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(ClientBranch::class);
    }

    public function courierMovements(): HasMany
    {
        return $this->hasMany(CourierMovement::class);
    }

    public function serviceTypes(): BelongsToMany
    {
        return $this->belongsToMany(ServiceType::class, 'client_service_type')
            ->withPivot('is_active')
            ->withTimestamps();
    }
}
