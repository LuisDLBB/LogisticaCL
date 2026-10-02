<?php

namespace App\Models;

use Database\Factories\CostCenterKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostCenterKey extends Model
{
    /** @use HasFactory<CostCenterKeyFactory> */
    use HasFactory;

    protected $table = 'PPR_llave_centro_costos';

    protected $fillable = [
        'tenant_id', 'provider_id', 'client_id', 'service_type_id', 'provider_tax_id', 'agent_name',
        'client_tax_id', 'merchant_name', 'service_code', 'service_name', 'key_code', 'key_text',
        'payment_status', 'cost_center_code', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_code', 'cost_center_code');
    }
}
