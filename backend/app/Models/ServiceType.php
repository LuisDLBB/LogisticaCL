<?php

namespace App\Models;

use Database\Factories\ServiceTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ServiceType extends Model
{
    protected $table = 'PPR_service_types';

    /** @use HasFactory<ServiceTypeFactory> */
    use HasFactory;

    protected $fillable = ['service_code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'PPR_client_service_type')
            ->withPivot('is_active')
            ->withTimestamps();
    }
}
