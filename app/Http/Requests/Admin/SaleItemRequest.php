<?php

namespace App\Http\Requests\Admin;

use Add\Inventory\Catalog\Models\Item;
use App\Domain\Retail\Models\SaleItem;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A stock item put on sale at reception, at a price in points. Only stock
 * can be sold: selling issues it from the shelf.
 */
class SaleItemRequest extends FormRequest
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
        /** @var SaleItem|null $saleItem */
        $saleItem = $this->route('saleItem');
        $presence = $saleItem === null ? 'required' : 'sometimes';

        return [
            'inventory_item_id' => [
                $presence,
                'integer',
                Rule::unique('sale_items', 'inventory_item_id')->ignore($saleItem),
                function (string $attribute, mixed $value, Closure $fail) {
                    $item = Item::query()->with('category')->find($value);

                    if ($item === null || ! $item->isStock()) {
                        $fail(__('api.item_sale.not_stock'));
                    }
                },
            ],
            'price_points' => [$presence, 'integer', 'min:1', 'max:10000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
