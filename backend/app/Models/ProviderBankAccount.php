<?php

namespace App\Models;

use Database\Factories\ProviderBankAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderBankAccount extends Model
{
    /** @use HasFactory<ProviderBankAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'provider_id', 'account_holder_name', 'account_holder_tax_id', 'bank_name',
        'account_type', 'account_number', 'is_primary', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
