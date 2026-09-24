<?php

namespace App\Models;

use Database\Factories\CourierSpecialPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourierSpecialPayment extends Model
{
    /** @use HasFactory<CourierSpecialPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'periodo', 'fecha', 'usuario_ingresa', 'autoriza', 'agente', 'zona_tipo',
        'codigo_seguimiento', 'localidad', 'cliente', 'descripcion', 'monto',
        'archivo_origen', 'hash_archivo', 'fila_origen',
    ];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'monto' => 'integer'];
    }
}
