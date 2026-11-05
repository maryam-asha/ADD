<?php

namespace Add\Inventory\Stock\Http\Requests;

use Add\Inventory\Stock\Enums\IssueReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Receiving, issuing, transferring and counting stock in a warehouse, told
 * apart by the route. Whether the item is stock, the warehouse active and
 * its scope open to the item, and the warehouse holds enough is the
 * service's to say.
 */
class StockRequest extends FormRequest
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
        $common = [
            'item_id' => ['required', 'integer', Rule::exists('inv_items', 'id')],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
        $quantity = ['quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999']];
        $unitCost = ['unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999']];

        return match ($this->route()->getName()) {
            'inventory.stock.receive' => [...$common, ...$warehouse('warehouse_id'), ...$quantity, ...$unitCost],
            'inventory.stock.issue' => [
                ...$common,
                ...$warehouse('warehouse_id'),
                ...$quantity,
                'reason' => ['required', Rule::in(array_map(fn (IssueReason $reason) => $reason->value, IssueReason::manual()))],
            ],
            'inventory.stock.transfer' => [...$common, ...$warehouse('from_warehouse_id'), ...$warehouse('to_warehouse_id'), ...$quantity],
            'inventory.stock.adjust' => [
                ...$common,
                ...$warehouse('warehouse_id'),
                'counted' => ['required', 'numeric', 'min:0', 'max:99999999999'],
                ...$unitCost,
            ],
            default => [],
        };
    }
}
