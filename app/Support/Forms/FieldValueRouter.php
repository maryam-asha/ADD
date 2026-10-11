<?php

namespace App\Support\Forms;

/**
 * Routes a submitted value into the right storage column and reads it back
 * out again. Value tables carry three columns — value_text, value_json,
 * value_file — and which one a field uses is a property of its type, not
 * of the domain that owns it.
 */
class FieldValueRouter
{
    /**
     * @return array{value_text: ?string, value_json: mixed, value_file: ?string}
     */
    public function route(FieldType $type, mixed $value): array
    {
        $columns = ['value_text' => null, 'value_json' => null, 'value_file' => null];

        if ($type->storesFile()) {
            // Already a stored path by this point; the caller moves the
            // upload before handing it over.
            $columns['value_file'] = (string) $value;
        } elseif ($type->storesJson()) {
            $columns['value_json'] = $value;
        } else {
            $columns['value_text'] = (string) $value;
        }

        return $columns;
    }

    /**
     * The stored value in a unified shape, whichever column holds it.
     */
    public function resolve(?string $text, mixed $json, ?string $file): mixed
    {
        return match (true) {
            $file !== null => $file,
            $json !== null => $json,
            default => $text,
        };
    }
}
