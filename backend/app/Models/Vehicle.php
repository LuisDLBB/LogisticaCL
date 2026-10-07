<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vehicle extends Model
{
    protected $table = 'MBA_vehicles';

    protected $fillable = [
        'tenant_id',
        'rut_empresa',
        'internal_code',
        'plate',
        'vehicle_type',
        'ownership_type',
        'operational_status',
        'brand',
        'model',
        'manufacture_year',
        'color',
        'vin',
        'max_weight_kg',
        'max_volume_liters',
        'max_pallets',
        'odometer_km',
        'technical_inspection_expires_at',
        'circulation_permit_expires_at',
        'insurance_expires_at',
        'next_maintenance_at',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'manufacture_year' => 'integer',
            'max_weight_kg' => 'integer',
            'max_volume_liters' => 'integer',
            'max_pallets' => 'integer',
            'odometer_km' => 'integer',
            'technical_inspection_expires_at' => 'date',
            'circulation_permit_expires_at' => 'date',
            'insurance_expires_at' => 'date',
            'next_maintenance_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
