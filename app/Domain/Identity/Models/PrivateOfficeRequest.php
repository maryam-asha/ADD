<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
    ];

    protected function casts(): array
    {
        return [
            'status' => PrivateOfficeRequestStatus::class,
        ];
    }

    public function convertedCompany(): HasOne
    {
        return $this->hasOne(Company::class, 'created_from_request_id');
    }
}
