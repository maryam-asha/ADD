<?php

namespace App\Domain\Booking\Models;

use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Booking\Concerns\HasSessionActivity;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Support\ReferenceSequence;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Booking extends Model
{
    use HasFactory, HasSessionActivity;

    protected $fillable = [
        'space_id',
        'seat_space_id',
        'user_id',
        'start_at',
        'end_at',
        'status',
        'payment_state',
        'payment_source',
        'checked_in_at',
        'checked_in_via',
        'kiosk_id',
        'checked_out_at',
        'termination_source',
        'amount_owed',
        'price_points',
        'list_price_points',
        'currency',
        'payment_method',
        'paid_by',
        'paid_at',
        'cancelled_at',
        'rejection_reason',
        'note',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'status' => BookingStatus::class,
            'payment_state' => PaymentState::class,
            'payment_source' => PaymentSource::class,
            'checked_in_at' => 'datetime',
            'checked_in_via' => SessionStartSource::class,
            'checked_out_at' => 'datetime',
            'termination_source' => TerminationSource::class,
            'amount_owed' => 'decimal:2',
            'price_points' => 'integer',
            'list_price_points' => 'integer',
            'payment_method' => PaymentMethod::class,
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Every booking gets its human-readable number ("BK-2026-00041") here,
     * whichever path creates it — the series restarts each calendar year, as
     * service requests' "REQ-…" does (docs/decisions/booking-outcomes-and-extension.md).
     */
    protected static function booted(): void
    {
        static::creating(function (self $booking) {
            if ($booking->reference !== null) {
                return;
            }

            $key = 'BK-'.($booking->created_at ?? now())->format('Y');

            $booking->reference = sprintf('%s-%05d', $key, ReferenceSequence::next($key));
        });
    }

    /**
     * The statuses that hold a slot (BookingStatus::slotHolding()). A
     * `pending` booking is itself the hold — there is no separate
     * reservation mechanism — while `cancelled` and `rejected` fall outside,
     * so the very next overlap check sees the slot free again with no
     * explicit release step
     * (docs/decisions/booking-creation-approval-extension.md).
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', BookingStatus::slotHolding());
    }

    /**
     * Rejected by the clock rather than by anyone: still pending when it
     * started (bookings:close-elapsed), so no operator and no reason is
     * recorded — a staff rejection always carries both.
     */
    public function lapsedUnreviewed(): bool
    {
        return $this->status === BookingStatus::Rejected
            && $this->approved_by === null
            && $this->rejection_reason === null;
    }

    /**
     * Half-open interval intersection: a booking ending exactly when the
     * window starts does not overlap it. Adjacency is the buffer's concern,
     * not this one.
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query->where('start_at', '<', $end)->where('end_at', '>', $start);
    }

    public function scopeForSpace(Builder $query, int $spaceId): Builder
    {
        return $query->where('space_id', $spaceId);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * The room lent to co-working this booking was placed in, or null when
     * it sits on its own space (docs/decisions/coworking-seat-pool.md,
     * "Seating placement").
     */
    public function seatSpace(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'seat_space_id');
    }

    /** Where the member actually sits: the lent room, else the booked space. */
    public function seatingSpace(): Space
    {
        return $this->seatSpace ?? $this->space;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Every payment made for this booking — the booking itself and any
     * extension — so a list can eager-load its payment state.
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /**
     * The points already paid for this booking: every payment made for it —
     * the booking itself and any extension, whatever the method — that was
     * accepted, settled and not refunded. What the booking still owes is its
     * price less this, so a paid booking that was then extended owes only
     * the extension.
     *
     * Reads the eager-loaded `payments` when a list has loaded them.
     */
    public function pointsPaid(): int
    {
        $payments = $this->relationLoaded('payments') ? $this->getRelation('payments') : $this->payments()->get();

        return (int) $payments
            ->filter(fn (Payment $payment) => $payment->status === PaymentStatus::Accepted
                && $payment->settled_at !== null
                && $payment->refunded_at === null)
            ->sum(fn (Payment $payment) => (int) $payment->amount_points);
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(AccessGrant::class, 'source_id')
            ->where('source_type', AccessSourceType::Booking);
    }

    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(Kiosk::class);
    }

    public function coworkingType(): string
    {
        return 'booking';
    }

    /** Bookings checked in before scan check-in existed have no value; reception did those. */
    public function startSource(): SessionStartSource
    {
        return $this->checked_in_via ?? SessionStartSource::Reception;
    }
}
