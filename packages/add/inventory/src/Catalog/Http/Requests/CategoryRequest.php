<?php

namespace Add\Inventory\Catalog\Http\Requests;

use Add\Inventory\Catalog\Enums\ItemKind;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Support\TranslatableField;
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
            'icon' => ['sometimes', 'nullable', 'string', 'max:40'],
            'show_to_members' => ['sometimes', 'boolean'],
            'order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
