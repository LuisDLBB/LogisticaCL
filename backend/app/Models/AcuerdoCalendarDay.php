<?php

namespace App\Models;

use Database\Factories\AcuerdoCalendarDayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcuerdoCalendarDay extends Model
{
    /** @use HasFactory<AcuerdoCalendarDayFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'es_feriado' => 'boolean', 'bloqueado' => 'boolean'];
    }
}
