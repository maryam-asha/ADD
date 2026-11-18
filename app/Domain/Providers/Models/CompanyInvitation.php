<?php

namespace App\Domain\Providers\Models;

use App\Domain\Identity\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An approved employee whose phone had no member account yet. Linked to the
 * company when that phone signs up (CompanyInvitationAcceptor).
 */
class CompanyInvitation extends Model
{
    protected $fillable = [
        'company_id',
        'phone',
        'door_access_enabled',
        'provider_request_id',
    ];

    protected function casts(): array
    {
        return [
            'door_access_enabled' => 'boolean',
            'accepted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('accepted_at');
    }
}
