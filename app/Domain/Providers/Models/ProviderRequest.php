<?php

namespace App\Domain\Providers\Models;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Enums\ProviderRequestType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a provider asked ADD to grant, waiting for (or past) a review.
 * `payload` holds the type's fields; `result_id` what approval created —
 * see ProviderRequestService.
 */
class ProviderRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'submitted_by',
        'type',
        'company_id',
        'payload',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProviderRequestType::class,
            'status' => ProviderRequestStatus::class,
            'payload' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ProviderRequestStatus::Pending);
    }

    public function isPending(): bool
    {
        return $this->status === ProviderRequestStatus::Pending;
    }
}
