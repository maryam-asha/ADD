<?php

namespace App\Domain\Experience\Models;

use App\Casts\AsMediaUrl;
use App\Concerns\HasTranslations;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Support\Enums\EventRegistrationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'branch_id',
        'space_id',
        'category_id',
        'title',
        'description',
        'start_at',
        'end_at',
        'location',
        'capacity',
        'requires_approval',
        'has_registration_form',
        'external_form_url',
        'is_free',
        'price_points',
        'image_url',
        'status',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'space_id' => 'integer',
            'category_id' => 'integer',
            'title' => 'array',
            'description' => 'array',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'requires_approval' => 'boolean',
            'has_registration_form' => 'boolean',
            'is_free' => 'boolean',
            'price_points' => 'integer',
            'image_url' => AsMediaUrl::class,
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'category_id');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(EventAttendee::class);
    }

    /**
     * Attendees that occupy a seat. Every capacity calculation goes
     * through this rather than `attendees()` — a rejected registration
     * must not keep holding a place.
     */
    /**
     * Whether registration happens somewhere other than here. Nothing about
     * seats, approval or points applies to such an event — the member never
     * reaches our registration endpoint at all.
     */
    public function registersExternally(): bool
    {
        return filled($this->external_form_url);
    }

    public function seatHoldingAttendees(): HasMany
    {
        return $this->attendees()
            ->whereIn('status', EventRegistrationStatus::seatHoldingValues());
    }

    /**
     * The sittings this event runs, in order.
     *
     * events.start_at/end_at is the envelope around these and is derived from
     * them by EventSchedule — an event with several sessions has no single
     * typed interval of its own.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(EventSession::class)->orderBy('start_at');
    }

    /** Whether this event runs over more than one sitting. */
    public function hasMultipleSessions(): bool
    {
        return $this->sessions()->count() > 1;
    }

    public function registrationFields(): HasMany
    {
        return $this->hasMany(EventRegistrationField::class)->orderBy('order');
    }

    /** What a member is actually shown, and what a submission is validated against. */
    public function activeRegistrationFields(): HasMany
    {
        return $this->registrationFields()->where('is_active', true);
    }
}
