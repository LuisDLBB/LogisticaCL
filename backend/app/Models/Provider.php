<?php

namespace App\Models;

use Database\Factories\ProviderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    /** @use HasFactory<ProviderFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'tax_id', 'tax_id_number', 'tax_id_check_digit', 'legal_name',
        'operational_name', 'operator_type', 'tax_document_type', 'commercial_address',
        'commercial_commune_name', 'contact_name', 'contact_phone', 'contact_email', 'contact_email_secondary', 'payment_terms', 'payment_terms_pmcb', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(ProviderBankAccount::class);
    }

    public function ocFilenames(): HasMany
    {
        return $this->hasMany(ProviderOcFilename::class);
    }

    public function coverages(): HasMany
    {
        return $this->hasMany(Coverage::class);
    }
}
