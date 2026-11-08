<?php

namespace Add\Inventory\Catalog\Enums;

/**
 * Who sets an item's code (config inventory.items.sku_mode). A host with a
 * coding scheme of its own types codes in; one without lets the package
 * number them (prefix + id).
 */
enum SkuMode: string
{
    /** The package numbers every item; a typed code is refused. */
    case Auto = 'auto';

    /** Typed when the item is made (numbered when left out), fixed after. */
    case OnCreate = 'on_create';

    /** Typed when made and changed at any time. */
    case Editable = 'editable';

    /**
     * The configured mode; an unknown value reads as the default.
     */
    public static function current(): self
    {
        return self::tryFrom((string) config('inventory.items.sku_mode')) ?? self::OnCreate;
    }

    public function typedOnCreate(): bool
    {
        return $this !== self::Auto;
    }

    public function typedOnUpdate(): bool
    {
        return $this === self::Editable;
    }
}
