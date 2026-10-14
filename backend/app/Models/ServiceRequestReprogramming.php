<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceRequestReprogramming extends Model
{
    protected $fillable = [
        'service_request_id', 'status', 'reason', 'previous_date', 'proposed_date',
        'previous_window_start', 'previous_window_end', 'proposed_window_start',
        'proposed_window_end', 'previous_driver_user_id', 'proposed_driver_user_id',
        'previous_vehicle_id', 'proposed_vehicle_id', 'requested_by_user_id',
        'decided_by_user_id', 'customer_response', 'decision_notes', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['previous_date' => 'date', 'proposed_date' => 'date', 'decided_at' => 'datetime'];
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }
}
