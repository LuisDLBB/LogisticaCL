<?php

namespace App\Models;

use Database\Factories\TipoEnvioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TipoEnvio extends Model
{
    /** @use HasFactory<TipoEnvioFactory> */
    use HasFactory;

    protected $fillable = ['tipo_envio', 'glosa', 'detalle', 'ejemplo'];
}
