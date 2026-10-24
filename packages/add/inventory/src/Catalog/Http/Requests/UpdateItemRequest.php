<?php

namespace Add\Inventory\Catalog\Http\Requests;

use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `properties`, when sent, replace the stored ones whole. Moving the item
 * to another category re-checks its properties against that category's
 * fields — the stored ones when no new ones are sent.
 */
class UpdateItemRequest extends FormRequest
{
    use ValidatesProperties;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        /** @var Item $item */
        $item = $this->route('item');

        if ($this->has('category_id') && ! $this->has('properties')) {
            $this->merge(['properties' => $item->properties ?? []]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Item $item */
        $item = $this->route('item');
        $category = $this->has('category_id')
            ? (is_numeric($this->input('category_id')) ? Category::query()->find($this->input('category_id')) : null)
            : $item->category;

        return [
            ...TranslatableField::sometimes('name'),
            'category_id' => ['sometimes', 'integer', Rule::exists('inv_categories', 'id')],
            'unit_id' => ['sometimes', 'integer', Rule::exists('inv_units', 'id')],
            'sku' => ['sometimes', 'string', 'max:40', Rule::unique('inv_items', 'sku')->ignore($item)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            ...($this->has('properties') ? $this->propertyRules($category) : []),
        ];
    }
}
