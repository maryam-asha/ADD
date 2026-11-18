<?php

namespace Add\Inventory\Catalog\Http\Requests;

use Add\Inventory\Catalog\Enums\AttributeType;
use Add\Inventory\Catalog\Models\AttributeDefinition;
use Add\Inventory\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A field's key and type are fixed once created: items already store
 * answers under that key, in that shape. A select keeps at least one
 * option.
 */
class AttributeDefinitionRequest extends FormRequest
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
        /** @var AttributeDefinition|null $definition */
        $definition = $this->route('attributeDefinition');
        $creating = $definition === null;
        $type = $creating ? AttributeType::tryFrom((string) $this->input('type')) : $definition->type;

        return [
            ...($creating ? TranslatableField::rules('label') : TranslatableField::sometimes('label')),
            ...($creating ? [
                'key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
                'type' => ['required', Rule::enum(AttributeType::class)],
            ] : []),
            'options' => [
                $type === AttributeType::Select && $creating ? 'required' : 'sometimes',
                ...($type === AttributeType::Select ? ['array', 'min:1'] : ['prohibited']),
            ],
            'options.*' => ['string', 'max:100', 'distinct'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'is_required' => ['sometimes', 'boolean'],
            'order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
