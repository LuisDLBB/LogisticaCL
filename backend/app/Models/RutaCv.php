<?php

namespace App\Models;

use App\Modules\ProviderPayments\Services\ProviderZone;
use Database\Factories\RutaCvFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RutaCv extends Model
{
    /** @use HasFactory<RutaCvFactory> */
    use HasFactory;

    protected $table = 'PPR_Rutas_CV';

    protected $fillable = [
        'tenant_id', 'periodo', 'route_key', 'proceso', 'zona', 'servicio', 'frecuencia',
        'facturador', 'usuario', 'detalle_ruta', 'comuna', 'producto', 'valor', 'agente',
        'provider_id', 'rut_proveedor', 'razon_social_proveedor', 'nombre_pila_proveedor',
        'dias', 'inasistencia', 'tipo_cobro', 'monto_fijo', 'total_mensual',
        'observacion', 'origen', 'fila_origen', 'closed_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $route): void {
            $route->zona = ProviderZone::resolve($route->rut_proveedor, $route->provider_id, $route->zona);
        });
    }

    protected function casts(): array
    {
        return ['dias' => 'array', 'valor' => 'integer', 'monto_fijo' => 'integer', 'total_mensual' => 'integer', 'closed_at' => 'datetime'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
