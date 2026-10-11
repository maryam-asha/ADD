<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PrivateOfficeRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'prospect_name',
        'contact',
        'status',
        'quote_ref',
        'contract_ref',
        'converted_company_id',
        'company_id',
        'payer',
        'provider_id',
        'submitted_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PrivateOfficeRequestStatus::class,
            'payer' => Payer::class,
        ];
    }

    public function convertedCompany(): HasOne
    {
        return $this->hasOne(Company::class, 'created_from_request_id');
    }

    /** The existing company asking for another office; null = a new company. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
