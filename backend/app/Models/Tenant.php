<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'code',
        'name',
        'legal_name',
        'tax_id',
        'timezone',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')
            ->withPivot(['rut_empresa', 'role_code', 'is_active'])
            ->withTimestamps();
    }

    public function providers(): HasMany
    {
        return $this->hasMany(Provider::class);
    }

    public function coverages(): HasMany
    {
        return $this->hasMany(Coverage::class);
    }
}
