<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetFeasibility extends Model
{
    protected $fillable = ['tenant_id', 'client_id', 'service_type_id', 'created_by_user_id', 'service_request_id', 'status', 'operational_result', 'requires_finance', 'is_test', 'request_data', 'original_request_data', 'operations_data', 'original_operations_data', 'finance_data', 'original_finance_data', 'proposed_price'];

    protected function casts(): array
    {
        return ['requires_finance' => 'boolean', 'is_test' => 'boolean', 'request_data' => 'array', 'original_request_data' => 'array', 'operations_data' => 'array', 'original_operations_data' => 'array', 'finance_data' => 'array', 'original_finance_data' => 'array', 'proposed_price' => 'decimal:2'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
