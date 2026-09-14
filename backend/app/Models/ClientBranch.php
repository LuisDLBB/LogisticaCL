<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientBranch extends Model
{
    protected $fillable = [
        'client_id',
        'code',
        'name',
        'branch_type',
        'address',
        'commune_name',
        'region_name',
        'location_reference',
        'service_schedule',
        'delivery_instructions',
        'latitude',
        'longitude',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ClientBranchContact::class);
    }
}
