<?php

namespace App\Models;

use Database\Factories\CostCenterWeightRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostCenterWeightRate extends Model
{
    /** @use HasFactory<CostCenterWeightRateFactory> */
    use HasFactory;

    protected $fillable = ['cost_center_code', 'final_weight', 'value', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_code', 'cost_center_code');
    }
}
