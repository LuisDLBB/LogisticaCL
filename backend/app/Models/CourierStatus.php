<?php

namespace App\Models;

use Database\Factories\CourierStatusFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourierStatus extends Model
{
    /** @use HasFactory<CourierStatusFactory> */
    use HasFactory;

    protected $table = 'PPR_estados';

    protected $fillable = ['name', 'consider_for_payment'];

    protected function casts(): array
    {
        return ['consider_for_payment' => 'boolean'];
    }
}
