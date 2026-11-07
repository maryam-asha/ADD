<?php

namespace Add\Inventory\Stock\Http\Requests;

use Add\Inventory\Stock\Enums\IssueReason;
use Add\Inventory\Support\Rules\FileUrl;
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
            'lines.*.note' => ['nullable', 'string', 'max:500'],
            // The header: the day it happened (today when left out, never
            // later), a reference such as a supplier's invoice, why — in
            // words, required but for a transfer — and a scanned document.
            'operation_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:60'],
            'note' => [$this->route()->getName() === 'inventory.stock.transfer' ? 'nullable' : 'required', 'string', 'max:2000'],
            'attachment_url' => ['nullable', ...FileUrl::rules()],
        ];
        $quantity = ['lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999']];
        $unitCost = ['lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999']];

        return match ($this->route()->getName()) {
            'inventory.stock.receive' => [
                ...$warehouse('warehouse_id'),
                ...$lines,
                ...$quantity,
                ...$unitCost,
                // Left out: entered when a line carries a cost, else the average.
                'cost_method' => ['nullable', Rule::in(['average', 'entered'])],
                'lines.*.unit_cost' => ['nullable', 'required_if:cost_method,entered', 'numeric', 'min:0', 'max:9999999999'],
            ],
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
