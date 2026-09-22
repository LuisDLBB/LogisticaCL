<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RealWeight extends Model
{
    protected $table = 'peso_real';

    protected $fillable = [
        'tenant_id', 'seguimiento_paquete', 'peso_real', 'codigo_seguimiento',
        'fecha_proceso', 'comerciante', 'servicio',
    ];

    protected function casts(): array
    {
        return ['peso_real' => 'integer', 'fecha_proceso' => 'date'];
    }
}
