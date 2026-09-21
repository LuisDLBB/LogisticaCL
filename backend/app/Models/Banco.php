<?php

namespace App\Models;

use Database\Factories\BancoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Banco extends Model
{
    /** @use HasFactory<BancoFactory> */
    use HasFactory;

    protected $primaryKey = 'id_banco';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id_banco',
        'banco',
        'codigo_sbif',
        'nombre_entidad_financiera',
        'marcas_productos_asociados',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
