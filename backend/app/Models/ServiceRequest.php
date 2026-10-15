<?php

namespace App\Models;

use App\Fleet\RetCode;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceRequest extends Model
{
    protected $fillable = [
        'late_pickup_authorization', 'original_data', 'requesting_area', 'pickup_kind', 'operations_notes',
        'requested_volume_m3', 'requested_weight_kg', 'load_unit', 'pallet_type', 'pallet_height_cm',
        'pickup_name', 'commune_snapshot', 'access_reference_snapshot', 'contact_name_snapshot', 'contact_phone_snapshot',
        'cancellation_reason', 'cancelled_at', 'is_test', 'driver_token_hash', 'driver_token_expires_at', 'guide_number', 'actual_packages', 'route_notes',
        'ret_code', 'tenant_id', 'client_id', 'client_branch_id', 'service_type_id', 'fixed_pickup_id', 'fixed_occurrence_key',
        'requested_by_user_id', 'status', 'requested_date_original', 'service_date', 'shift',
        'window_start', 'window_end', 'packages', 'material', 'client_name_snapshot',
        'legal_name_snapshot', 'point_name_snapshot', 'address_snapshot', 'effective_address',
        'operational_emails_snapshot', 'address_override_reason', 'address_override_by_user_id',
        'address_override_at', 'notes', 'scheduled_at', 'retired_at', 'email_status',
        'email_subject', 'email_body', 'driver_user_id', 'vehicle_id', 'assigned_by_user_id',
        'assigned_at', 'email_to_snapshot', 'email_cc_snapshot', 'email_prepared_at',
    ];

    protected function retCode(): Attribute
    {
        return Attribute::make(get: fn (?string $value): ?string => preg_match('/^RET-[0-9]+$/', (string) $value) && $this->getKey()
            ? RetCode::format((int) $this->getKey()) : $value);
    }

    protected function casts(): array
    {
        return [
            'pallet_height_cm' => 'decimal:2',
            'late_pickup_authorization' => 'array', 'original_data' => 'array', 'is_test' => 'boolean', 'driver_token_expires_at' => 'datetime', 'cancelled_at' => 'datetime',
            'requested_date_original' => 'date', 'service_date' => 'date',
            'scheduled_at' => 'datetime', 'retired_at' => 'datetime',
            'address_override_at' => 'datetime', 'assigned_at' => 'datetime',
            'email_prepared_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
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

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function reprogrammings(): HasMany
    {
        return $this->hasMany(ServiceRequestReprogramming::class);
    }
}
