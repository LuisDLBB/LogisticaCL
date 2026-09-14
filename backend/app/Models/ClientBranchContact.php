<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientBranchContact extends Model
{
    protected $fillable = [
        'client_branch_id',
        'name',
        'position',
        'email',
        'phone',
        'mobile_phone',
        'is_primary',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(ClientBranch::class, 'client_branch_id');
    }
}
