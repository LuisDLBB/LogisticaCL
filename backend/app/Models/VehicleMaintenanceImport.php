<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleMaintenanceImport extends Model
{
    protected $table = 'VEH_maintenance_imports';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['requested_at' => 'date', 'document_issued_at' => 'date', 'net_amount' => 'decimal:2', 'vat_amount' => 'decimal:2', 'source_data' => 'array'];
    }
}
