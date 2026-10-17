<?php

namespace Add\Hr\Leave\Models;

use Add\Hr\Core\Models\DocumentType;
use Add\Hr\Leave\Enums\LeaveUnit;
use Add\Hr\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Annual, sick, unpaid, hourly permission, ... — what may be requested and
 * how it is counted. Entitlements live on its policies.
 */
class LeaveType extends Model
{
    use HasTranslations;

    protected $table = 'hr_leave_types';

    protected $fillable = [
        'code',
        'name',
        'unit',
        'is_paid',
        'tracks_balance',
        'allows_half_day',
        'counts_calendar_days',
        'hide_reason',
        'attachment_document_type_id',
        'is_active',
    ];

    protected $casts = [
        'name' => 'array',
        'unit' => LeaveUnit::class,
        'is_paid' => 'boolean',
        'tracks_balance' => 'boolean',
        'allows_half_day' => 'boolean',
        'counts_calendar_days' => 'boolean',
        'hide_reason' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'unit' => 'day',
        'is_paid' => true,
        'tracks_balance' => true,
        'allows_half_day' => false,
        'counts_calendar_days' => false,
        'hide_reason' => false,
        'is_active' => true,
    ];

    public function requiresAttachment(): bool
    {
        return $this->attachment_document_type_id !== null;
    }

    public function attachmentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'attachment_document_type_id');
    }

    public function policies(): HasMany
    {
        return $this->hasMany(LeavePolicy::class);
    }
}
