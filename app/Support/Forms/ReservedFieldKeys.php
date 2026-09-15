<?php

namespace App\Support\Forms;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\NotIn;

/**
 * Identity keys a custom form may never define.
 *
 * Name, phone and email already exist on every authenticated member and
 * are copied onto the submission from the token. Letting a form redefine
 * them would give two sources for one fact and let a client overwrite the
 * identity the server just established.
 *
 * Enforced on the Events field API today. Services' existing rows predate
 * the rule and are reported by a guard test rather than rejected, so a
 * live configuration can't break on deploy.
 */
final class ReservedFieldKeys
{
    /** @var list<string> */
    public const KEYS = ['name', 'phone', 'email'];

    public static function isReserved(string $key): bool
    {
        return in_array(strtolower(trim($key)), self::KEYS, true);
    }

    /**
     * Any reserved key among the submitted field keys.
     *
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    public static function present(array $fields): array
    {
        return array_values(array_filter(
            array_keys($fields),
            static fn ($key) => is_string($key) && self::isReserved($key),
        ));
    }

    public static function rule(): NotIn
    {
        // Case variants too: the column is compared case-sensitively, but
        // "Name" is the same field to a reader.
        $variants = [];

        foreach (self::KEYS as $key) {
            $variants[] = $key;
            $variants[] = ucfirst($key);
            $variants[] = strtoupper($key);
        }

        return Rule::notIn($variants);
    }
}
