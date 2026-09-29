<?php

namespace App\Models;

use Database\Factories\ProviderOcFilenameFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderOcFilename extends Model
{
    /** @use HasFactory<ProviderOcFilenameFactory> */
    use HasFactory;

    protected $fillable = ['provider_id', 'company_code', 'service_scope', 'file_stem'];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
