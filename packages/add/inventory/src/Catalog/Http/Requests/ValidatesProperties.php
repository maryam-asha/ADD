<?php

namespace Add\Inventory\Catalog\Http\Requests;

use Add\Inventory\Catalog\Models\AttributeDefinition;
use Add\Inventory\Catalog\Models\Category;
use Closure;

/**
 * An item's `properties` answer its category's fields (its own and its
 * ancestors'): each value passes its field's rules, a required field must
 * be answered, and no key outside them is accepted.
 */
trait ValidatesProperties
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function propertyRules(?Category $category): array
    {
        if ($category === null) {
            return ['properties' => ['nullable', 'array']];
        }

        $definitions = $category->effectiveDefinitions();
        $keys = $definitions->pluck('key')->all();

        $rules = [
            'properties' => [
                'nullable',
                'array',
                function (string $attribute, mixed $value, Closure $fail) use ($keys) {
                    $unknown = is_array($value) ? array_diff(array_keys($value), $keys) : [];

                    if ($unknown !== []) {
                        $fail(__('inventory::catalog.unknown_properties', ['keys' => implode(', ', $unknown)]));
                    }
                },
            ],
        ];

        foreach ($definitions as $definition) {
            /** @var AttributeDefinition $definition */
            $rules["properties.{$definition->key}"] = $definition->valueRules();
        }

        return $rules;
    }
}
