<?php

namespace App\Support\Presenters;

use App\Domain\Pricing\Enums\DiscountKind;
use App\Domain\Pricing\Models\AppliedDiscount;
use App\Domain\Pricing\Services\CouponLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * What was taken off a booking, a session, a registration or a payment —
 * its `discounts` — read from the ledger, so as it was when the price was
 * locked: an offer renamed or changed since does not rewrite it.
 *
 * `offer_id` names an offer, `coupon_id` a typed coupon, `plan_discount_id`
 * a plan discount; `duration_saving` is a plan's built-in saving, which
 * took nothing off the price (`name` is what it was compared to).
 *
 * The rows ride on the record as an `appliedDiscounts` relation set here,
 * not declared on the models: an event registration is Ecosystem, which
 * may not import Pricing (DomainLayerBoundaryTest). A list calls preload()
 * first, so a page costs one query, not one per row.
 */
class AppliedDiscountPresenter
{
    private const RELATION = 'appliedDiscounts';

    /**
     * Loads the rows of every record on a page in one query.
     *
     * @param  iterable<mixed>  $records  bookings, walk-ins, registrations or payments
     */
    public function preload(iterable $records): void
    {
        // A co-working list mixes bookings and walk-ins: one query per kind.
        collect($records)
            ->filter(fn ($record) => $record instanceof Model)
            ->groupBy(fn (Model $record) => $record::class)
            ->each(function ($sameKind) {
                $column = CouponLedger::recordColumn($sameKind->first());
                $rows = AppliedDiscount::query()->whereIn($column, $sameKind->map->getKey())->orderBy('id')->get()->groupBy($column);

                $sameKind->each(fn (Model $record) => $record->setRelation(self::RELATION, $rows->get($record->getKey(), collect())));
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function for(Model $record): array
    {
        if (! $record->relationLoaded(self::RELATION)) {
            $this->preload([$record]);
        }

        return $this->list($record->getRelation(self::RELATION));
    }

    /**
     * @param  Collection<int, AppliedDiscount>  $rows
     * @return list<array<string, mixed>>
     */
    public function list(Collection $rows): array
    {
        return $rows->sortBy('id')->map(fn (AppliedDiscount $row) => [
            'kind' => $row->kind->value,
            'offer_id' => $row->kind === DiscountKind::Offer ? $row->coupon_id : null,
            'coupon_id' => $row->kind === DiscountKind::Coupon ? $row->coupon_id : null,
            'plan_discount_id' => $row->plan_discount_id,
            'name' => $this->translated($row->terms['name'] ?? $row->terms['reference_name'] ?? null),
            'code' => $row->terms['code'] ?? null,
            'discount_type' => $row->discount_type?->value,
            'discount_value' => $row->discount_value,
            'max_discount_points' => $row->max_discount_points,
            'base_points' => $row->base_points,
            'points' => $row->points,
            'percent' => $row->percent,
        ])->values()->all();
    }

    private function translated(mixed $name): ?string
    {
        if (! is_array($name)) {
            return $name;
        }

        return $name[app()->getLocale()] ?? $name['en'] ?? $name['ar'] ?? null;
    }
}
