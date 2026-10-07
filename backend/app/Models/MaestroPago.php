<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaestroPago extends Model
{
    protected $table = 'PPR_Maestro_Pagos';

    protected $primaryKey = 'seguimiento_paquete';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function trackingKey(string $tracking): string
    {
        return strtoupper(trim($tracking));
    }
}
