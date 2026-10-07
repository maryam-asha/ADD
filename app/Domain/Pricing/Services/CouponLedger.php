<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Enums\DiscountKind;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\AppliedDiscount;
use App\Domain\Pricing\Models\Coupon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The discount ledger: one `applied_discounts` row per reduction locked
 * into a price, by kind, and the counts a coupon's or offer's limits are
 * checked against — live rows, never a stored counter
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
 *
 * Locking protocol: lockForPricing() — or, where no offer can be chosen,
 * lockForRedemption() — is the FIRST lock, and the first read, in any
 * transaction that redeems. Under MySQL's REPEATABLE READ the
 * snapshot is taken at the first plain read, so counting after a later lock
 * could miss a use committed in between and let "the first ten" become
 * eleven; and taking it after Branch/Space would deadlock against a plan
 * purchase. Order: Coupon → Booking → Branch → Space / Event → Payment →
 * Wallet.
 */
class CouponLedger
{
    /**
     * The first lock — and the first read — of a transaction that may spend
     * an offer or a coupon: every active offer with a limit, in id order,
     * then the typed coupon (never automatic, so never among them) — so two
     * purchases cannot both take "the last" use and two transactions never
     * lock them in opposite orders. Locking reads only: a subquery here
     * would be a plain read and fix the snapshot before the lock.
     */
    public function lockForPricing(?Coupon $coupon): ?Coupon
    {
        Coupon::query()
            ->where('is_automatic', true)
            ->where('is_active', true)
            ->where(fn (Builder $limited) => $limited->whereNotNull('usage_limit')->orWhereNotNull('per_user_limit'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return $coupon !== null ? $this->lockForRedemption($coupon) : null;
    }

    public function lockForRedemption(Coupon $coupon): Coupon
    {
        return Coupon::query()->whereKey($coupon->getKey())->lockForUpdate()->firstOrFail();
    }

    /** Live uses: of everyone, or of one member. */
    public function liveUsage(Coupon $coupon, ?User $member = null): int
    {
        return $coupon->redemptions()
            ->live()
            ->when($member !== null, fn ($query) => $query->where('user_id', $member->id))
            ->count();
    }

    /**
     * Files one reduction of `$kind` on the record it applied to — or
     * rewrites it, when the record is priced again — with `percent` taken
     * from what it took off what it applied to. Called inside the
     * transaction that saved the record.
     *
     * @param  array<string, mixed>  $attributes  the terms copy, `base_points` and `points`
     */
    public function write(Model $record, User $member, PricingPurpose $purpose, DiscountKind $kind, array $attributes): AppliedDiscount
    {
        $row = $this->row($record, $kind) ?? new AppliedDiscount([
            self::recordColumn($record) => $record->getKey(),
            'kind' => $kind,
        ]);

        $row->fill($attributes + ['user_id' => $member->id, 'purpose' => $purpose]);
        $row->percent = AppliedDiscount::percentOf((int) $row->points, (int) $row->base_points);
        $row->save();

        return $row;
    }

    /**
     * A row describing a price that has since moved — a session closed at
     * the time actually used, a booking extended — rewritten to what it now
     * applies to and takes off, so the breakdown still adds up.
     */
    public function resize(?AppliedDiscount $row, int $basePoints, int $points): void
    {
        $row?->update([
            'base_points' => $basePoints,
            'points' => $points,
            'percent' => AppliedDiscount::percentOf($points, $basePoints),
        ]);
    }

    /** A coupon's use, filed with a copy of its terms. */
    public function record(Coupon $coupon, User $member, Model $record, PricingPurpose $purpose, int $basePoints, int $points): AppliedDiscount
    {
        return $this->write($record, $member, $purpose, DiscountKind::Coupon, self::couponAttributes($coupon, $basePoints, $points));
    }

    /**
     * The copy of a coupon or offer a row keeps: its terms, and its code
     * and name for display.
     *
     * @return array<string, mixed>
     */
    public static function couponAttributes(Coupon $coupon, int $basePoints, int $points): array
    {
        return [
            'coupon_id' => $coupon->id,
            'discount_type' => $coupon->discount_type,
            'discount_value' => $coupon->discount_value,
            'max_discount_points' => $coupon->max_discount_points,
            'base_points' => $basePoints,
            'points' => $points,
            'terms' => array_filter(['code' => $coupon->code, 'name' => $coupon->name], fn ($value) => $value !== null),
        ];
    }

    public function row(Model $record, DiscountKind $kind): ?AppliedDiscount
    {
        return AppliedDiscount::query()
            ->where(self::recordColumn($record), $record->getKey())
            ->where('kind', $kind)
            ->first();
    }

    public function couponFor(Model $record): ?AppliedDiscount
    {
        return $this->row($record, DiscountKind::Coupon);
    }

    public function offerFor(Model $record): ?AppliedDiscount
    {
        return $this->row($record, DiscountKind::Offer);
    }

    public function planDiscountFor(Model $record): ?AppliedDiscount
    {
        return $this->row($record, DiscountKind::PlanDiscount);
    }

    /** @return Collection<int, AppliedDiscount> */
    public function rowsFor(Model $record): Collection
    {
        return AppliedDiscount::query()->where(self::recordColumn($record), $record->getKey())->orderBy('id')->get();
    }

    public static function recordColumn(Model $record): string
    {
        return match (true) {
            $record instanceof Booking => 'booking_id',
            $record instanceof WalkinSession => 'walkin_session_id',
            $record instanceof EventAttendee => 'event_attendee_id',
            $record instanceof Payment => 'payment_id',
            default => throw new InvalidArgumentException('A discount cannot be applied to '.$record::class.'.'),
        };
    }
}
