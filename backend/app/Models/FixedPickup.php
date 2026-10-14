<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixedPickup extends Model
{
    protected $fillable = [
        'tenant_id', 'client_id', 'client_branch_id', 'service_type_id', 'source_client_name',
        'source_point_name', 'association_status', 'weekdays', 'frequency_label', 'shift',
        'window_start', 'window_end', 'material', 'usual_packages', 'operational_emails',
        'operational_email_pending', 'usual_driver_user_id', 'usual_vehicle_id', 'is_active', 'source_row',
    ];

    protected function casts(): array
    {
        return ['weekdays' => 'array', 'is_active' => 'boolean', 'operational_email_pending' => 'boolean'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(ClientBranch::class, 'client_branch_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }
}
