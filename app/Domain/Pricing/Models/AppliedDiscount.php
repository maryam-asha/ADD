<?php

namespace App\Domain\Pricing\Models;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\PlanDiscount;
use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Enums\DiscountKind;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Support\Enums\EventRegistrationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * One reduction locked into one price — the discount ledger
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md):
 * the booking, walk-in, registration or plan payment it applied to, what
 * kind it was (an offer, a plan discount, a coupon, or a plan's built-in
 * saving), and a copy of the terms it applied on. At most one row per kind
 * per record. Only CouponLedger writes it.
 *
 * The offer and coupon rows are what their limits are counted against.
 */
class AppliedDiscount extends Model
{
    /** The record columns, exactly one of which is set. */
    public const RECORD_COLUMNS = ['booking_id', 'walkin_session_id', 'event_attendee_id', 'payment_id'];

    protected $fillable = [
        'user_id',
        'purpose',
        'booking_id',
        'walkin_session_id',
        'event_attendee_id',
        'payment_id',
        'kind',
        'coupon_id',
        'plan_discount_id',
        'discount_type',
        'discount_value',
        'max_discount_points',
        'base_points',
        'points',
        'percent',
        'terms',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => PricingPurpose::class,
            'kind' => DiscountKind::class,
            'discount_type' => CouponDiscountType::class,
            'discount_value' => 'integer',
            'max_discount_points' => 'integer',
            'base_points' => 'integer',
            'points' => 'integer',
            'percent' => 'integer',
            'terms' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $row) {
            $set = array_filter(self::RECORD_COLUMNS, fn (string $column) => $row->getAttribute($column) !== null);

            if (count($set) !== 1) {
                throw new InvalidArgumentException('An applied discount belongs to exactly one booking, walk-in, registration or payment.');
            }

            if (in_array($row->kind, [DiscountKind::Offer, DiscountKind::Coupon], true) && $row->coupon_id === null) {
                throw new InvalidArgumentException('An offer or coupon row names its coupon.');
            }

            if ($row->kind === DiscountKind::PlanDiscount && $row->plan_discount_id === null) {
                throw new InvalidArgumentException('A plan discount row names its plan discount.');
            }
        });
    }

    /** What `$points` is of `$base`, in whole percent, rounded down: never overstated. */
    public static function percentOf(int $points, int $base): int
    {
        return $base > 0 ? intdiv($points * 100, $base) : 0;
    }

    /**
     * The rows that still count: the record they are on can still be — or
     * was — bought. A booking holding its slot (a no-show kept its slot to
     * the end), a registration holding its seat, a payment that may yet be
     * or was paid, and any walk-in. Cancelling, rejecting, failing, expiring
     * and reversing all give the use back, by status alone — some of those
     * paths are bulk updates that fire no events.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNotNull('walkin_session_id')
                ->orWhereHas('booking', fn (Builder $b) => $b->whereIn('status', BookingStatus::slotHolding()))
                ->orWhereHas('eventAttendee', fn (Builder $a) => $a->whereIn('status', EventRegistrationStatus::seatHoldingValues()))
                ->orWhereHas('payment', fn (Builder $p) => $p->whereIn('status', PaymentStatus::holdsRedemption()));
        });
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function planDiscount(): BelongsTo
    {
        return $this->belongsTo(PlanDiscount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function walkinSession(): BelongsTo
    {
        return $this->belongsTo(WalkinSession::class);
    }

    public function eventAttendee(): BelongsTo
    {
        return $this->belongsTo(EventAttendee::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
