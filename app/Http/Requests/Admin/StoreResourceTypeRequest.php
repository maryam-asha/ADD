<?php

namespace App\Http\Requests\Admin;

use Add\Inventory\Catalog\Models\Item;
use App\Http\Requests\Concerns\DropsNullDefaults;
use App\Support\TranslatableField;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreResourceTypeRequest extends FormRequest
{
    use DropsNullDefaults;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->dropNullDefaults(['order', 'is_active']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(
            TranslatableField::rules('name'),
            TranslatableField::rules('unit_label'),
            [
                'slug' => ['nullable', 'string', 'max:255', Rule::unique('resource_types', 'slug')],
                'icon' => ['nullable', 'string', 'max:100'],
                'order' => ['nullable', 'integer', 'min:0'],
                'inventory_item_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                    if ($value !== null && ! Item::query()->with('category')->find($value)?->isStock()) {
                        $fail(__('api.item_sale.not_stock'));
                    }
                }],
                'stock_units_per_unit' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
                'is_active' => ['nullable', 'boolean'],
            ]
        );
    }
}
