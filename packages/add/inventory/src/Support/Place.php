<?php

namespace Add\Inventory\Support;

/**
 * Where something is, or who holds it: a type the host's resolver knows
 * ("space", "branch", "hr_employee", ...) and that record's id. Stored as
 * two plain columns, never as an Eloquent morph, so the package needs no
 * model of the host's.
 */
final class Place
{
    public function __construct(
        public readonly string $type,
        public readonly int $id,
    ) {}

    /**
     * @param  array{type: string, id: int|string}  $value
     */
    public static function from(array $value): self
    {
        return new self((string) $value['type'], (int) $value['id']);
    }

    public function key(): string
    {
        return $this->type.':'.$this->id;
    }

    public function is(self $other): bool
    {
        return $this->key() === $other->key();
    }

    /**
     * @return array{type: string, id: int}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }
}
