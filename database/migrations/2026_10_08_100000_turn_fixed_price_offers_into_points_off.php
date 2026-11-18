<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `fixed_price` is gone (docs/decisions/automatic-offers-savings-discount-ledger.md):
 * an offer is a percentage or a number of points off. Every row that still
 * says `fixed_price` — an offer, a copy in its history, a use on the ledger
 * — becomes the `amount_off` that takes the same points off:
 *
 * - an offer, or a history copy, whose targets name one item (one space —
 *   booked or as a session —, one plan or one event): that item's price
 *   less the fixed price;
 * - a use on the ledger: its own base less the fixed price — for time in a
 *   space, the space's hourly rate, since its terms are per hour.
 *
 * An offer naming several items, a space type or none had one price across
 * items of different prices, so no single number keeps it: it is switched
 * off and logged, for an admin to set again. Its history copies and uses
 * keep the smallest discount, 1 point, as a marker: the ledger's points
 * already say what each use took.
 *
 * One way: the fixed prices are not kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('coupons')->where('discount_type', 'fixed_price')->orderBy('id')->each(function (object $offer) {
            $targets = DB::table('coupon_targets')->where('coupon_id', $offer->id)->get()->map(fn (object $t) => (array) $t)->all();
            $off = $this->pointsOff($targets, (int) $offer->discount_value);

            DB::table('coupons')->where('id', $offer->id)->update(array_merge(
                ['discount_type' => 'amount_off', 'discount_value' => $off ?? 1],
                $off === null ? ['is_active' => false] : [],
            ));

            if ($off === null) {
                Log::warning("Offer #{$offer->id} had one fixed price across several items; it was switched off — set its discount again.");
            }
        });

        DB::table('coupon_revisions')->where('discount_type', 'fixed_price')->orderBy('id')->each(function (object $revision) {
            $off = $this->pointsOff(json_decode($revision->targets, true) ?: [], (int) $revision->discount_value);

            DB::table('coupon_revisions')->where('id', $revision->id)->update(['discount_type' => 'amount_off', 'discount_value' => $off ?? 1]);
        });

        DB::table('applied_discounts')->where('discount_type', 'fixed_price')->orderBy('id')->each(function (object $use) {
            $spaceId = match (true) {
                $use->booking_id !== null => DB::table('bookings')->where('id', $use->booking_id)->value('space_id'),
                $use->walkin_session_id !== null => DB::table('walkin_sessions')->where('id', $use->walkin_session_id)->value('space_id'),
                default => null,
            };
            $base = $spaceId !== null ? (int) DB::table('spaces')->where('id', $spaceId)->value('hourly_rate_points') : (int) $use->base_points;

            DB::table('applied_discounts')->where('id', $use->id)->update([
                'discount_type' => 'amount_off',
                'discount_value' => max(1, $base - (int) $use->discount_value),
            ]);
        });
    }

    public function down(): void
    {
        // The fixed prices are not kept: there is nothing to restore.
    }

    /**
     * The points off that leave one item at `$price`, or null when the
     * targets name no single item or the price was not below its own.
     *
     * @param  list<array<string, mixed>>  $targets
     */
    private function pointsOff(array $targets, int $price): ?int
    {
        $items = collect($targets)
            ->map(fn (array $t) => match (true) {
                ($t['space_id'] ?? null) !== null => ['spaces', 'hourly_rate_points', $t['space_id']],
                ($t['plan_id'] ?? null) !== null => ['plans', 'price_points', $t['plan_id']],
                ($t['event_id'] ?? null) !== null => ['events', 'price_points', $t['event_id']],
                default => null,
            })
            ->unique(fn (?array $item) => $item === null ? 'none' : implode(':', $item));

        if ($items->count() !== 1 || $items->first() === null) {
            return null;
        }

        [$table, $column, $id] = $items->first();
        $list = (int) DB::table($table)->where('id', $id)->value($column);

        return $list > $price ? $list - $price : null;
    }
};
