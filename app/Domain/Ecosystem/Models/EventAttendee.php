<?php

namespace App\Domain\Ecosystem\Models;

use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Models\EventRegistrationFieldValue;
use App\Domain\Identity\Models\User;
use App\Support\Enums\EventRegistrationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventAttendee extends Model
{
    use HasFactory;

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
            'status' => EventRegistrationStatus::class,
            'approved_at' => 'datetime',
            'pending_expires_at' => 'datetime',
        ];
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

    /** Answers to the event's registration form, if it has one. */
    public function values(): HasMany
    {
        return $this->hasMany(EventRegistrationFieldValue::class);
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
