<?php

namespace Add\Hr\Recruitment\Models;

use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Recruitment\Enums\OfferStatus;
use Add\Hr\Support\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the candidate is offered: start date, pay lines (applied as their
 * compensation on hire) and terms. Approved before it is made.
 */
class Offer extends Model
{
    protected $table = 'hr_offers';

    protected $guarded = [];

    protected $casts = [
        'compensation' => 'array',
        'start_on' => DateOnly::class,
        'expires_on' => DateOnly::class,
        'status' => OfferStatus::class,
        'responded_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }
}
