<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleMaintenance extends Model
{
    protected $fillable = [
        'vehicle_id', 'tenant_id', 'maintenance_type', 'execution_type', 'status',
        'scheduled_at', 'reported_odometer_km', 'provider_id', 'estimated_cost',
        'notes', 'next_due_at', 'next_due_km', 'responsible_user_id',
        'started_at', 'closed_at', 'closed_odometer_km', 'actual_cost',
        'document_type', 'document_number', 'closing_notes', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'closed_at' => 'datetime',
            'next_due_at' => 'date', 'reported_odometer_km' => 'integer',
            'closed_odometer_km' => 'integer', 'next_due_km' => 'integer',
            'estimated_cost' => 'decimal:2', 'actual_cost' => 'decimal:2',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceEvent::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FleetDocument::class, 'documentable_id')
            ->where('documentable_type', 'maintenance');
    }
}
