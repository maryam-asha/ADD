<?php

namespace Add\Inventory\Catalog\Http\Requests;

use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemRequest extends FormRequest
{
    use ValidatesBarcodeAndUnits;
    use ValidatesProperties;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = is_numeric($this->input('category_id')) ? Category::query()->find($this->input('category_id')) : null;

        return [
            ...TranslatableField::rules('name'),
            'category_id' => ['required', 'integer', Rule::exists('inv_categories', 'id')],
            'unit_id' => ['required', 'integer', Rule::exists('inv_units', 'id')],
            // Generated from the id when left out.
            'sku' => ['nullable', 'string', 'max:40', Rule::unique('inv_items', 'sku')],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            ...$this->propertyRules($category),
            ...$this->barcodeAndUnitRules(null, $this->input('unit_id')),
        ];
    }
}
