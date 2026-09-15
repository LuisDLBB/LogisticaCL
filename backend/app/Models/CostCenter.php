<?php

namespace App\Models;

use Database\Factories\CostCenterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostCenter extends Model
{
    /** @use HasFactory<CostCenterFactory> */
    use HasFactory;

    protected $fillable = ['cost_center_code', 'dispatch_guide_detail', 'additional_kilo_value', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function paymentKeys(): HasMany
    {
        return $this->hasMany(CostCenterKey::class, 'cost_center_code', 'cost_center_code');
    }
}
