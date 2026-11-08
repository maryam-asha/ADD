<?php

namespace Add\Inventory\Settings;

use Closure;
use Illuminate\Validation\Rule;

/**
 * One setting the dashboard may change: its config path under `inventory.`,
 * the page group it shows in, its type (string, integer, boolean, enum,
 * list), its validation rules, the choices of an enum or a list, and when
 * it is locked — a closure given the current and the new value that
 * answers the lang key of the reason, or null when the change may go.
 */
final class SettingDefinition
{
    /**
     * @param  list<mixed>  $rules
     * @param  list<string>  $options
     * @param  (Closure(mixed, mixed): ?string)|null  $lockedWhen
     */
    public function __construct(
        public readonly string $key,
        public readonly string $group,
        public readonly string $type,
        public readonly array $rules,
        public readonly array $options = [],
        public readonly ?Closure $lockedWhen = null,
    ) {}

    /**
     * The value as the setting holds it: a number from a form arrives as a
     * string, a switch as "1".
     */
    public function cast(mixed $value): mixed
    {
        return match ($this->type) {
            'integer' => is_numeric($value) ? (int) $value : $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value,
            'list' => is_array($value) ? array_values($value) : $value,
            default => $value,
        };
    }

    /**
     * The rules for a validator over `['value' => …]`: an enum's value and
     * each of a list's values must be one of its options.
     *
     * @return array<string, list<mixed>>
     */
    public function validationRules(): array
    {
        return match ($this->type) {
            'enum' => ['value' => [...$this->rules, Rule::in($this->options)]],
            'list' => ['value' => $this->rules, 'value.*' => ['string', 'distinct', Rule::in($this->options)]],
            default => ['value' => $this->rules],
        };
    }

    /**
     * The lang key of why `$next` may not replace `$current`, or null.
     */
    public function lockReason(mixed $current, mixed $next): ?string
    {
        return $this->lockedWhen === null ? null : ($this->lockedWhen)($current, $next);
    }
}
