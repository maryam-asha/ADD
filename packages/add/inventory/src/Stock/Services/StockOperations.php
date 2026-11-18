<?php

namespace Add\Inventory\Stock\Services;

use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Inventory;
use Add\Inventory\Stock\Enums\IssueReason;
use Add\Inventory\Stock\Enums\OperationKind;
use Add\Inventory\Stock\Models\StockBalance;
use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Stock\Models\StockOperation;
use Add\Inventory\Support\Decimal;
use Add\Inventory\Support\InventoryException;
use Add\Inventory\Warehouses\Models\Warehouse;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What staff record in one go: an operation — its date, reference, reason
 * and attachment — over several lines in a warehouse (two for a transfer),
 * each in any of its item's units. Every line becomes ledger rows through
 * StockService, in one transaction with the operation itself — one line
 * that fails takes the whole operation back — and all of them carry the
 * operation's id. A pack line is converted to the main unit (2 boxes of 6
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
     * Null for a count where every line matched the book: nothing is
     * recorded then, not even the operation.
     *
     * @param  array<string, mixed>  $data  validated: warehouse_id (from_warehouse_id + to_warehouse_id for a transfer), lines, and the header — operation_date, reference, note, reason, cost_method, attachment_url
     */
    public function record(OperationKind $kind, array $data): ?StockOperation
    {
        return DB::transaction(function () use ($kind, $data) {
            $from = Warehouse::query()->findOrFail($data['from_warehouse_id'] ?? $data['warehouse_id']);
            $to = $kind === OperationKind::Transfer ? Warehouse::query()->findOrFail($data['to_warehouse_id']) : null;
            $reason = isset($data['reason']) ? IssueReason::from($data['reason']) : null;
            $costMethod = $kind === OperationKind::Receive ? $this->costMethod($data) : null;
            $id = (string) Str::uuid();
            // The reason stays on each ledger row too, so the ledger reads on its own.
            $note = $data['note'] ?? null;

            $rows = $this->run($data['lines'], $id, function (Item $item, array $line, string $factor, array $meta) use ($data, $kind, $from, $to, $reason, $costMethod, $note) {
                $cost = $costMethod === 'average' || ! isset($line['unit_cost']) ? null : Decimal::round(Decimal::div($line['unit_cost'], $factor), 4);

                // Chosen as such (the dashboard always says), "the current
                // average" must have one to give.
                if (($data['cost_method'] ?? null) === 'average') {
                    $this->ensureAverage($item, $from);
                }

                return match ($kind) {
                    OperationKind::Receive => [$this->stock->receive($item, $from->asPlace(), $this->toMain($line['quantity'], $factor), $cost, note: $note, meta: $meta)],
                    OperationKind::Issue, OperationKind::WriteOff => [$this->stock->issue($item, $from->asPlace(), $this->toMain($line['quantity'], $factor), $reason, note: $note, meta: $meta)],
                    OperationKind::Transfer => $this->stock->transfer($item, $from->asPlace(), $to->asPlace(), $this->toMain($line['quantity'], $factor), $note, $meta),
                    OperationKind::Adjust => [$this->stock->adjust($item, $from->asPlace(), $this->toMain($line['counted'], $factor), $cost, $note, $meta)],
                };
            });

            if ($rows->isEmpty()) {
                return null;
            }

            return StockOperation::query()->forceCreate([
                'id' => $id,
                'kind' => $kind,
                'warehouse_id' => $from->id,
                'to_warehouse_id' => $to?->id,
                'operation_date' => $data['operation_date'] ?? Inventory::today()->toDateString(),
                'reference' => $data['reference'] ?? null,
                'reason' => $reason,
                'note' => $data['note'] ?? null,
                'cost_method' => $costMethod,
                'attachment_url' => $data['attachment_url'] ?? null,
                'actor_id' => Inventory::actorId(),
            ]);
        });
    }

    /**
     * A receipt's costing: as chosen, or — left out — entered when any line
     * carries a cost, the warehouse's current average otherwise.
     *
     * @param  array<string, mixed>  $data
     */
    private function costMethod(array $data): string
    {
        if (isset($data['cost_method'])) {
            return $data['cost_method'];
        }

        return collect($data['lines'])->contains(fn (array $line) => isset($line['unit_cost'])) ? 'entered' : 'average';
    }

    /**
     * Receiving "at the current average" needs one: an item never costed in
     * the warehouse would come in at nothing.
     */
    private function ensureAverage(Item $item, Warehouse $at): void
    {
        $average = StockBalance::query()
            ->where('item_id', $item->id)
            ->where('location_type', Warehouse::TYPE)
            ->where('location_id', $at->id)
            ->value('average_cost');

        if ($average === null || Decimal::isZero($average)) {
            InventoryException::fail('inventory::stock.no_average_cost', ['item' => (string) $item->translate('name')]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  Closure(Item, array<string, mixed>, string, array<string, mixed>): array<int, ?StockMovement>  $record
     * @return Collection<int, StockMovement>
     */
    private function run(array $lines, string $operationId, Closure $record): Collection
    {
        $items = Item::query()->with(['unit', 'otherUnits', 'category'])->findMany(array_column($lines, 'item_id'))->keyBy('id');
        usort($lines, fn (array $a, array $b) => (int) $a['item_id'] <=> (int) $b['item_id']);

        $rows = collect();

        foreach ($lines as $line) {
            $item = $items->get((int) $line['item_id']);
            $unitId = isset($line['unit_id']) ? (int) $line['unit_id'] : $item->unit_id;
            $factor = $item->factorFor($unitId);
            $typed = $line['quantity'] ?? $line['counted'];
            $meta = ['operation_id' => $operationId, 'line_note' => $line['note'] ?? null];

            if ($unitId !== $item->unit_id) {
                $meta += ['entered_unit_id' => $unitId, 'entered_quantity' => Decimal::round($typed, 3)];
            }

            $rows->push(...array_filter($record($item, $line, $factor, $meta)));
        }

        return $rows;
    }

    private function toMain(int|float|string $quantity, string $factor): string
    {
        return Decimal::trim(Decimal::mul($quantity, $factor));
    }
}
