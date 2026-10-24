<?php

namespace Add\Inventory\Support\Rules;

use Add\Inventory\Contracts\CustodianResolver;
use Add\Inventory\Contracts\LocationResolver;
use Add\Inventory\Inventory;
use Add\Inventory\Support\Place;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The request shape of a place (`{"type": "space", "id": 12}`): a type the
 * host's resolver knows, and a record it says exists.
 */
final class PlaceRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function location(string $field = 'location', bool $required = true): array
    {
        return self::rules($field, $required, Inventory::locations(), 'inventory::catalog.unknown_location');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function custodian(string $field = 'custodian', bool $required = true): array
    {
        return self::rules($field, $required, Inventory::custodians(), 'inventory::assets.unknown_custodian');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function rules(string $field, bool $required, LocationResolver|CustodianResolver $resolver, string $message): array
    {
        $exists = function (string $attribute, mixed $value, Closure $fail) use ($resolver, $message) {
            if (! is_array($value) || ! is_string($value['type'] ?? null) || ! is_numeric($value['id'] ?? null)) {
                return;
            }

            if (in_array($value['type'], $resolver->types(), true) && ! $resolver->exists(Place::from($value))) {
                $fail(__($message));
            }
        };

        return [
            $field => [$required ? 'required' : 'nullable', 'array', $exists],
            "{$field}.type" => ["required_with:{$field}", 'string', Rule::in($resolver->types())],
            "{$field}.id" => ["required_with:{$field}", 'integer', 'min:1'],
        ];
    }
}
