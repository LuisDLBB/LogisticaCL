<?php

namespace App\Models;

use Database\Factories\AcuerdoServiceRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcuerdoServiceRule extends Model
{
    /** @use HasFactory<AcuerdoServiceRuleFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['dias_semana' => 'array', 'cantidad_fija' => 'integer'];
    }
}
