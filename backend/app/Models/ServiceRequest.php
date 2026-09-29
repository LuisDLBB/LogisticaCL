<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceRequest extends Model
{
    protected $fillable = [
        'ret_code', 'tenant_id', 'client_id', 'client_branch_id', 'service_type_id', 'fixed_pickup_id', 'fixed_occurrence_key',
        'requested_by_user_id', 'status', 'requested_date_original', 'service_date', 'shift',
        'window_start', 'window_end', 'packages', 'material', 'client_name_snapshot',
        'legal_name_snapshot', 'point_name_snapshot', 'address_snapshot', 'effective_address',
        'operational_emails_snapshot', 'address_override_reason', 'address_override_by_user_id',
        'address_override_at', 'notes', 'scheduled_at', 'retired_at', 'email_status',
        'email_subject', 'email_body',
    ];

    protected function casts(): array
    {
        return [
            'requested_date_original' => 'date', 'service_date' => 'date',
            'scheduled_at' => 'datetime', 'retired_at' => 'datetime',
            'address_override_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(ClientBranch::class, 'client_branch_id');
    }

    public function fixedPickup(): BelongsTo
    {
        return $this->belongsTo(FixedPickup::class);
    }

    public function reprogrammings(): HasMany
    {
        return $this->hasMany(ServiceRequestReprogramming::class);
    }
}
