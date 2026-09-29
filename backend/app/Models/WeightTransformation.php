<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeightTransformation extends Model
{
    protected $fillable = ['tenant_id', 'source_weight', 'comparison_key', 'transformed_weight', 'is_active'];

    public static function integerPart(string $value): ?int
    {
        return preg_match('/^\s*(\d+)(?:[.,]\d+)?(?:\s*kg)?\s*$/iu', $value, $matches)
            ? (int) $matches[1]
            : null;
    }

    protected function casts(): array
    {
        return ['transformed_weight' => 'integer', 'is_active' => 'boolean'];
    }
}
