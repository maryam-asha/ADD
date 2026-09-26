<?php

namespace App\Domain\Ecosystem\Models;

use App\Domain\Experience\Models\Event;
use App\Domain\Forms\Concerns\HasFormSubmissions;
use App\Domain\Identity\Models\User;
use App\Support\Enums\EventRegistrationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAttendee extends Model
{
    use HasFactory, HasFormSubmissions;

    protected $fillable = [
        'event_id',
        'user_id',
        'name',
        'phone',
        'email',
        'status',
        'rejection_reason',
        'approved_by',
        'approved_at',
        'pending_expires_at',
        'attended',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'attended' => 'boolean',
            'approved_at' => 'datetime',
            'pending_expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EventAttendee $attendee) {
            if (empty($attendee->attributes['status'])) {
                $requiresApproval = $attendee->relationLoaded('event')
                    ? (bool) $attendee->event?->requires_approval
                    : (bool) Event::query()->where('id', $attendee->event_id)->value('requires_approval');

                $attendee->status = $requiresApproval
                    ? EventRegistrationStatus::Pending
                    : EventRegistrationStatus::Approved;
            }
        });
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value instanceof EventRegistrationStatus) {
                    return $value;
                }

                $enum = EventRegistrationStatus::tryFrom((string) $value);
                if ($enum !== null) {
                    return $enum;
                }

                $requiresApproval = $this->relationLoaded('event')
                    ? (bool) $this->event?->requires_approval
                    : (bool) Event::query()->where('id', $this->event_id)->value('requires_approval');

                return $requiresApproval
                    ? EventRegistrationStatus::Pending
                    : EventRegistrationStatus::Approved;
            },
            set: fn ($value) => $value instanceof EventRegistrationStatus ? $value->value : $value,
        );
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The staff account that approved or rejected this registration. */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Registrations that occupy a seat. A rejected one does not, so the
     * seat is free again with no separate release step.
     */
    public function scopeSeatHolding(Builder $query): Builder
    {
        return $query->whereIn('status', EventRegistrationStatus::seatHoldingValues());
    }
}
