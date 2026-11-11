<?php

namespace Add\Inventory\Warehouses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The minimums a warehouse is set to: one line per item, where 0 removes
 * the item's minimum.
 */
class WarehouseMinimumsRequest extends FormRequest
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
        return [
            'items' => ['required', 'array'],
            'items.*.item_id' => ['required', 'integer', 'distinct', 'exists:inv_items,id'],
            'items.*.minimum' => ['required', 'numeric', 'min:0', 'max:99999999999'],
        ];
    }
}
