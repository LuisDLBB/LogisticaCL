<?php

namespace App\Models;

use Database\Factories\TipoCuentaBancariaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TipoCuentaBancaria extends Model
{
    /** @use HasFactory<TipoCuentaBancariaFactory> */
    use HasFactory;

    protected $table = 'PPR_tipos_cuenta_bancaria';

    protected $primaryKey = 'id_tipo_cuenta';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id_tipo_cuenta', 'tipo_cuenta', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
