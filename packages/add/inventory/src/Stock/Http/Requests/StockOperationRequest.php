<?php

namespace Add\Inventory\Stock\Http\Requests;

use Add\Inventory\Stock\Enums\IssueReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One stock operation — receiving, issuing, transferring or counting —
 * told apart by the route: a warehouse (two for a transfer) and its lines,
 * each an item in one of its units. Whether the item is stock, the unit
 * one of the item's, the warehouse active and open to it, and holding
 * enough is the services' to say.
 */
class StockOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $warehouse = fn (string $field) => [$field => ['required', 'integer', Rule::exists('inv_warehouses', 'id')]];
        $lines = [
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.item_id' => ['required', 'integer', 'distinct', Rule::exists('inv_items', 'id')],
            'lines.*.unit_id' => ['nullable', 'integer', Rule::exists('inv_units', 'id')],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
        $quantity = ['lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999']];
        $unitCost = ['lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999']];

        return match ($this->route()->getName()) {
            'inventory.stock.receive' => [...$warehouse('warehouse_id'), ...$lines, ...$quantity, ...$unitCost],
            'inventory.stock.issue' => [
                ...$warehouse('warehouse_id'),
                ...$lines,
                ...$quantity,
                'reason' => ['required', Rule::in(array_map(fn (IssueReason $reason) => $reason->value, IssueReason::manual()))],
            ],
            'inventory.stock.transfer' => [
                ...$warehouse('from_warehouse_id'),
                ...$warehouse('to_warehouse_id'),
                ...$lines,
                ...$quantity,
            ],
            'inventory.stock.adjust' => [
                ...$warehouse('warehouse_id'),
                ...$lines,
                'lines.*.counted' => ['required', 'numeric', 'min:0', 'max:99999999999'],
                ...$unitCost,
            ],
            default => [],
        };
    }
}
