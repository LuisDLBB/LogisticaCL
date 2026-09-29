<?php

namespace App\Models;

use Database\Factories\ApoyoAlzaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApoyoAlza extends Model
{
    /** @use HasFactory<ApoyoAlzaFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'porcentaje' => 'decimal:6',
            'monto_dia' => 'integer',
            'registros_base' => 'integer',
            'monto_base' => 'integer',
            'dias_base' => 'integer',
            'monto_apoyo' => 'integer',
            'calculado_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
