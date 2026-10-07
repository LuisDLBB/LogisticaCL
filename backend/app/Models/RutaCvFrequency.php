<?php

namespace App\Models;

use Database\Factories\RutaCvFrequencyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RutaCvFrequency extends Model
{
    protected $table = 'PPR_ruta_cv_frequencies';

    /** @use HasFactory<RutaCvFrequencyFactory> */
    use HasFactory;

    protected $fillable = ['tenant_id', 'name', 'name_key', 'weekdays'];

    protected function casts(): array
    {
        return ['weekdays' => 'array'];
    }
}
