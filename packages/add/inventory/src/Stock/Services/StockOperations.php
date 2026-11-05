<?php

namespace Add\Inventory\Stock\Services;

use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Stock\Enums\IssueReason;
use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Support\Decimal;
use Add\Inventory\Warehouses\Models\Warehouse;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What staff record in one go: several lines in a warehouse (two for a
 * transfer), each in any of its item's units. Every line becomes ledger
 * rows through StockService, in one transaction — one line that fails
 * takes the whole operation back — and all of them share one
 * `operation_id`. A pack line is converted to the main unit (2 boxes of 6
 * are 12 pieces, a box's cost split six ways) and keeps what was typed in
 * `entered_*`; for a count, what was typed is the count.
 *
 * Lines are recorded in item order, so two operations over the same items
 * lock their balances in the same order.
 */
class StockOperations
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * @param  list<array{item_id: int, unit_id?: ?int, quantity: int|float|string, unit_cost?: int|float|string|null}>  $lines
     * @return Collection<int, StockMovement>
     */
    public function receive(Warehouse $at, array $lines, ?string $note): Collection
    {
        return $this->run($lines, fn (Item $item, array $line, string $factor, array $meta) => [$this->stock->receive(
            $item,
            $at->asPlace(),
            $this->toMain($line['quantity'], $factor),
            isset($line['unit_cost']) ? Decimal::round(Decimal::div($line['unit_cost'], $factor), 4) : null,
            note: $note,
            meta: $meta,
        )]);
    }

    /**
     * @param  list<array{item_id: int, unit_id?: ?int, quantity: int|float|string}>  $lines
     * @return Collection<int, StockMovement>
     */
    public function issue(Warehouse $at, array $lines, IssueReason $reason, ?string $note): Collection
    {
        return $this->run($lines, fn (Item $item, array $line, string $factor, array $meta) => [$this->stock->issue(
            $item,
            $at->asPlace(),
            $this->toMain($line['quantity'], $factor),
            $reason,
            note: $note,
            meta: $meta,
        )]);
    }

    /**
     * @param  list<array{item_id: int, unit_id?: ?int, quantity: int|float|string}>  $lines
     * @return Collection<int, StockMovement>
     */
    public function transfer(Warehouse $from, Warehouse $to, array $lines, ?string $note): Collection
    {
        return $this->run($lines, fn (Item $item, array $line, string $factor, array $meta) => $this->stock->transfer(
            $item,
            $from->asPlace(),
            $to->asPlace(),
            $this->toMain($line['quantity'], $factor),
            $note,
            $meta,
        ));
    }

    /**
     * Only the lines whose count differed write a row.
     *
     * @param  list<array{item_id: int, unit_id?: ?int, counted: int|float|string, unit_cost?: int|float|string|null}>  $lines
     * @return Collection<int, StockMovement>
     */
    public function adjust(Warehouse $at, array $lines, ?string $note): Collection
    {
        return $this->run($lines, fn (Item $item, array $line, string $factor, array $meta) => array_filter([$this->stock->adjust(
            $item,
            $at->asPlace(),
            $this->toMain($line['counted'], $factor),
            isset($line['unit_cost']) ? Decimal::round(Decimal::div($line['unit_cost'], $factor), 4) : null,
            $note,
            $meta,
        )]));
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  Closure(Item, array<string, mixed>, string, array<string, mixed>): array<int, ?StockMovement>  $record
     * @return Collection<int, StockMovement>
     */
    private function run(array $lines, Closure $record): Collection
    {
        $operationId = (string) Str::uuid();
        $items = Item::query()->with(['unit', 'otherUnits', 'category'])->findMany(array_column($lines, 'item_id'))->keyBy('id');
        usort($lines, fn (array $a, array $b) => (int) $a['item_id'] <=> (int) $b['item_id']);

        return DB::transaction(function () use ($lines, $items, $record, $operationId) {
            $rows = collect();

            foreach ($lines as $line) {
                $item = $items->get((int) $line['item_id']);
                $unitId = isset($line['unit_id']) ? (int) $line['unit_id'] : $item->unit_id;
                $factor = $item->factorFor($unitId);
                $typed = $line['quantity'] ?? $line['counted'];
                $meta = ['operation_id' => $operationId];

                if ($unitId !== $item->unit_id) {
                    $meta += ['entered_unit_id' => $unitId, 'entered_quantity' => Decimal::round($typed, 3)];
                }

                $rows->push(...array_filter($record($item, $line, $factor, $meta)));
            }

            return $rows;
        });
    }

    private function toMain(int|float|string $quantity, string $factor): string
    {
        return Decimal::trim(Decimal::mul($quantity, $factor));
    }
}
