<?php

namespace Add\Inventory\Stock\Services;

use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Inventory;
use Add\Inventory\Stock\Enums\IssueReason;
use Add\Inventory\Stock\Enums\StockMovementType;
use Add\Inventory\Stock\Models\StockBalance;
use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Support\Decimal;
use Add\Inventory\Support\InventoryException;
use Add\Inventory\Support\Place;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only way a stock quantity changes. Every call writes ledger rows
 * (inv_stock_movements) and moves the balance they add up to, in one
 * transaction, with the balance row locked — so two receptions selling the
 * last ream cannot both succeed, and the balance always equals its ledger.
 *
 * Cost is a weighted average per item and location: a receipt at a new
 * cost re-averages what is there; issues and transfers take the average
 * they leave at. Hosts call issue() for their own operations (a sale, a
 * recorded consumption) inside their own transaction, passing what caused
 * it as `$source`.
 */
class StockService
{
    public function receive(
        Item $item,
        Place $at,
        int|float|string $quantity,
        int|float|string|null $unitCost = null,
        ?Place $source = null,
        ?string $note = null,
    ): StockMovement {
        $quantity = $this->quantity($item, $quantity);
        $this->ensureLocation($at);

        if ($unitCost !== null && Decimal::cmp($unitCost, '0') < 0) {
            InventoryException::fail('inventory::stock.negative_cost');
        }

        return DB::transaction(fn () => $this->record($item, $at, StockMovementType::Receipt, $quantity, $unitCost === null ? null : Decimal::round($unitCost, 4), [
            'note' => $note,
            ...$this->source($source),
        ]));
    }

    public function issue(
        Item $item,
        Place $at,
        int|float|string $quantity,
        IssueReason $reason,
        ?Place $source = null,
        ?string $note = null,
    ): StockMovement {
        $quantity = $this->quantity($item, $quantity);
        $this->ensureLocation($at);

        return DB::transaction(fn () => $this->record($item, $at, StockMovementType::Issue, Decimal::neg($quantity), null, [
            'reason' => $reason,
            'note' => $note,
            ...$this->source($source),
        ]));
    }

    /**
     * Both halves share a `transfer_id`; what arrives keeps the cost it
     * left at.
     *
     * @return array{0: StockMovement, 1: StockMovement}
     */
    public function transfer(Item $item, Place $from, Place $to, int|float|string $quantity, ?string $note = null): array
    {
        $quantity = $this->quantity($item, $quantity);

        if ($from->is($to)) {
            InventoryException::fail('inventory::stock.same_location');
        }

        $this->ensureLocation($from);
        $this->ensureLocation($to);

        return DB::transaction(function () use ($item, $from, $to, $quantity, $note) {
            // Both rows locked up front, always in the same order, so two
            // opposite transfers cannot deadlock each other.
            $places = [$from, $to];
            usort($places, fn (Place $a, Place $b) => strcmp($a->key(), $b->key()));
            foreach ($places as $place) {
                $this->lockedBalance($item, $place);
            }

            $transferId = (string) Str::uuid();
            $out = $this->record($item, $from, StockMovementType::TransferOut, Decimal::neg($quantity), null, ['transfer_id' => $transferId, 'note' => $note]);
            $in = $this->record($item, $to, StockMovementType::TransferIn, $quantity, $out->unit_cost, ['transfer_id' => $transferId, 'note' => $note]);

            return [$out, $in];
        });
    }

    /**
     * A physical count: the ledger takes the difference so the balance
     * becomes what was counted. Null when the count matched. Stock found
     * beyond the book comes in at `$unitCost`, or the current average.
     */
    public function adjust(Item $item, Place $at, int|float|string $counted, int|float|string|null $unitCost = null, ?string $note = null): ?StockMovement
    {
        $this->ensureStock($item);
        $counted = Decimal::round($counted, 3);

        if (Decimal::cmp($counted, '0') < 0) {
            InventoryException::fail('inventory::stock.negative_count');
        }

        $this->ensureWhole($item, $counted);
        $this->ensureLocation($at);

        return DB::transaction(function () use ($item, $at, $counted, $unitCost, $note) {
            $balance = $this->lockedBalance($item, $at);
            $delta = Decimal::sub($counted, $balance->quantity);

            if (Decimal::isZero($delta)) {
                return null;
            }

            $cost = Decimal::cmp($delta, '0') > 0 && $unitCost !== null ? Decimal::round($unitCost, 4) : null;

            return $this->record($item, $at, StockMovementType::Adjustment, $delta, $cost, ['note' => $note]);
        });
    }

    /**
     * What an item holds at a location right now.
     */
    public function onHand(Item $item, Place $at): string
    {
        $quantity = StockBalance::query()
            ->where('item_id', $item->id)
            ->where('location_type', $at->type)
            ->where('location_id', $at->id)
            ->value('quantity');

        return $quantity === null ? '0' : Decimal::trim($quantity);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(Item $item, Place $at, StockMovementType $type, string $delta, ?string $unitCost, array $extra): StockMovement
    {
        $balance = $this->lockedBalance($item, $at);
        $after = Decimal::add($balance->quantity, $delta);

        if (Decimal::cmp($after, '0') < 0) {
            throw InsufficientStockException::for($balance->quantity, Decimal::neg($delta), $item->unit?->translate('name'));
        }

        $before = $balance->average_cost;
        $average = $before;

        if (Decimal::cmp($delta, '0') > 0 && $unitCost !== null) {
            $average = Decimal::round(Decimal::div(
                Decimal::add(Decimal::mul($balance->quantity, $balance->average_cost), Decimal::mul($delta, $unitCost)),
                $after,
            ), 4);
        }

        $balance->update(['quantity' => $after, 'average_cost' => $average]);

        return StockMovement::query()->create([
            'item_id' => $item->id,
            'location_type' => $at->type,
            'location_id' => $at->id,
            'type' => $type,
            'quantity' => $delta,
            // What it came in at, or the average it left at.
            'unit_cost' => $unitCost ?? $before,
            'quantity_after' => $after,
            'average_cost_after' => $average,
            'actor_id' => Inventory::actorId(),
            ...$extra,
        ]);
    }

    private function lockedBalance(Item $item, Place $at): StockBalance
    {
        $find = fn () => StockBalance::query()
            ->where('item_id', $item->id)
            ->where('location_type', $at->type)
            ->where('location_id', $at->id)
            ->lockForUpdate()
            ->first();

        $balance = $find();

        if ($balance === null) {
            // Ignored when a concurrent call created it first; the locked
            // read below then waits for that one.
            StockBalance::query()->insertOrIgnore([
                'item_id' => $item->id,
                'location_type' => $at->type,
                'location_id' => $at->id,
                'quantity' => 0,
                'average_cost' => 0,
                'updated_at' => now(),
            ]);
            $balance = $find();
        }

        return $balance;
    }

    private function quantity(Item $item, int|float|string $quantity): string
    {
        $this->ensureStock($item);
        $quantity = Decimal::round($quantity, 3);

        if (Decimal::cmp($quantity, '0') <= 0) {
            InventoryException::fail('inventory::stock.positive_quantity');
        }

        $this->ensureWhole($item, $quantity);

        return $quantity;
    }

    private function ensureStock(Item $item): void
    {
        if (! $item->isStock()) {
            InventoryException::fail('inventory::stock.not_stock');
        }
    }

    private function ensureWhole(Item $item, string $quantity): void
    {
        if (! $item->unit->allows_fractions && str_contains($quantity, '.')) {
            InventoryException::fail('inventory::stock.whole_quantity', ['unit' => (string) $item->unit->translate('name')]);
        }
    }

    private function ensureLocation(Place $at): void
    {
        if (! Inventory::locations()->exists($at)) {
            InventoryException::fail('inventory::stock.unknown_location');
        }
    }

    /**
     * @return array{source_type?: string, source_id?: int}
     */
    private function source(?Place $source): array
    {
        return $source === null ? [] : ['source_type' => $source->type, 'source_id' => $source->id];
    }
}
