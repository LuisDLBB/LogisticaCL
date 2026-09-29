<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FleetDocument extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'size_bytes' => 'integer'];
    }

    protected $fillable = [
        'tenant_id', 'vehicle_id', 'documentable_type', 'documentable_id',
        'kind', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes',
        'uploaded_by_user_id', 'created_at',
    ];
}
