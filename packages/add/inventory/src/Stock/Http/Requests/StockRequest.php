<?php

namespace Add\Inventory\Stock\Http\Requests;

use Add\Inventory\Stock\Enums\IssueReason;
use Add\Inventory\Support\Rules\PlaceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Receiving, issuing, transferring and counting stock, told apart by the
 * route. Whether the item is stock and the location holds enough is the
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
        $common = [
            'item_id' => ['required', 'integer', Rule::exists('inv_items', 'id')],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
        $quantity = ['quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999']];
        $unitCost = ['unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999']];

        return match ($this->route()->getName()) {
            'inventory.stock.receive' => [...$common, ...PlaceRules::location(), ...$quantity, ...$unitCost],
            'inventory.stock.issue' => [
                ...$common,
                ...PlaceRules::location(),
                ...$quantity,
                'reason' => ['required', Rule::in(array_map(fn (IssueReason $reason) => $reason->value, IssueReason::manual()))],
            ],
            'inventory.stock.transfer' => [...$common, ...PlaceRules::location('from'), ...PlaceRules::location('to'), ...$quantity],
            'inventory.stock.adjust' => [
                ...$common,
                ...PlaceRules::location(),
                'counted' => ['required', 'numeric', 'min:0', 'max:99999999999'],
                ...$unitCost,
            ],
            default => [],
        };
    }
}
