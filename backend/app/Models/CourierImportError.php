<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourierImportError extends Model
{
    protected $fillable = ['tenant_id', 'batch_id', 'file_name', 'category', 'source_key', 'source_values', 'affected_records', 'action', 'status', 'exclude_from_import', 'comment'];

    protected function casts(): array
    {
        return ['source_values' => 'array', 'exclude_from_import' => 'boolean'];
    }
}
