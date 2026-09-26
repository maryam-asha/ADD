<?php

namespace App\Domain\Experience\Models;

use App\Casts\AsMediaUrl;
use App\Concerns\HasTranslations;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Forms\Concerns\InteractsWithForms;
use App\Domain\Forms\Contracts\HasForms;
use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Forms\Models\Form;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Support\Enums\EventRegistrationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Event extends Model implements HasForms
{
    use HasFactory, HasTranslations, InteractsWithForms;

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
     * Whether registration happens somewhere other than here. Nothing about
     * seats, approval or points applies to such an event — the member never
     * reaches our registration endpoint at all.
     */
    public function registersExternally(): bool
    {
        return filled($this->external_form_url);
    }

    /**
     * Attendees that occupy a seat. Every capacity calculation goes
     * through this rather than `attendees()` — a rejected registration
     * must not keep holding a place.
     */
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

    /** The questions asked when a member registers, if the event asks any. */
    public function registrationForm(): MorphOne
    {
        return $this->form(FormPurpose::Registration);
    }

    /**
     * `has_registration_form` is a stored copy of "the registration form
     * has at least one active field", read by the browse listings so they
     * need no join. Kept in step from FormField's own model events.
     */
    public function formFieldsChanged(Form $form): void
    {
        if ($form->purpose !== FormPurpose::Registration) {
            return;
        }

        $this->forceFill(['has_registration_form' => $form->activeFields()->exists()])->save();
    }

    /**
     * An event that sends registration elsewhere has no questions of its
     * own to ask — the member never reaches our endpoint.
     */
    public function formFieldCreationBlockedReason(FormPurpose $purpose): ?string
    {
        return $purpose === FormPurpose::Registration && $this->registersExternally()
            ? __('api.event.form_is_external')
            : null;
    }
}
