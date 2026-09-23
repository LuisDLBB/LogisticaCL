<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourierPaymentMovement extends Model
{
    protected $table = 'Pago_Movimientos_Courier';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'direccion' => 'encrypted', 'peso_final' => 'integer', 'valor' => 'integer'];
    }
}
