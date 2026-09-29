<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleMaintenanceEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'vehicle_maintenance_id', 'tenant_id', 'actor_user_id', 'event_type',
        'previous_status', 'new_status', 'odometer_km', 'recorded_at', 'details', 'created_at',
    ];

    protected function casts(): array
    {
        return ['details' => 'array', 'recorded_at' => 'datetime', 'created_at' => 'datetime', 'odometer_km' => 'integer'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
