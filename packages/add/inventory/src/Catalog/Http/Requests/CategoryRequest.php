<?php

namespace Add\Inventory\Catalog\Http\Requests;

use Add\Inventory\Catalog\Enums\ItemKind;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Support\TranslatableField;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A root category names its kind; a child takes its parent's. Whether a
 * move or a kind change is allowed is the controller's (it depends on what
 * the tree already holds).
 */
class CategoryRequest extends FormRequest
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
        /** @var Category|null $category */
        $category = $this->route('category');

        return [
            ...($category === null ? TranslatableField::rules('name') : TranslatableField::sometimes('name')),
            'parent_id' => ['nullable', 'integer', Rule::exists('inv_categories', 'id')],
            'kind' => [
                $category === null ? 'required_without:parent_id' : 'sometimes',
                Rule::enum(ItemKind::class),
            ],
            // An asset category whose assets have no body (needs config
            // inventory.assets.intangible).
            'intangible' => ['sometimes', 'boolean', function (string $attribute, mixed $value, Closure $fail) use ($category) {
                if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                    return;
                }

                if (! config('inventory.assets.intangible')) {
                    $fail(__('inventory::catalog.intangible_off'));

                    return;
                }

                $parent = $this->filled('parent_id') ? Category::query()->find($this->input('parent_id')) : null;
                $kind = $this->input('kind') ?? $parent?->kind->value ?? $category?->kind->value;

                if ($kind !== ItemKind::Asset->value) {
                    $fail(__('inventory::catalog.intangible_assets_only'));
                }
            }],
            'icon' => ['sometimes', 'nullable', 'string', 'max:40'],
            'show_to_members' => ['sometimes', 'boolean'],
            'order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
