<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RealWeight extends Model
{
    protected $table = 'peso_real';

    protected $fillable = [
        'tenant_id', 'seguimiento_paquete', 'peso_real', 'talla', 'codigo_seguimiento',
        'fecha_proceso', 'comerciante', 'servicio', 'cliente_origen',
        'operario', 'observacion', 'guia_cliente',
    ];

    protected function casts(): array
    {
        return ['peso_real' => 'integer', 'fecha_proceso' => 'date'];
    }
}
